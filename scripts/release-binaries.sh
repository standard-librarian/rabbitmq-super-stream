#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
GO_DIR="$ROOT_DIR/go"

for target in linux-amd64 linux-arm64 darwin-amd64 darwin-arm64
do
  GOOS_VALUE=${target%-*}
  GOARCH_VALUE=${target#*-}
  TARGET_DIR="$ROOT_DIR/resources/bin/$target"
  mkdir -p "$TARGET_DIR"

  echo "building $target"
  (
    cd "$GO_DIR"
    CGO_ENABLED=0 GOOS="$GOOS_VALUE" GOARCH="$GOARCH_VALUE" \
      go build -trimpath -ldflags="-s -w" -o "$TARGET_DIR/rabbitmq-super-stream-helper" ./cmd/rabbitmq-super-stream-helper
  )
  chmod 755 "$TARGET_DIR/rabbitmq-super-stream-helper"
done
