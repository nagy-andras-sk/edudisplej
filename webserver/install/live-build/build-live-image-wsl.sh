#!/bin/bash
set -euo pipefail

SOURCE_DIR="${1:-}"
OUTPUT_DIR="${2:-}"
INSTALL_DEPS="${3:-true}"

if [ -z "$SOURCE_DIR" ] || [ -z "$OUTPUT_DIR" ]; then
  echo "Usage: $0 <source_live_build_dir> <output_dir> [install_deps:true|false]"
  exit 1
fi

if [ ! -d "$SOURCE_DIR" ]; then
  echo "[!] Source directory does not exist: $SOURCE_DIR"
  exit 1
fi

mkdir -p "$OUTPUT_DIR"

if [ "$INSTALL_DEPS" = "true" ]; then
  sudo apt update
  sudo apt install -y live-build debootstrap squashfs-tools xorriso
fi

WORK_DIR="$HOME/edudisplej-livebuild-work"
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"
cp -a "$SOURCE_DIR/." "$WORK_DIR/"

# Copy the required files from parent directory (install/)
PARENT_DIR="$(dirname "$SOURCE_DIR")"
if [ -f "$PARENT_DIR/offline-installer.sh" ]; then
  cp "$PARENT_DIR/offline-installer.sh" "$WORK_DIR/../"
fi
if [ -f "$PARENT_DIR/edudisplej-offline-installer.service" ]; then
  cp "$PARENT_DIR/edudisplej-offline-installer.service" "$WORK_DIR/../"
fi

# Convert all shell scripts from DOS to Unix line endings
cd "$WORK_DIR"
find . -type f \( -name "*.sh" -o -name "config" \) -exec sed -i 's/\r$//' {} \;

cd "$WORK_DIR"
chmod +x ./prepare-live-build.sh ./build-live-image.sh ./auto/config

./build-live-image.sh

if ls ./*.iso >/dev/null 2>&1; then
  cp -f ./*.iso "$OUTPUT_DIR/"
  echo "[✓] ISO copied to: $OUTPUT_DIR"
else
  echo "[!] Build finished but no ISO found"
  exit 1
fi
