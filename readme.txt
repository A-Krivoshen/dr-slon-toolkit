=== Dr.Slon Toolkit ===
Contributors: A-Krivoshen
Tags: wordpress, toolkit, maintenance, comments, transliteration, cleanup
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Модульный WordPress-плагин для практических задач клиентских сайтов.

== Description ==

Dr.Slon Toolkit — clean-room модульный toolkit-плагин для WordPress-проектов.

Этот релиз включает:
- bootstrap плагина с Composer PSR-4 autoload
- центральный runtime плагина
- страницу настроек в админке на Settings API
- переключатели модулей
- модуль Транслитерации
- модуль Отключения комментариев
- модуль Очистки
- детектор совместимости с The SEO Framework

== Installation ==

1. Загрузите плагин в `/wp-content/plugins/dr-slon-toolkit`.
2. Выполните `composer install` в директории плагина.
3. Активируйте плагин через **Плагины** в wp-admin.
4. Настройте плагин в меню **Dr.Slon Toolkit**.

== Changelog ==

= 0.2.0 =
* Первая рабочая bootstrap-веха.
* Добавлены модули Транслитерации, Отключения комментариев и Очистки.
* Добавлена модульная архитектура настроек и notice о TSF.
* Усилены merge вложенных настроек, edge-case обработка slug и консервативность очистки.
