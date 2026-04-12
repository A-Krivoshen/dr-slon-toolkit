#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="dr-slon-toolkit"
BUILD_DIR="$ROOT_DIR/build"
DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR="$BUILD_DIR/$PLUGIN_SLUG"
MAIN_FILE="$ROOT_DIR/dr-slon-toolkit.php"

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "Не найден главный файл плагина: $MAIN_FILE" >&2
  exit 1
fi

VERSION="$(php -r '$contents = file_get_contents($argv[1]); if (!preg_match("/^[ \t]*\* Version:\s*([^\r\n]+)/m", $contents, $m)) { fwrite(STDERR, "Не удалось определить версию плагина.\n"); exit(1);} echo trim($m[1]);' "$MAIN_FILE")"

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"

echo "==> Подготовка каталогов сборки"
rm -rf "$STAGE_DIR" "$ZIP_PATH"
mkdir -p "$STAGE_DIR" "$DIST_DIR"

echo "==> Установка production-зависимостей Composer"
composer install \
  --working-dir="$ROOT_DIR" \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader \
  --no-interaction

echo "==> Копирование runtime-файлов"
cp "$ROOT_DIR/dr-slon-toolkit.php" "$STAGE_DIR/"
cp "$ROOT_DIR/readme.txt" "$STAGE_DIR/"
cp "$ROOT_DIR/uninstall.php" "$STAGE_DIR/"
cp "$ROOT_DIR/LICENSE" "$STAGE_DIR/"
cp -R "$ROOT_DIR/src" "$STAGE_DIR/src"
cp -R "$ROOT_DIR/vendor" "$STAGE_DIR/vendor"

if [[ -d "$ROOT_DIR/languages" ]]; then
  cp -R "$ROOT_DIR/languages" "$STAGE_DIR/languages"
fi

if [[ -d "$ROOT_DIR/assets" ]]; then
  cp -R "$ROOT_DIR/assets" "$STAGE_DIR/assets"
fi

echo "==> Создание ZIP-архива $ZIP_NAME"
(
  cd "$BUILD_DIR"
  zip -rq "$ZIP_PATH" "$PLUGIN_SLUG"
)

echo "Готово: $ZIP_PATH"
