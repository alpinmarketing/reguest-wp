#!/bin/bash
cd "$(dirname "$0")/.." && \
zip -r am-hotelfolio-reguest.zip am-hotelfolio-reguest \
  --exclude "*.git*" \
  --exclude "*/.gitignore" \
  --exclude "*/.gitlab-ci.yml" \
  --exclude "*/.DS_Store" \
  --exclude "*/.claude*" \
  --exclude "*/build-zip.sh"
echo "Created: $(pwd)/am-hotelfolio-reguest.zip"
