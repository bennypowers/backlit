#!/usr/bin/env bash
set -euo pipefail

# Download the lit-ssr binary for the current platform.
# Called automatically by Composer post-install/post-update.
# Can also be run manually: ./scripts/download-binary.sh [version]

VERSION="${1:-v0.1.0}"
BIN_DIR="$(cd "$(dirname "$0")/.." && pwd)/bin"

OS="$(uname -s)"
ARCH="$(uname -m)"

case "$OS" in
  Linux)  PLATFORM_OS="linux" ;;
  Darwin) PLATFORM_OS="darwin" ;;
  MINGW*|MSYS*|CYGWIN*) PLATFORM_OS="win32" ;;
  *) echo "backlit: unsupported OS: $OS" >&2; exit 1 ;;
esac

case "$ARCH" in
  x86_64|amd64) PLATFORM_ARCH="x64" ;;
  aarch64|arm64) PLATFORM_ARCH="arm64" ;;
  *) echo "backlit: unsupported architecture: $ARCH" >&2; exit 1 ;;
esac

BINARY="lit-ssr-${PLATFORM_OS}-${PLATFORM_ARCH}"
if [ "$PLATFORM_OS" = "win32" ]; then
  BINARY="${BINARY}.exe"
fi

URL="https://github.com/bennypowers/lit-ssr-wasm/releases/download/${VERSION}/${BINARY}"

echo "backlit: downloading ${BINARY} (${VERSION})..."
mkdir -p "$BIN_DIR"
curl -fsSL "$URL" -o "${BIN_DIR}/${BINARY}"
chmod +x "${BIN_DIR}/${BINARY}"
echo "backlit: installed to ${BIN_DIR}/${BINARY}"
