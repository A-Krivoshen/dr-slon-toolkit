# Dr.Slon Toolkit

Модульный плагин WordPress для обслуживания и базового усиления клиентских сайтов.

**Текущая версия:** [0.9.1](https://github.com/A-Krivoshen/dr-slon-toolkit/releases/tag/v0.9.1)

## Скачать и установить (клиенту)

Composer **не нужен**. Не используйте кнопку **Code → Download ZIP** в репозитории: это исходники для разработки, не готовый пакет.

1. Откройте [Releases](https://github.com/A-Krivoshen/dr-slon-toolkit/releases/latest).
2. Скачайте файл **`dr-slon-toolkit-x.y.z.zip`** (готовый asset релиза, не Source code).
3. В WordPress: **Плагины → Добавить новый → Загрузить плагин**.
4. Активируйте и настройте в меню **Dr.Slon Toolkit**.

Прямая ссылка на последний релиз:  
https://github.com/A-Krivoshen/dr-slon-toolkit/releases/latest

### Обновления

Начиная с `0.9.0` WordPress сам предлагает обновления из **GitHub Releases**:

- берётся только asset с именем `dr-slon-toolkit-<version>.zip`;
- source archives GitHub **не** используются;
- перед установкой проверяются SHA-256, размер, версия и структура пакета.

Переход с `0.8.2` (или раньше) на `0.9.0` — **один раз вручную** через загрузку ZIP. Дальше обновления идут из админки.

## Требования

- WordPress 6.6+
- PHP 8.1+

## Что входит в 0.9.x

- Модульная архитектура и нативная страница настроек
- Скрытый вход, REST API Control, IndexNow, Sitemap, Update Controls
- Транслитерация, отключение комментариев, очистка
- Совместимость с The SEO Framework (Sitemap / IndexNow)
- Проверяемые обновления из GitHub Releases
- Локальные карточки поддержки без удалённого JavaScript

## Модули

### Скрытый вход
- 404 для прямого `wp-login.php` (кроме reset/recovery).
- Вход по slug, например `/my-login/`.
- Аварийное отключение: `define('KRV_DSTK_DISABLE_HIDE_LOGIN', true);` в `wp-config.php`.

### REST API Control
- Режимы: всем / только авторизованным / whitelist.
- Whitelist маршрутов и namespace, capability для обхода.
- Встроенный системный allowlist WordPress всегда активен.

### IndexNow
- Ключ, endpoint, ручная и автоматическая отправка URL.
- Очередь WP-Cron, проверочный `/<key>.txt` без файлов на диске.
- Учитывает noindex/canonical The SEO Framework.

### Sitemap
- `/sitemap.xml` и отдельные карты типов записей/таксономий.
- Пагинация, кеш, `lastmod`.
- При активном The SEO Framework — safe mode (без дублей).

### Update Controls
- Автообновления ядра (all / minor / security / off), плагинов, тем, переводов.
- Управление e-mail уведомлениями.

### Транслитерация
- Русский URL-профиль, slug терминов, имена загружаемых файлов.
- Не меняет уже опубликованные URL автоматически.

### Отключение комментариев
- Глобально закрывает комментарии и пинги, скрывает UI.

### Очистка
- Emoji, wp-embed, XML-RPC, безопасные теги из `<head>`.

## Разработка

```bash
composer install
composer check
```

Composer нужен **только** разработчику (тесты, PHPCS, сборка). На сервере клиента его нет.

Production-зависимостей у плагина нет: runtime либо использует `vendor/autoload.php` из релизного ZIP, либо встроенный PSR-4 loader для `src/`.

## Сборка релизного ZIP

```bash
composer build-release
# или
bash tools/build-release.sh
```

Скрипт:

- собирает staging в `build/dr-slon-toolkit/`;
- ставит production autoload (`composer install --no-dev`) в staging;
- пишет `dist/dr-slon-toolkit-<version>.zip` с корнем `dr-slon-toolkit/`;
- проверяет версии, структуру, размер и обязательные файлы.

### Содержимое релизного архива

- `dr-slon-toolkit.php`, `readme.txt`, `uninstall.php`, `LICENSE`
- `src/`, `assets/`, `languages/` (если есть)
- `vendor/` (autoload для production; на клиенте Composer не запускается)

### Что не попадает в ZIP

- `.git/`, `.github/`, `tests/`, `build/`, `dist/`
- `composer.json` / `composer.lock` (после сборки удаляются из staging)
- dev-документация и конфиги инструментов

## Публикация релиза

1. Версии в `dr-slon-toolkit.php` (`Version` + `DSTK_VERSION`) и `Stable tag` в `readme.txt` совпадают.
2. Пуш в `main`, затем тег:

```bash
git tag v0.9.1
git push origin v0.9.1
```

3. Workflow `.github/workflows/release.yml` соберёт ZIP, прогонит проверки и создаст GitHub Release с asset `dr-slon-toolkit-<version>.zip`.

## Лицензия

GPL-2.0-or-later — см. [LICENSE](LICENSE).
