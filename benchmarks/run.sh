#!/usr/bin/env bash
set -euo pipefail

# Run Backlit SSR benchmarks in a container.
#
# Usage: bash benchmarks/run.sh [iterations]
#
# Builds a container with Drupal 11 + RHDS elements, creates test
# pages with 5/100/1000/2000 elements, and measures response times
# with SSR enabled vs disabled.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKLIT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ITERATIONS="${1:-20}"
IMAGE="backlit-bench"

echo "Building benchmark image..."
podman build -t "$IMAGE" -f "$SCRIPT_DIR/Containerfile" "$SCRIPT_DIR"

echo ""
echo "Running benchmarks ($ITERATIONS iterations per page)..."
echo ""

podman run --rm \
  -v "$BACKLIT_DIR:/opt/backlit:Z,ro" \
  "$IMAGE" \
  "$ITERATIONS"
