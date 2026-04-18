#!/bin/bash
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$PLUGIN_DIR/.." && \
zip -r "$PLUGIN_DIR/am-hotelfolio-reguest.zip" am-hotelfolio-reguest \
  --exclude "*.git*" \
  --exclude "*/.gitignore" \
  --exclude "*/.gitlab-ci.yml" \
  --exclude "*/.DS_Store" \
  --exclude "*/.claude*" \
  --exclude "*/build-zip.sh" \
  --exclude "*/am-hotelfolio-reguest.zip"
echo "Created: $PLUGIN_DIR/am-hotelfolio-reguest.zip"
