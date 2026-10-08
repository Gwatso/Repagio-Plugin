# Repagio WordPress Plugin

Connects a WordPress site to Repagio (repagio.app),
an AI content repurposing platform. The plugin scans existing
published posts, identifies repurposing opportunities, and
generates SEO blog posts, LinkedIn posts, X threads, and
newsletters via the Repagio API.

## Non-negotiable WordPress standards

Every item below is a WordPress.org plugin review rejection
reason. Treat them as hard requirements, not suggestions.

### Security
- Nonce verification on EVERY form submission and AJAX handler
  (wp_nonce_field / check_admin_referer / check_ajax_referer)
- Capability checks (current_user_can) on every admin action.
  Settings, the API key and the dashboard use
  Repagio_Settings::capability() (manage_options, filterable via
  repagio_settings_capability). The editor sidebar and the
  repurposing AJAX endpoints use Repagio_Settings::can_repurpose(),
  which also admits Repagio_Settings::editor_capability()
  (edit_others_posts, filterable via repagio_editor_capability).
  Per-post endpoints also check edit_post
- Sanitize ALL input: sanitize_text_field, esc_url_raw, absint,
  sanitize_key — never trust $_POST, $_GET or $_REQUEST directly
- Escape ALL output: esc_html, esc_attr, esc_url, wp_kses_post
- Use $wpdb->prepare() for any custom query
- Every PHP file starts with:
  if (!defined('ABSPATH')) { exit; }
- The API key is stored in wp_options. Never log it, never echo
  it, never include it in error messages

### Conventions
- Prefix every function, class, constant, option and hook with
  repagio_ / Repagio_ / REPAGIO_
  (The plugin was called Repagify up to 0.6.0. The legacy
  repagify_settings / _repagify_converted names may appear only
  in includes/class-repagio-migration.php and uninstall.php)
- The folder, main file (repagio.php), text domain and
  Repagio_Updater::SLUG are all "repagio" and must stay in step
- Follow WordPress PHP Coding Standards (WPCS), NOT PSR-12
- Use the WordPress HTTP API (wp_remote_get / wp_remote_post).
  Never use cURL directly
- Use the Settings API for the options page
- Text domain: repagio — wrap all user-facing strings in
  __() or esc_html__()
- Minimum PHP 7.4, minimum WordPress 6.6 (the sidebar uses the
  wp.editor plugin slots; there is no wp.editPost fallback)
- GPL-2.0-or-later license

### Architecture
- No build step anywhere. Admin pages are vanilla PHP with
  enqueued vanilla JS. The Gutenberg sidebar
  (admin/assets/editor.js) is plain JS written against the wp.*
  globals the editor already loads — wp.element.createElement,
  no JSX — so WordPress.org reviewers read exactly what ships.
  build/ is bin/build-zip.sh's staging folder, not a JS output
  directory
- All API calls go through includes/class-repagio-api.php.
  No scattered wp_remote_post calls anywhere else
- Uninstall must clean up: register_uninstall_hook removing
  plugin options and post meta

### Do not
- Add Composer or npm dependencies, a JS build step, or a bundled
  copy of React (use the wp.element global)
- Modify or publish posts without explicit user action
- Make external HTTP requests on every page load. That includes
  editor loads: the sidebar can be pinned, so drawing it must not
  reach the service. Quota is fetched only when the Repurpose
  panel is expanded, generation only on Generate. readme.txt
  promises "It will not make an external request on a normal page
  load" — keep that true
- Use inline styles where an enqueued stylesheet works

## API

Base URL: https://repagio.app/api/v1
Auth: Authorization: Bearer <api_key>

The API is live. Every client method must still fail gracefully
with a clear, user-readable message on 404, auth, rate-limit,
timeout and server errors. The archive scanner must work
entirely offline with no API calls.

Endpoints:
- GET  /me         — plan tier, usage, conversions remaining
- POST /generate   — { text, output_type, tone } -> generated content
- GET  /sources    — list saved sources
- POST /sources    — create a source

GET /sources and POST /sources exist on the service, but the
plugin deliberately does not use them and has no feature that
needs them. Do not wire them into Repagio_API or the UI unless a
feature that actually requires saved sources is agreed first.

## Build order
1. Plugin scaffold, settings page, API key storage, test connection
2. Archive scanner — WP_Query over published posts, opportunity
   dashboard (no API needed)
3. Generation flow — calls /generate, shows result, saves to post meta
4. Gutenberg sidebar — no build step (see Architecture)
5. WordPress.org submission prep