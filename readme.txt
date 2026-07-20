=== Dr.Slon Toolkit ===
Contributors: A-Krivoshen
Tags: toolkit, maintenance, security, transliteration, indexnow
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Модульный плагин WordPress для практических задач клиентских сайтов.

== Description ==

Dr.Slon Toolkit — модульный плагин, созданный с нуля (clean-room) для WordPress-проектов.

Этот релиз включает:
- модульную архитектуру с PSR-4 (vendor autoload в релизе или встроенный loader)
- центральный класс запуска
- понятную страницу настроек и отдельную встроенную справку
- переключатели модулей
- модуль «Скрытый вход»
- модуль «REST API Control»
- модуль «IndexNow»
- модуль «Sitemap»
- модуль «Update Controls»
- модуль «Транслитерация»
- модуль «Отключение комментариев»
- модуль «Очистка»
- интеграцию с The SEO Framework для Sitemap и IndexNow
- безопасные обновления из GitHub Releases

Для модуля «Скрытый вход»:
- поддерживается настраиваемый slug входа;
- при изменении slug правила маршрутизации обновляются автоматически;
- доступен аварийный bypass через `KRV_DSTK_DISABLE_HIDE_LOGIN` в `wp-config.php`.

IndexNow отправляет автоматические уведомления через WP-Cron, учитывает Search Engine Visibility и не отправляет URL, которые The SEO Framework пометил noindex или другим canonical.

Обновления принимаются только из готового ZIP-asset GitHub Release (`dr-slon-toolkit-x.y.z.zip`). Source code ZIP с кнопки Code не используется. Перед установкой проверяются SHA-256, версия и структура архива.

== Installation ==

1. Скачайте `dr-slon-toolkit-x.y.z.zip` со страницы GitHub Releases (не Code → Download ZIP).
2. Установите архив через **Плагины → Добавить новый → Загрузить плагин**.
3. Активируйте плагин.
4. Настройте модули в меню **Dr.Slon Toolkit**.

Composer на сервере клиента не требуется.

== Development ==

Composer нужен только разработчику:
- для локальной разработки (`composer install`);
- для сборки релизного ZIP (`composer build-release` или `bash tools/build-release.sh`).
- для публикации: push тега `vX.Y.Z` запускает workflow, который прикрепляет проверенный ZIP к GitHub Release.

== Changelog ==

= 0.9.1 =
* Hide Login: закрыт обход slug через dstk_custom_login=1.
* REST API Control: editor-маршруты только для авторизованных; allowlist capability.
* Update Controls: legacy security → minor; режим security убран из UI.
* Rewrite flush, IndexNow, Sitemap, Disable Comments, multisite seed/uninstall hardening.
* Unit-тесты REST, Hide Login, security→minor.

= 0.9.0 =
* Исправлены критические сценарии настроек, транслитерации, Hide Login и Update Controls.
* IndexNow переведён на асинхронную очередь с повторами и интеграцией The SEO Framework.
* Sitemap получил пагинацию, кеширование, поддержку страниц и подкаталогов.
* Добавлены автообновления через проверенные assets GitHub Releases.
* Добавлены новый интерфейс, страница помощи, unit-тесты, PHPCS и CI.
* Лицензия и релизная сборка синхронизированы с GPL-2.0-or-later.

= 0.8.3 =
* Усилен MVP «REST API Control»: улучшена нормализация whitelist-маршрутов через безопасный разбор пути URL.
* Для whitelist namespace добавлена дополнительная runtime-санитизация как защита от некорректных значений в опциях.

= 0.8.2 =
* Синхронизирована документация и релизные данные с фактическим составом модулей.
* Подтверждено, что модули Redirect Manager и Login Attempts в текущем релизе не реализованы и остаются в roadmap.

= 0.8.1 =
* Выполнен pre-release hardening: исправлена типобезопасность фильтров модуля «Update Controls» для корректной работы с null/boolean значениями WordPress.
* В uninstall добавлена очистка служебного кеша `dstk_indexnow_cache`.

= 0.8.0 =
* Добавлен первый безопасный MVP модуля «Update Controls».
* Добавлены настройки режима автообновлений ядра: all, minor, security (MVP), off.
* Добавлены отдельные переключатели автообновлений плагинов, тем, переводов и e-mail уведомлений.
* Реализация построена на нативных фильтрах WordPress без хака ядра и без отдельной cron-логики.

= 0.7.1 =
* Упрощён и стабилизирован MVP «Sitemap»: снижена тяжесть запросов и убраны лишние проверки мета-ключей.
* Добавлен фильтр `dstk_sitemap_is_noindex` для безопасного исключения noindex-записей без жёсткой привязки к сторонним SEO-плагинам.
* Уточнён TSF-safe режим: sitemap Dr.Slon Toolkit не активируется, если The SEO Framework обслуживает sitemap.

= 0.7.0 =
* Добавлен первый безопасный MVP модуля «Sitemap».
* Реализованы маршруты `/sitemap.xml`, `/sitemap-pt-{post_type}.xml`, `/sitemap-tax-{taxonomy}.xml`.
* Добавлены настройки включения sitemap runtime и выбора типов записей/таксономий.
* Добавлено TSF-safe поведение: при активном The SEO Framework sitemap Dr.Slon Toolkit не отдаётся, чтобы избежать дублей.

= 0.6.1 =
* Выполнен hardening MVP модуля «IndexNow».
* Усилен runtime отдачи ключа `/<key>.txt` (предсказуемое поведение для GET/HEAD).
* Добавлен runtime-whitelist endpoint и безопасный fallback при некорректном значении.
* Улучшен антидубль отправок URL, чтобы снизить конфликты между ручной и автоматической отправкой.

= 0.6.0 =
* Добавлен MVP модуля «IndexNow».
* Добавлена автоматическая отправка URL при publish/update/trash/delete поддерживаемых типов записей.
* Добавлена ручная отправка URL из админки с nonce и проверкой прав.
* Добавлен безопасный публичный ключ по адресу `/<key>.txt` без записи файла на диск.

= 0.5.0 =
* Выполнен hardening MVP модуля «REST API Control».
* Добавлен встроенный системный allowlist WordPress, который не зависит от редактируемых настроек.
* Добавлены префиксные маршруты (без regex) для критичных REST-сценариев редактора и медиа.
* Поле системных маршрутов в настройках теперь только расширяет встроенный allowlist.

= 0.3.0 =
* Добавлен MVP модуля «REST API Control».
* Добавлены режимы доступа к REST API: разрешить всем, только авторизованным, whitelist.
* Добавлены whitelist маршрутов, whitelist namespace, доверенная capability и список системных маршрутов.

= 0.2.0 =
* Первая рабочая версия с модульной архитектурой.
* Добавлены модули «Скрытый вход», «Транслитерация», «Отключение комментариев» и «Очистка».
* Добавлены модульные настройки и уведомление о совместимости с TSF.
* Улучшена обработка вложенных настроек, сложных случаев slug и консервативной очистки.
