#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# Intera — Production Installation & Bootstrap Script
# ==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${ROOT_DIR}"

echo "====================================================================="
echo "Intera — Production Installer"
echo "====================================================================="

# 1. Check prerequisites
echo "1. Checking prerequisites..."
if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: 'docker' command not found. Please install Docker first." >&2
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' plugin not found. Please install Docker Compose v2." >&2
  exit 1
fi
echo "Docker and Docker Compose are installed and operational."

# 2. Prepare .env file
echo "2. Configuring environment (.env)..."
if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
  else
    echo "ERROR: Neither .env nor .env.example exists." >&2
    exit 1
  fi
else
  echo ".env file already exists. Existing settings preserved."
fi

# 3. Ensure APP_KEY exists and is never overwritten if already present
CURRENT_KEY=$(grep -E "^APP_KEY=" .env | cut -d '=' -f2- || true)
if [ -z "${CURRENT_KEY}" ]; then
  echo "Generating secure, persistent APP_KEY..."
  NEW_KEY="base64:$(openssl rand -base64 32)"
  # Replace empty APP_KEY= with generated key
  if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" .env
  else
    sed -i "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" .env
  fi
  echo "APP_KEY successfully generated."
else
  echo "Existing APP_KEY detected: preserved intact."
fi

# 4. Generate random passwords for defaults if placeholders are still present
if grep -q "replace_with_strong_database_password" .env; then
  RANDOM_DB_PASS=$(openssl rand -hex 16)
  if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "s|replace_with_strong_database_password|${RANDOM_DB_PASS}|" .env
  else
    sed -i "s|replace_with_strong_database_password|${RANDOM_DB_PASS}|" .env
  fi
  echo "Generated random strong database password."
fi

if grep -q "replace_with_strong_reverb_secret" .env; then
  RANDOM_REVERB_SECRET=$(openssl rand -hex 24)
  if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "s|replace_with_strong_reverb_secret|${RANDOM_REVERB_SECRET}|" .env
  else
    sed -i "s|replace_with_strong_reverb_secret|${RANDOM_REVERB_SECRET}|" .env
  fi
  echo "Generated random strong Reverb secret."
fi

# 5. Domain / URL configuration (ensures definitive settings before container boot)
CURRENT_APP_URL=$(grep -E "^APP_URL=" .env | cut -d '=' -f2- || true)
if [ -n "${OVERRIDE_APP_URL:-}" ]; then
  CONFIG_APP_URL="${OVERRIDE_APP_URL}"
elif [ -t 0 ] && { [ -z "${CURRENT_APP_URL}" ] || [ "${CURRENT_APP_URL}" = "http://localhost:8000" ]; }; then
  read -r -p "Enter public URL for Intera [default: ${CURRENT_APP_URL:-http://localhost:8100}]: " USER_URL || true
  CONFIG_APP_URL="${USER_URL:-$CURRENT_APP_URL}"
else
  CONFIG_APP_URL="${CURRENT_APP_URL:-http://localhost:8000}"
fi

if [ -n "${CONFIG_APP_URL}" ]; then
  if [[ "$OSTYPE" == "darwin"* ]]; then
    sed -i '' "s|^APP_URL=.*|APP_URL=${CONFIG_APP_URL}|" .env
  else
    sed -i "s|^APP_URL=.*|APP_URL=${CONFIG_APP_URL}|" .env
  fi
  echo "Configured APP_URL=${CONFIG_APP_URL}"

  if [[ "${CONFIG_APP_URL}" == https://* ]]; then
    if grep -q "^SESSION_SECURE_COOKIE=" .env; then
      if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" .env
      else
        sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" .env
      fi
    fi
  else
    echo "WARNING: APP_URL is not HTTPS (${CONFIG_APP_URL}). Meta Graph API requires public HTTPS for media/attachment downloads."
  fi
fi

# 6. Build and launch Docker containers
echo "3. Building container images..."
docker compose build

echo "4. Starting Docker services..."
docker compose up -d

# 7. Wait for backend to be healthy
echo "5. Waiting for backend service to become ready..."
MAX_WAIT=30
WAITED=0
BACKEND_READY=0

until [ "${WAITED}" -ge "${MAX_WAIT}" ]; do
  if docker compose exec -T backend curl -sf http://127.0.0.1:8000/health >/dev/null 2>&1; then
    BACKEND_READY=1
    break
  fi
  sleep 2
  WAITED=$((WAITED+2))
done

if [ "${BACKEND_READY}" -ne 1 ]; then
  echo "ERROR: Backend did not respond to health check in time." >&2
  exit 1
fi
echo "Backend is responsive."

# 8. Apply migrations and seed baseline roles
echo "6. Running database migrations and role seeding..."
docker compose exec -T backend php artisan migrate --force --no-interaction
docker compose exec -T backend php artisan db:seed --force --no-interaction

# 9. Check and initialize Super Admin
echo "7. Checking administrative user..."
ADMIN_COUNT=$(docker compose exec -T backend php -r '
  require "vendor/autoload.php";
  $app = require_once "bootstrap/app.php";
  $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
  $kernel->bootstrap();
  echo \App\Models\User::whereHas("roles", fn($q) => $q->whereIn("name", ["super_admin", "admin"]))->count();
' 2>/dev/null || echo "0")

if [ "${ADMIN_COUNT}" -eq 0 ]; then
  echo "No administrator account detected."
  if [ -t 0 ]; then
    echo "Creating initial administrator interactively..."
    docker compose exec backend php artisan conexa:init-admin
  else
    echo "Non-interactive environment detected. If INITIAL_ADMIN_* are set in .env, running init-admin:"
    docker compose exec -T backend php artisan conexa:init-admin --force --no-interaction || {
      echo "Notice: Set INITIAL_ADMIN_EMAIL and INITIAL_ADMIN_PASSWORD in .env or run:"
      echo "  docker compose exec -it backend php artisan conexa:init-admin"
    }
  fi
else
  echo "Administrator account exists. Preserving existing accounts."
fi

# 10. Refresh and compile definitive production configuration cache
echo "8. Refreshing and compiling definitive configuration cache..."
docker compose exec -T backend php artisan config:clear --no-interaction
docker compose exec -T backend php artisan config:cache --no-interaction
docker compose exec -T backend php artisan route:cache --no-interaction
docker compose exec -T backend php artisan view:cache --no-interaction

# 11. Verify overall health
echo "9. Performing post-installation health verification..."
HEALTH_CHECK=$(docker compose exec -T backend curl -s http://127.0.0.1:8000/health/ready)
echo "Ready Health Status: ${HEALTH_CHECK}"

echo "====================================================================="
echo "Intera installation completed successfully!"
echo "Access points:"
echo "  Frontend Web UI:   http://localhost:3000 (or configured FRONTEND_PORT)"
echo "  Backend API:       http://localhost:8000/api/v1"
echo "  Health Endpoint:   http://localhost:3000/health (or :8000/health)"
echo "====================================================================="
exit 0
