# Архитектура

## Структура директорий

- `dr-slon-toolkit.php`
  - Минимальная точка запуска плагина.
  - Загружает автозагрузчик Composer, переводы, хук активации и запуск плагина.
- `src/Core`
  - Классы запуска и жизненного цикла (`Plugin`, `Activator`, `Settings`, `ModuleInterface`).
- `src/Admin`
  - Нативная страница в wp-admin, регистрация настроек через Settings API и переиспользуемые UI-блоки для страниц плагина.
- `src/Modules`
  - Функциональные модули с чёткой зоной ответственности.
- `src/Integrations`
  - Классы совместимости со сторонними системами (сейчас — детектор The SEO Framework).

## Основные классы

- `DrSlon\Toolkit\Core\Plugin`
  - Подключает admin-часть и регистрирует включённые модули.
- `DrSlon\Toolkit\Core\Settings`
  - Хранит значения по умолчанию, безопасно объединяет вложенные настройки и даёт доступ к опции `dstk_settings`.
- `DrSlon\Toolkit\Core\Activator`
  - Инициализирует базовые опции и версию при активации.
- `DrSlon\Toolkit\Admin\InfoPanel`
  - Единый информационный блок с контактами и партнёрским виджетом для страниц Dr.Slon Toolkit.

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
  - Отправляет URL в IndexNow вручную и автоматически (publish/update/trash/delete).
  - Отдаёт проверочный ключ по `/<key>.txt` без записи файла на диск.
  - Имеет встроенный whitelist endpoint и простой антидубль отправок URL.
- `SitemapModule`
  - Отдаёт безопасный MVP XML sitemap по маршрутам `/sitemap.xml`, `/sitemap-pt-{post_type}.xml`, `/sitemap-tax-{taxonomy}.xml`.
  - Исключает записи не в статусе `publish` и записи с паролем; noindex-исключения доступны через фильтр `dstk_sitemap_is_noindex`.
  - Добавляет TSF-safe режим: при активном The SEO Framework runtime sitemap Dr.Slon Toolkit не обслуживается.

## Модель данных

- Основная опция: `dstk_settings`
  - `modules` — карта переключателей модулей.
  - `cleanup` — карта поднастроек очистки.
  - `hide_login` — настройки скрытого входа (slug страницы входа).
  - `rest_api` — настройки доступа к REST API (режим, whitelist, capability, системные маршруты).
  - `indexnow` — настройки ключа, endpoint и поддерживаемых типов записей для отправки URL.
  - `sitemap` — флаг runtime sitemap и набор включённых типов записей/таксономий для XML-карт.
- Опция версии: `dstk_version`
