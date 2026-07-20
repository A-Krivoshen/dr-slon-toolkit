# Архитектура

## Структура директорий

- `dr-slon-toolkit.php`
  - Минимальная точка запуска плагина.
  - Подключает `vendor/autoload.php` (релиз/dev) или встроенный PSR-4 loader для `src/`, затем переводы, хуки активации и запуск.
- `src/Core`
  - Классы запуска и жизненного цикла (`Plugin`, `Activator`, `Deactivator`, `RewriteManager`, `Settings`, `ModuleInterface`).
- `src/Admin`
  - Нативная страница в wp-admin, регистрация настроек через Settings API и переиспользуемые UI-блоки для страниц плагина.
- `src/Modules`
  - Функциональные модули с чёткой зоной ответственности.
- `src/Integrations`
  - Интеграция с The SEO Framework и обновлениями GitHub Releases.

## Основные классы

- `DrSlon\Toolkit\Core\Plugin`
  - Подключает admin-часть и регистрирует включённые модули.
- `DrSlon\Toolkit\Core\Settings`
  - Хранит значения по умолчанию, безопасно объединяет вложенные настройки и даёт доступ к опции `dstk_settings`.
- `DrSlon\Toolkit\Core\Activator`
  - Инициализирует базовые опции и версию, включая network activation.
- `DrSlon\Toolkit\Core\RewriteManager`
  - Выполняет отложенный flush только после регистрации актуальных правил и очищает их при деактивации.
- `DrSlon\Toolkit\Integrations\GitHubReleaseUpdater`
  - Проверяет GitHub Release API и устанавливает только ZIP-asset с совпадающими SHA-256, размером, версией и штатной структурой.
- `DrSlon\Toolkit\Admin\InfoPanel`
  - Локальные карточки поддержки и ссылок без удалённого исполняемого кода.
  - Рендерится внутри страниц Dr.Slon Toolkit и не вмешивается в чужие экраны wp-admin.

## Модули

Каждый модуль реализует `ModuleInterface` и регистрирует только свои WordPress hooks.

- `TransliterationModule`
  - Транслитерация slug записей, терминов и имён загружаемых файлов.
- `DisableCommentsModule`
  - Глобальное отключение комментариев и связанных элементов интерфейса.
- `CleanupModule`
  - Консервативная очистка по настройкам.
- `HideLoginModule`
  - Скрывает прямой доступ к wp-login.php и обслуживает кастомный URL входа.
  - Поддерживает fallback для сайтов без ЧПУ и аварийный bypass через константу.
- `RestApiControlModule`
  - Ограничивает доступ к REST API через нативный фильтр `rest_pre_dispatch`.
  - Поддерживает режимы: открытый, только для авторизованных и whitelist.
  - Содержит встроенный базовый allowlist системных маршрутов; настройки в админке только расширяют этот список.
- `IndexNowModule`
  - Отправляет URL вручную или через неблокирующую очередь WP-Cron.
  - Отдаёт проверочный ключ по `/<key>.txt` без записи файла на диск.
  - Учитывает Search Engine Visibility, noindex и canonical The SEO Framework.
- `SitemapModule`
  - Отдаёт пагинированный и кешируемый XML sitemap по собственным маршрутам.
  - Исключает записи не в статусе `publish` и записи с паролем; noindex-исключения доступны через фильтр `dstk_sitemap_is_noindex`.
  - Добавляет TSF-safe режим: при активном The SEO Framework runtime sitemap Dr.Slon Toolkit не обслуживается.
- `UpdateControlsModule`
  - Управляет автообновлениями ядра, плагинов, тем и переводов через нативные фильтры WordPress.
  - Позволяет управлять e-mail уведомлениями об автообновлениях.
  - Для режима security использует безопасное приближение через minor-канал без major/dev обновлений.

## Модель данных

- Основная опция: `dstk_settings`
  - `modules` — карта переключателей модулей.
  - `cleanup` — карта поднастроек очистки.
  - `hide_login` — настройки скрытого входа (slug страницы входа).
  - `rest_api` — настройки доступа к REST API (режим, whitelist, capability, системные маршруты).
  - `indexnow` — настройки ключа, endpoint и поддерживаемых типов записей для отправки URL.
  - `sitemap` — флаг runtime sitemap и набор включённых типов записей/таксономий для XML-карт.
  - `update_controls` — режим обновлений ядра и переключатели автообновлений плагинов/тем/переводов и e-mail уведомлений.
- Опция версии: `dstk_version`
