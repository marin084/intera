# Guía de interfaz de Intera

## Páginas nuevas del espacio de trabajo

- Usa la página de Flujos Conversacionales como referencia visual para las páginas nuevas.
- Conserva el layout compartido de la aplicación y coloca el contenido principal en una sola tarjeta de ancho disponible.
- Usa las clases compartidas `card`, `card-header` y `card-title`, junto con los patrones existentes para tablas y botones.
- No anides otro `content-body` ni agregues márgenes de página que reduzcan el ancho respecto a Flujos.
- En móvil, presenta las tablas con el patrón adaptable existente y agrega `data-mobile-label` a cada celda para identificar su contenido.
- Mantén las columnas, etiquetas y acciones legibles; permite el desplazamiento horizontal cuando una tabla necesite más espacio.

Antes de crear una variante visual, comprueba si el sistema ya tiene un componente o patrón reutilizable para esa necesidad.
