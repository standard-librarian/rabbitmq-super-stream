#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
GO_DIR="$ROOT_DIR/go"
GOOS_VALUE=$(cd "$GO_DIR" && go env GOOS)
GOARCH_VALUE=$(cd "$GO_DIR" && go env GOARCH)

case "$GOOS_VALUE" in
  linux|darwin) ;;
  *)
    echo "unsupported GOOS: $GOOS_VALUE" >&2
    exit 1
    ;;
esac

case "$GOARCH_VALUE" in
  amd64|arm64) ;;
  *)
    echo "unsupported GOARCH: $GOARCH_VALUE" >&2
    exit 1
    ;;
esac

TARGET_DIR="$ROOT_DIR/resources/bin/${GOOS_VALUE}-${GOARCH_VALUE}"
mkdir -p "$TARGET_DIR"

cd "$GO_DIR"
CGO_ENABLED=0 GOOS="$GOOS_VALUE" GOARCH="$GOARCH_VALUE" go build -trimpath -ldflags="-s -w" -o "$TARGET_DIR/rabbitmq-super-stream-helper" ./cmd/rabbitmq-super-stream-helper
chmod 755 "$TARGET_DIR/rabbitmq-super-stream-helper"
