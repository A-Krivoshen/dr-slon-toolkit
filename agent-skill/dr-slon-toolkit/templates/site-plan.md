# Dr.Slon Toolkit site plan

Fill this in from the user's answers. Leave captcha keys, passwords, and tokens out of this file.

- Site the user named:
- WordPress path or WP-CLI target:
- Network activate: no
- Install latest release: yes

Enable only the checked modules. Unchecked stays off.

- [ ] maintenance — closes the public site (503 and noindex). Leave off unless the user asked.
- [ ] hide_login — slug:
- [ ] yandex_captcha — provider yandex or google; keys entered in wp-admin, not here
- [ ] login_attempts — whitelist edited in wp-admin, not here
- [ ] rest_api_control — mode: allow_all
- [ ] indexnow
- [ ] sitemap
- [ ] ai_agents — pulse stays off unless the user asked
- [ ] redirects — see redirects.example.txt
- [ ] transliteration — rewrite existing URLs only if the user asked
- [ ] disable_comments
- [ ] cleanup
- [ ] update_controls — core mode: minor

Before hide_login is enabled: emergency constant is ready to paste, and a logged-in session stays open.
