#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="dr-slon-toolkit"
BUILD_DIR="$ROOT_DIR/build"
DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR="$BUILD_DIR/$PLUGIN_SLUG"
MAIN_FILE="$ROOT_DIR/dr-slon-toolkit.php"
VERIFY_SCRIPT="$ROOT_DIR/tools/verify-release.php"

if [[ ! -f "$MAIN_FILE" || ! -f "$ROOT_DIR/readme.txt" || ! -f "$VERIFY_SCRIPT" ]]; then
  echo "Не найдены файлы метаданных или проверки релиза." >&2
  exit 1
fi

VERSION="$(php "$VERIFY_SCRIPT" --source "$ROOT_DIR")"

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$DIST_DIR/$ZIP_NAME"

echo "==> Подготовка каталогов сборки"
rm -rf "$STAGE_DIR" "$ZIP_PATH"
mkdir -p "$STAGE_DIR" "$DIST_DIR"

echo "==> Копирование runtime-файлов"
cp "$ROOT_DIR/dr-slon-toolkit.php" "$STAGE_DIR/"
cp "$ROOT_DIR/readme.txt" "$STAGE_DIR/"
cp "$ROOT_DIR/uninstall.php" "$STAGE_DIR/"
cp "$ROOT_DIR/LICENSE" "$STAGE_DIR/"
cp "$ROOT_DIR/composer.json" "$STAGE_DIR/"
cp "$ROOT_DIR/composer.lock" "$STAGE_DIR/"
cp -R "$ROOT_DIR/src" "$STAGE_DIR/src"

if [[ -d "$ROOT_DIR/languages" ]]; then
  cp -R "$ROOT_DIR/languages" "$STAGE_DIR/languages"
fi

if [[ -d "$ROOT_DIR/assets" ]]; then
  cp -R "$ROOT_DIR/assets" "$STAGE_DIR/assets"
fi

echo "==> Установка production-зависимостей в staging"
composer install \
  --working-dir="$STAGE_DIR" \
  --no-dev \
  --prefer-dist \
  --classmap-authoritative \
  --no-interaction \
  --no-plugins \
  --no-scripts

rm "$STAGE_DIR/composer.json" "$STAGE_DIR/composer.lock"

SYMLINK_PATH="$(find "$STAGE_DIR" -type l -print -quit)"

if [[ -n "$SYMLINK_PATH" ]]; then
  echo "Staging contains a symbolic link: $SYMLINK_PATH" >&2
  exit 1
fi

SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git -C "$ROOT_DIR" log -1 --format=%ct)}"

if [[ ! "$SOURCE_DATE_EPOCH" =~ ^[0-9]+$ ]]; then
  echo "SOURCE_DATE_EPOCH must be a non-negative integer." >&2
  exit 1
fi

find "$STAGE_DIR" -type d -exec chmod 755 {} +
find "$STAGE_DIR" -type f -exec chmod 644 {} +
find "$STAGE_DIR" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +

echo "==> Создание ZIP-архива $ZIP_NAME"
(
  cd "$BUILD_DIR"
  find "$PLUGIN_SLUG" -print0 | sort -z | xargs -0 zip -X -q "$ZIP_PATH"
)

php "$VERIFY_SCRIPT" "$ZIP_PATH" "$VERSION"

echo "Готово: $ZIP_PATH"
