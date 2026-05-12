#!/bin/bash
PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
DIST_DIR="$PLUGIN_DIR/dist"
mkdir -p "$DIST_DIR"
cd "$PLUGIN_DIR/.." && \
zip -r "$DIST_DIR/reguest-wp.zip" reguest-wp \
  --exclude "*.git*" \
  --exclude "*/.gitignore" \
  --exclude "*/.gitlab-ci.yml" \
  --exclude "*/.DS_Store" \
  --exclude "*/.claude*" \
  --exclude "*/build-zip.sh" \
  --exclude "*/dist/*"
echo "Created: $DIST_DIR/reguest-wp.zip"
