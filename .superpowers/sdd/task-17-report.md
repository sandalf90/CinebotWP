# Task 17 Implementation Report

## Status

Implemented frontend AJAX filters, load-more, responsive CSS, and asset enqueuing.

## Files

- Create: `assets/js/cinebot-frontend.js`
- Create: `assets/css/cinebot-frontend.css`
- Modify: `includes/Frontend/ShortcodeHandler.php` (add AJAX filter endpoint + asset enqueuing)

## Implementation

- Vanilla JS: attaches to each `.cinebot-programmazione` instance independently.
- Uses `FormData`, `URLSearchParams`, `fetch()`.
- Filter submit replaces cards; load-more appends.
- ARIA live region for result count announcements.
- Buttons disabled during pending requests, restored on error.
- CSS: responsive grid (auto-fill minmax(300px, 1fr)), CSS variables, keyboard focus, no theme-wide selectors.
- AJAX endpoint: `wp_ajax_cinebot_wp_filter` + `wp_ajax_nopriv_cinebot_wp_filter`.
- Nonce: `cinebot_frontend`.
- Response: `{html, total, has_more}`.
- Assets enqueued only when shortcode is rendered (called from renderProgrammazione).

## Concerns

- PHPUnit/WPCS/PHPStan/build not executed (Docker unavailable).
- `titolo-card-list` template not created yet; AJAX filter returns empty HTML until template is added. This is a known gap to fix in CI verification.
