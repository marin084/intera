#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
ENV_FILE="$ROOT_DIR/loadtest.env"
RESULT_DIR="$ROOT_DIR/.loadtest-results"
COMPOSE_FILE="$ROOT_DIR/docker-compose.loadtest.yml"
PROJECT="conexa-loadtest"

compose() {
  docker compose --project-name "$PROJECT" --env-file "$ENV_FILE" --file "$COMPOSE_FILE" "$@"
}

run_php_profile() {
  profile="$1"
  output_file="$2"
  set +e
  load_profile="${3:-ramp}"
  compose exec -T backend sh -lc 'cat > /tmp/conexa-webchat-load.php && BASE_URL=http://127.0.0.1:8000 ORIGIN=http://loadtest.local WEBCHAT_PUBLIC_KEY=wc_loadtest_only_not_for_production LOADTEST_SESSION_COUNT=250 php -d max_execution_time=0 /tmp/conexa-webchat-load.php "$1" "$2"' sh "$profile" "$load_profile" \
    < "$ROOT_DIR/load-tests/webchat.php" > "$output_file"
  result=$?
  set -e
  if [ -s "$output_file" ]; then
    cat "$output_file"
  fi
  return "$result"
}

usage() {
  cat <<'EOF'
Isolated Conexa Webchat load test

Usage: scripts/loadtest-webchat.sh <command>
  init     Create local synthetic credentials (never production credentials).
  prepare  Build/start the isolated stack, migrate its database, and seed the fixture.
  smoke    Verify the local Webchat API and its bot reply with one session.
  run      Run an isolated Webchat load profile (default: ramp).
             ramp: gradual increase to 100 messages/second, then sustained plateau.
             sweep: 45-second plateaus at 10, 25, 50, 75, and 100 messages/second.
             capacity: 45-second plateaus at 25, 30, 35, and 40 messages/second.
  status   Show only the conexa-loadtest Compose project.
  logs     Tail logs from that isolated project.
  down     Stop the isolated stack while preserving its test database volume.
  destroy  Delete only the isolated project's containers and volumes; requires
           CONFIRM_LOADTEST_DATA_DELETION=1.
EOF
}

command="${1:-help}"

case "$command" in
  init)
    if [ -f "$ENV_FILE" ]; then
      echo "loadtest.env already exists; leaving it unchanged."
      exit 0
    fi
    app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
    db_password="$(openssl rand -hex 24)"
    cat > "$ENV_FILE" <<EOF
