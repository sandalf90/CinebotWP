# Task 16 Implementation Report

## Status

Implemented ShortcodeHandler, TemplateRenderer, three templates, and Plugin shortcode registration.

## Files

- Create: `includes/Frontend/ShortcodeHandler.php`
- Create: `includes/Frontend/TemplateRenderer.php`
- Create: `templates/programmazione-cards.php`
- Create: `templates/titolo-card.php`
- Create: `templates/dettaglio-titolo.php`
- Modify: `includes/Plugin.php` (add shortcodes composition in boot)
- Modify: `phpstan.neon.dist` (add templates path)

## Implementation

- [cinebot_programmazione] with attributes: tipo, locale, comune, from, to, limit, orderby, order, show_filters, show_desc, layout, offset.
- Defaults: from=today UTC, limit=50 (clamped 1-100), orderby=inizio, order=ASC, filters shown.
- Template lookup: theme/cinebot-wp/{template}.php first, then plugin templates/.
- Output buffering with safe cleanup.
- Transient caching: key cinebot_prog_{md5(atts)}, TTL 900s (filterable via cinebot_wp_cache_ttl).
- findPublicSchedule returns ProgrammazioneCard[] with state=3 and sync_active=1 only.
- countPublicSchedule for total/pagination.
- [cinebot_titolo id="123"] renders dettaglio-titolo template.

## Concerns

- PHPUnit/WPCS/PHPStan/build not executed (Docker unavailable).
