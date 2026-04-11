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
- модуль «Транслитерация»
- модуль «Отключение комментариев»
- модуль «Очистка»
- уведомление о совместимости с The SEO Framework

== Installation ==

1. Загрузите плагин в `/wp-content/plugins/dr-slon-toolkit`.
2. Выполните `composer install` в папке плагина.
3. Активируйте плагин через **Плагины** в wp-admin.
4. Настройте плагин в меню **Dr.Slon Toolkit**.

== Changelog ==

= 0.2.0 =
* Первая рабочая версия с модульной архитектурой.
* Добавлены модули «Транслитерация», «Отключение комментариев» и «Очистка».
* Добавлены модульные настройки и уведомление о совместимости с TSF.
* Улучшена обработка вложенных настроек, сложных случаев slug и консервативной очистки.
