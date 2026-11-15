# Conexa — Production Deployment Guide

Esta guía documenta los requisitos de infraestructura, topología de red, configuración TLS/HTTPS/WSS, seguridad de cookies y despliegue productivo de Conexa.

---

## 1. Topología de Red y Arquitectura Productiva

En una infraestructura productiva real de cliente, el stack se ubica detrás de un Reverse Proxy / Terminador TLS corporativo (como Nginx, Cloudflare, Traefik o AWS ALB).

```mermaid
flowchart TD
    Client["Navegador / Visitante Webchat"] -->|HTTPS (443) / WSS| ReverseProxy["Reverse Proxy TLS (Host / Edge)"]
    
    subgraph DockerHost["Docker Network: Internal"]
        ReverseProxy -->|HTTP (3000)| FrontendGateway["frontend: Nginx Gateway (puerto 80)"]
        
        FrontendGateway -->|Servir SPA & Assets| FrontendStatic["Vite React SPA / Webchat Widget"]
        FrontendGateway -->|Proxy HTTP /api /sanctum /health| BackendNginx["backend: Nginx (puerto 8000)"]
        FrontendGateway -->|Proxy WebSocket /app/| ReverbWS["reverb: WebSocket Server (puerto 8080)"]
        
        BackendNginx -->|FastCGI (127.0.0.1:9000 internal)| PHPFPM["PHP-FPM Worker Pool"]
        
        PHPFPM -->|SQL (5432)| Postgres[("postgres: PostgreSQL 16")]
        PHPFPM -->|TCP (6379)| Redis[("redis: Redis 7")]
        
        Scheduler["scheduler: schedule:work"] --> Postgres
        Scheduler --> Redis
    end
```

### Reglas de Exposición de Puertos:
- **Públicos a Internet:** Únicamente puertos `80` (HTTP) y `443` (HTTPS) a través del Reverse Proxy corporativo.
- **Internos al Host:** Puerto `3000` (mapeado a `frontend:80`).
- **Completamente Aislados (Red Docker):**
  - PHP-FPM (`127.0.0.1:9000` solo dentro de `backend`). **Jamás publicado**.
  - Reverb (`8080`).
  - PostgreSQL (`5432`).
  - Redis (`6379`).

---

## 2. Configuración del Reverse Proxy TLS (Nginx de Host)

El Reverse Proxy externo debe terminar TLS y reenviar el tráfico HTTP y WebSocket hacia el contenedor `frontend` (puerto `3000`).

### Ejemplo de Configuración Nginx (`/etc/nginx/sites-available/chat.cliente.com`):

```nginx
# Redirección obligatoria HTTP a HTTPS
server {
    listen 80;
    server_name chat.cliente.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name chat.cliente.com;

    ssl_certificate /etc/letsencrypt/live/chat.cliente.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/chat.cliente.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    client_max_body_size 20M;

    # Gateway Frontend SPA, API y Health
    location / {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;

        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-Port 443;
    }

    # Proxy WebSocket Reverb (/app/)
    location /app/ {
        proxy_pass http://127.0.0.1:3000/app/;
        proxy_http_version 1.1;

        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;

        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }
}
```

---

## 3. Configuración Obligatoria de `.env` para Producción

### A. Seguridad de Sesiones y Cookies
Detrás de HTTPS, configure:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://chat.cliente.com
FRONTEND_URL=https://chat.cliente.com

# Forzar flag Secure en cookies de sesión
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=
SANCTUM_STATEFUL_DOMAINS=chat.cliente.com
```

### B. Trusted Proxies
Para evitar spoofing de direcciones IP en headers `X-Forwarded-For`, declare los rangos CIDR de sus proxies:
```env
TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
```

### C. Laravel Reverb en Producción
```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=conexa_prod
REVERB_APP_KEY=conexa_pub_key
REVERB_APP_SECRET=clave_secreta_altamente_segura_de_reverb
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http
```
> [!WARNING]
> Nunca exponga `REVERB_APP_SECRET` al frontend. El frontend solo utiliza `REVERB_APP_KEY`, conectando vía `wss://chat.cliente.com/app/`.

### D. CORS y Orígenes Permitidos
Conexa distingue estrictamente:
- **Origen de la Aplicación Administrativa:** Configurado en `FRONTEND_URL`. Solo orígenes específicos autorizados pueden autenticarse con cookies (`supports_credentials=true`). **Nunca se utiliza wildcard `*`**.
- **Orígenes de Sitios de Clientes para Webchat:** Se configuran individualmente en la base de datos por cada Canal (`Channel.settings['allowed_origins']`), verificados durante el handshake del visitante.

---

## 4. Requisitos de Hardware y Metodología de Benchmark

> [!NOTE]
> **Production sizing must be benchmarked for the expected workload.**
> No se deben fijar arbitrariamente límites de recursos sin una medición empírica del caso de uso concreto.

### Metodología Recomendada para el Benchmark de Capacidad:
Para determinar el dimensionamiento final en la infraestructura del cliente:
1. **Métricas a Monitorear:**
   - Agentes concurrentes en el panel de Inbox.
   - Conversaciones activas por minuto.
   - Mensajes por segundo (inbound contact + outbound bot/agente).
   - Conexiones WebSocket concurrentes en Reverb.
   - Latencia de respuesta de API (p50 y p95).
   - Uso de CPU y memoria en `backend`, `reverb`, `postgres` y `redis`.
2. **Herramienta de Carga:** Se recomienda ejecutar pruebas con `k6` o `artillery` simulando:
   - 10 agentes navegando y respondiendo.
   - De 50 a 500 visitantes concurrentes enviando mensajes en Webchat.
3. **Requisitos Mínimos Empíricos de Inicialización (Baseline Docker):**
   - 2 vCPU.
   - 4 GB de RAM.
   - 20 GB de almacenamiento SSD (PostgreSQL volumen y backups).
   *(Dimensionamiento base para arrancar contenedores; ajustar según resultados del benchmark)*.
