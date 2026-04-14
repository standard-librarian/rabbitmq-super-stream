#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
DIST_DIR="$ROOT_DIR/dist"

rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

for binary in "$ROOT_DIR"/resources/bin/*/rabbitmq-super-stream-helper
do
  [ -f "$binary" ] || continue

  target=$(basename "$(dirname "$binary")")
  archive="$DIST_DIR/rabbitmq-super-stream-helper_${target}.tar.gz"
  staging_dir="$DIST_DIR/$target"

  mkdir -p "$staging_dir"
  cp "$binary" "$staging_dir/rabbitmq-super-stream-helper"
  chmod 755 "$staging_dir/rabbitmq-super-stream-helper"

  tar -C "$staging_dir" -czf "$archive" rabbitmq-super-stream-helper
  rm -rf "$staging_dir"
done

(
  cd "$DIST_DIR"
  shasum -a 256 ./*.tar.gz > SHA256SUMS
)
