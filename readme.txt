=== Dr.Slon Toolkit ===
Contributors: A-Krivoshen
Tags: wordpress, toolkit, maintenance, comments, transliteration, cleanup
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Модульный плагин WordPress для практических задач клиентских сайтов.

== Description ==

Dr.Slon Toolkit — модульный плагин, созданный с нуля (clean-room) для WordPress-проектов.

Этот релиз включает:
- запуск плагина с автозагрузкой Composer PSR-4
- центральный класс запуска
- страницу настроек в админке на Settings API
- переключатели модулей
- модуль «Скрытый вход»
- модуль «Транслитерация»
- модуль «Отключение комментариев»
- модуль «Очистка»
- уведомление о совместимости с The SEO Framework

== Installation ==

1. Установите готовый ZIP-архив плагина через wp-admin.
2. Активируйте плагин через **Плагины**.
3. Настройте плагин в меню **Dr.Slon Toolkit**.

== Development ==

Composer нужен только разработчику:
- для локальной разработки (`composer install`);
- для сборки релизного ZIP (`composer build-release` или `bash tools/build-release.sh`).

== Changelog ==

= 0.2.0 =
* Первая рабочая версия с модульной архитектурой.
* Добавлены модули «Скрытый вход», «Транслитерация», «Отключение комментариев» и «Очистка».
* Добавлены модульные настройки и уведомление о совместимости с TSF.
* Улучшена обработка вложенных настроек, сложных случаев slug и консервативной очистки.
