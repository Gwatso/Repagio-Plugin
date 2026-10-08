# Contributing to Repagio

Thanks for taking an interest. This document covers getting a local
environment running and the standards a patch is expected to meet.

## Local environment

You need a WordPress install running **WordPress 6.6 or newer** on
**PHP 7.4 or newer**. Any of these will do:

- **[Local](https://localwp.com/)** — simplest on Windows and macOS. Create a
  site, then clone this repository into
  `app/public/wp-content/plugins/repagio`. The folder name matters: Plugin
  Check derives both the plugin slug and the expected text domain from it.
- **[wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)** —
  `npx wp-env start` from the repository root.
- **[LocalWP alternatives](https://make.wordpress.org/core/handbook/tutorials/installing-a-local-server/)** —
  MAMP, XAMPP, Docker, or a plain LAMP stack all work.

Then activate the plugin from **Plugins → Installed Plugins**.

The archive scanner works immediately with no account and no network access.
To work on the generation flow you need a Repagio API key, which you add
under **Repagio → Settings**.

### Testing without spending generations

Generation calls cost against your plan's quota. When working on anything that
is not the API client itself, intercept the request rather than making it:

```php
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    if ( false === strpos( $url, '/generate' ) ) {
        return $pre;
    }

    return array(
        'headers'  => array(),
        'body'     => wp_json_encode( array(
            'content'    => 'Mocked output.',
            'word_count' => 2,
        ) ),
        'response' => array( 'code' => 200, 'message' => 'OK' ),
        'cookies'  => array(),
        'filename' => null,
    );
}, 10, 3 );
```

Note that every filter on `pre_http_request` runs, so pass a non-`false`
`$pre` straight through or you will clobber another mock.

## Coding standards

This project follows the **[WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)** (WPCS), not PSR-12.
In particular:

- Tabs for indentation, spaces for alignment
- Yoda conditions: `if ( 'publish' === $status )`
- Spaces inside parentheses: `foo( $bar )`
- `snake_case` for functions and variables, `Class_Name` for classes
- A docblock on every class, method and function

Install the sniffs and run them before opening a pull request:

```
composer global require wp-coding-standards/wpcs --dev
phpcs --standard=WordPress .
```

### Non-negotiables

These are WordPress.org review rejection reasons. A patch that breaks one will
not be merged:

- **Prefixing.** Every function, class, constant, option, hook and transient
  starts with `repagio_` / `Repagio_` / `REPAGIO_`.
- **Nonces.** Every form POST and every AJAX handler verifies one.
- **Capabilities.** Every admin action checks `current_user_can()`.
- **Sanitize on input, escape on output.** No exceptions, including for values
  you believe are safe.
- **`ABSPATH` guard** at the top of every PHP file.
- **The API key never leaves the HTTP client.** It is never echoed, logged,
  put in a page attribute, or included in an error message.
- **The WordPress HTTP API only.** `wp_remote_get()` / `wp_remote_post()`,
  never cURL directly, and every call goes through
  `includes/class-repagio-api.php`.
- **No new dependencies and no build step.** No Composer packages shipped, no
  npm, no bundled React, no bundled fonts or icon libraries. The block editor
  sidebar (`admin/assets/editor.js`) is plain JavaScript written against the
  `wp.*` globals the editor already loads, so what ships is what reviewers
  read. Dashicons ship with core and are the only icons used.

### Things this plugin deliberately does not do

Please do not send patches adding these:

- **Bulk generation.** Each generation takes 20 to 45 seconds and costs one of
  the user's quota. A "repurpose everything" button would spend a free
  account's whole allowance in a single click.
- **Telemetry, analytics or version pings.** The plugin makes no outbound
  request except those the user explicitly triggers. Loading the block editor
  is not one of them, even with the Repagio sidebar pinned open: the quota is
  read only when the Repurpose panel is expanded.
- **Modifying the user's posts.** The plugin reads content and writes one post
  meta key recording what has been repurposed. It never edits or publishes.

## Internationalisation

Text domain is `repagio`, matching the plugin folder name, loaded from `/languages`. Plugin Check derives the expected domain from the folder, so the two must stay in step. Wrap every user-facing
string, use `printf`-style placeholders rather than concatenation, and add a
`translators:` comment wherever a placeholder's meaning is not obvious:

```php
printf(
    /* translators: %s: formatted word count. */
    esc_html__( '%s words will be sent.', 'repagio' ),
    esc_html( number_format_i18n( $words ) )
);
```

Strings needed in JavaScript are passed through `wp_localize_script()`. Never
hardcode user-facing English in a `.js` file.

Regenerate the translation template after changing any string:

```
wp i18n make-pot . languages/repagio.pot
```

## Building the distribution zip

`bin/build-zip.sh` produces `repagio.zip`, exactly what would be
submitted to WordPress.org. It is the single source of truth for what ships:
the release workflow calls this same script rather than repeating the rules, so
CI and your machine cannot disagree.

```
bash bin/build-zip.sh          # build the zip
bash bin/build-zip.sh --list   # print the file list, build nothing
```

Exclusions come from `.distignore` and nowhere else. The script refuses to
produce an archive that is missing a required file, carrying a dotfile, or
carrying anything from the excluded list.

Two things about `.distignore` worth knowing before editing it:

- A pattern without a leading slash matches a path segment **at any depth**.
  `assets/` therefore also matches `admin/assets/`, which once silently
  stripped the plugin's CSS and JavaScript out of the release. Anchor anything
  that should only apply at the root, as `/assets/` does.
- The `.*` rule near the top is what actually keeps dotfiles out. The named
  entries beneath it are documentation.

## Running Plugin Check

**Run Plugin Check against the built zip, never against the repository
folder.** Checking the working directory always reports errors that do not
exist in the distributed plugin:

| Finding | Why it appears | In the zip |
| --- | --- | --- |
| `hidden_files` | `.gitignore`, `.gitattributes`, `.distignore` | absent |
| `github_directory` | `.github/` | absent |
| `unexpected_markdown_file` | `CLAUDE.md`, `CONTRIBUTING.md` | absent |

To check the real artefact:

1. `bash bin/build-zip.sh`
2. In WordPress admin, go to **Tools → Plugin Check**
3. Choose **Check an uploaded plugin** and upload `repagio.zip`

Or unzip it into a scratch WordPress install's `wp-content/plugins/` and check
it there. The folder must keep the name `repagio`, because Plugin Check
derives both the plugin slug and the expected text domain from the folder name
— rename it and you will get a false `TextDomainMismatch` on every string.

### What is expected to remain

One finding is known and deliberate:

- **`plugin_updater_detected`** — the `Update URI` header, which routes update
  checks to GitHub releases until the plugin is hosted on WordPress.org. It
  must be removed at submission; the reason is spelled out at the top of
  `repagio.php`.

### Why the slug is `repagio` and not `repagio-plugin`

WordPress.org treats "plugin" as a restricted term and will not accept a slug
containing it. The folder, the main file, the text domain and
`Repagio_Updater::SLUG` are therefore all plain `repagio`, and they have to
stay in step: Plugin Check derives both the slug and the expected text domain
from the folder name, so renaming any one of them alone reintroduces a
`TextDomainMismatch` on every translatable string.

The GitHub repository is named `Repagio-Plugin`. That is fine — the
repository name has no bearing on the directory slug, and
`Repagio_Updater::REPO` refers to it deliberately. If the repository is
renamed again, update `Repagio_Updater::REPO`, the `Plugin URI` and
`Update URI` headers in `repagio.php`, the "Report an issue" link in
`Repagio_Admin`, and the source link in `readme.txt` together.

### The former name, Repagify

Up to 0.6.0 the plugin was called Repagify, with the slug, text domain and
prefix `repagify`. Sites that ran it have their API key in the
`repagify_settings` option and their converted posts marked with
`_repagify_converted` post meta. `Repagio_Migration` moves both onto the
`repagio` keys the first time 0.7.0 loads in the admin, and `uninstall.php`
also deletes them in case it never ran.

Those legacy names in `includes/class-repagio-migration.php` and
`uninstall.php` are the only places the old prefix may appear. Everything
new uses `repagio_` / `Repagio_` / `REPAGIO_`.

## Pull requests

- One concern per pull request.
- Say what you changed and why, and how you verified it.
- If you touched anything that talks to the API, say whether you tested
  against the live service or a mock.
- New user-facing strings need to be translatable and need to be in the
  regenerated `.pot`.

## Reporting a security issue

Please do not open a public issue for a security problem. Email
**hello@repagio.app** with the details and give us a reasonable
window to ship a fix before disclosing.
