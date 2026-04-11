# Архитектура

## Структура директорий

- `dr-slon-toolkit.php`
  - Минимальный bootstrap-вход плагина.
  - Загружает Composer autoloader, переводы, хук активации и runtime плагина.
- `src/Core`
  - Классы runtime и жизненного цикла (`Plugin`, `Activator`, `Settings`, `ModuleInterface`).
- `src/Admin`
  - Нативная страница wp-admin и регистрация настроек через Settings API.
- `src/Modules`
  - Функциональные модули с чёткой зоной ответственности.
- `src/Integrations`
  - Хелперы совместимости со сторонними системами (сейчас — детектор The SEO Framework).

## Основные классы

- `DrSlon\Toolkit\Core\Plugin`
  - Запускает admin-часть и регистрирует включённые модули.
- `DrSlon\Toolkit\Core\Settings`
  - Хранит значения по умолчанию, безопасно объединяет вложенные настройки и отдаёт доступ к опции (`dstk_settings`).
- `DrSlon\Toolkit\Core\Activator`
  - Инициализирует базовые опции и версию при активации.

## Модули

Каждый модуль реализует `ModuleInterface` и регистрирует только собственные WordPress hooks.

- `TransliterationModule`
  - Отвечает за транслитерацию slug записей, терминов и имён загружаемых файлов.
- `DisableCommentsModule`
  - Глобально отключает комментарии и убирает связанные элементы интерфейса.
- `CleanupModule`
  - Применяет консервативные cleanup-переключатели по настройкам.

## Модель данных

- Основная опция: `dstk_settings`
  - `modules` — карта переключателей модулей.
  - `cleanup` — карта поднастроек cleanup.
- Опция версии: `dstk_version`
