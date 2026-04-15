#!/bin/sh
set -eu

SERVICE_NAME=${RABBITMQ_SERVICE_NAME:-rabbitmq}
COMPOSE_FILE=${COMPOSE_FILE:-docker/compose.yml}
WAIT_ATTEMPTS=${RABBITMQ_WAIT_ATTEMPTS:-90}
WAIT_INTERVAL_SECONDS=${RABBITMQ_WAIT_INTERVAL_SECONDS:-2}

compose() {
  docker compose -f "$COMPOSE_FILE" "$@"
}

dump_diagnostics() {
  compose ps "$SERVICE_NAME" >&2 || true
  compose logs --tail=200 "$SERVICE_NAME" >&2 || true
}

wait_for_rabbitmq() {
  phase=$1
  attempts=0

  until compose exec -T "$SERVICE_NAME" rabbitmq-diagnostics -q ping >/dev/null 2>&1
  do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge "$WAIT_ATTEMPTS" ]; then
      echo "rabbitmq did not become ready in time during $phase" >&2
      dump_diagnostics
      exit 1
    fi
    sleep "$WAIT_INTERVAL_SECONDS"
  done
}

if ! compose ps --status running --services | grep -qx "$SERVICE_NAME"; then
  echo "rabbitmq service is not running" >&2
  dump_diagnostics
  exit 1
fi

wait_for_rabbitmq "initial startup"

compose exec -T "$SERVICE_NAME" rabbitmq-plugins enable rabbitmq_stream rabbitmq_stream_management rabbitmq_amqp1_0

compose restart "$SERVICE_NAME" >/dev/null

wait_for_rabbitmq "plugin restart"
