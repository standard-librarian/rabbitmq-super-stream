#!/bin/sh
set -eu

CONTAINER_NAME=${RABBITMQ_CONTAINER_NAME:-rabbitmq}
COMPOSE_FILE=${COMPOSE_FILE:-docker/compose.yml}

if ! docker compose -f "$COMPOSE_FILE" ps rabbitmq >/dev/null 2>&1; then
  echo "rabbitmq service is not running" >&2
  exit 1
fi

ATTEMPTS=0
until docker compose -f "$COMPOSE_FILE" exec -T rabbitmq rabbitmq-diagnostics ping >/dev/null 2>&1
do
  ATTEMPTS=$((ATTEMPTS + 1))
  if [ "$ATTEMPTS" -gt 30 ]; then
    echo "rabbitmq did not become ready in time" >&2
    exit 1
  fi
  sleep 2
done

docker compose -f "$COMPOSE_FILE" exec -T rabbitmq rabbitmq-plugins enable rabbitmq_stream rabbitmq_stream_management rabbitmq_amqp1_0

docker compose -f "$COMPOSE_FILE" restart rabbitmq >/dev/null

ATTEMPTS=0
until docker compose -f "$COMPOSE_FILE" exec -T rabbitmq rabbitmq-diagnostics ping >/dev/null 2>&1
do
  ATTEMPTS=$((ATTEMPTS + 1))
  if [ "$ATTEMPTS" -gt 30 ]; then
    echo "rabbitmq did not come back after plugin restart" >&2
    exit 1
  fi
  sleep 2
done