APP_NAME=Conexa Load Test
APP_ENV=loadtest
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=http://localhost:18080
LOG_CHANNEL=stderr
LOG_LEVEL=warning
LOADTEST_DB_PASSWORD=$db_password
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=conexa_loadtest
DB_USERNAME=conexa_loadtest
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=
CACHE_STORE=redis
CACHE_PREFIX=conexa_loadtest_
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=conexa-loadtest
REVERB_APP_KEY=conexa-loadtest-key
REVERB_APP_SECRET=conexa-loadtest-secret
REVERB_HOST=reverb
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
WEBCHAT_RATE_LIMIT_SESSION=5000
WEBCHAT_RATE_LIMIT_MESSAGES=5000
WEBCHAT_RATE_LIMIT_EMBED=5000
PHP_FPM_MAX_CHILDREN=12
PHP_FPM_START_SERVERS=6
PHP_FPM_MIN_SPARE_SERVERS=3
PHP_FPM_MAX_SPARE_SERVERS=6
EOF
    chmod 600 "$ENV_FILE"
    mkdir -p "$RESULT_DIR"
    echo "Created local synthetic credentials in loadtest.env (mode 600)."
    ;;
  prepare)
    [ -f "$ENV_FILE" ] || { echo "Run scripts/loadtest-webchat.sh init first." >&2; exit 2; }
    mkdir -p "$RESULT_DIR"
    compose up -d --build postgres redis reverb backend worker
    attempts=0
    until compose exec -T backend wget -qO- http://127.0.0.1:8000/api/health/ready >/dev/null 2>&1; do
      attempts=$((attempts + 1))
      if [ "$attempts" -ge 90 ]; then
        echo "Load-test backend did not become healthy; inspect its isolated logs." >&2
        compose ps
        exit 1
      fi
      sleep 2
    done
    compose exec -T backend php artisan loadtest:webchat:setup
    ;;
  smoke)
    [ -f "$ENV_FILE" ] || { echo "Run scripts/loadtest-webchat.sh init first." >&2; exit 2; }
    mkdir -p "$RESULT_DIR"
    run_php_profile smoke "$RESULT_DIR/smoke-summary.json" ramp
    ;;
  run)
    [ -f "$ENV_FILE" ] || { echo "Run scripts/loadtest-webchat.sh init first." >&2; exit 2; }
    compose exec -T backend wget -qO- http://127.0.0.1:8000/api/health/ready >/dev/null
    mkdir -p "$RESULT_DIR"
    stats_file="$RESULT_DIR/docker-stats.csv"
    printf 'timestamp_utc,container,cpu_percent,memory_usage,memory_limit\n' > "$stats_file"
    (
      while :; do
        timestamp="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
        ids="$(docker ps -q --filter "label=com.docker.compose.project=$PROJECT")"
        if [ -n "$ids" ]; then
          docker stats --no-stream --format '{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}' $ids | while IFS= read -r row; do
            container="${row%%|*}"
            remaining="${row#*|}"
            cpu_percent="${remaining%%|*}"
            memory="${remaining#*|}"
            memory_usage="${memory% / *}"
            memory_limit="${memory#* / }"
            printf '%s,%s,%s,%s,%s\n' "$timestamp" "$container" "$cpu_percent" "$memory_usage" "$memory_limit" >> "$stats_file"
          done
        fi
        sleep 5
      done
    ) &
    sampler_pid=$!
    set +e
    load_profile="${2:-ramp}"
    case "$load_profile" in
      ramp|sweep|capacity) ;;
      *) echo "Load profile must be ramp, sweep, or capacity." >&2; exit 2 ;;
    esac
    run_php_profile load "$RESULT_DIR/summary.json" "$load_profile"
    result=$?
    set -e
    kill "$sampler_pid" 2>/dev/null || true
    wait "$sampler_pid" 2>/dev/null || true
    echo "Load summary: $RESULT_DIR/summary.json"
    echo "Container samples: $stats_file"
    exit "$result"
    ;;
  status)
    [ -f "$ENV_FILE" ] || { echo "Run scripts/loadtest-webchat.sh init first." >&2; exit 2; }
    compose ps
    ;;
  logs)
    [ -f "$ENV_FILE" ] || { echo "Run scripts/loadtest-webchat.sh init first." >&2; exit 2; }
    compose logs --tail=200 -f "${2:-backend}"
    ;;
  down)
    [ -f "$ENV_FILE" ] || { echo "Run scripts/loadtest-webchat.sh init first." >&2; exit 2; }
    compose down --remove-orphans
    ;;
  destroy)
    [ "${CONFIRM_LOADTEST_DATA_DELETION:-}" = 1 ] || {
      echo "Set CONFIRM_LOADTEST_DATA_DELETION=1 to delete only the isolated load-test data." >&2
      exit 2
    }
    [ -f "$ENV_FILE" ] || { echo "loadtest.env is missing; no test volumes were targeted." >&2; exit 2; }
    compose down --volumes --remove-orphans
    rm -f "$ENV_FILE"
    rm -rf "$RESULT_DIR"
    echo "Deleted only the conexa-loadtest project, its volumes, credentials, and local report files."
    ;;
  help|-h|--help)
    usage
    ;;
  *)
    usage >&2
    exit 2
    ;;
esac
