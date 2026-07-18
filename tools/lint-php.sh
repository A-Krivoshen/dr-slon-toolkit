#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find "$ROOT_DIR/src" "$ROOT_DIR/tests" "$ROOT_DIR/tools" -type f -name '*.php' -print0 2>/dev/null)

php -l "$ROOT_DIR/dr-slon-toolkit.php" >/dev/null
php -l "$ROOT_DIR/uninstall.php" >/dev/null

echo "PHP lint passed"
