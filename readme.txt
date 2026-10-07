=== Repagio ===
Contributors: afriflare
Tags: content, repurposing, seo, social media, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scans your published archive and scores every post for repurposing potential, so you can see which of your old writing is worth a second life.

== Description ==

Most sites are sitting on years of published writing nobody reads any more. Repagio reads your archive and tells you which of it is still worth something.

= What the plugin does on your own server =

The plugin is complete as installed. It needs no account, no API key and no internet connection to do any of the following, and none of it is withheld from anyone:

* Scans every published post on the site
* Scores each post out of 100 for repurposing potential, using word count, heading structure, how long the post has lain dormant and whether you have reused it before
* Explains every score in plain language — "2,400 words, well structured, never repurposed"
* Surfaces which posts have never been repurposed, and which you have already used
* Filters by post type, category and date range, sorts by score, length or date, and pages through the whole archive
* Reports totals across the archive, including how many words of dormant content you are sitting on
* Batches the scan automatically on large archives so it never times out

All of that runs on your own server and makes no network request of any kind.

= The optional service connection =

Turning a post into new content is done by **Repagio**, a separate web service at [repagio.app](https://repagio.app/). Connecting an account is optional, and the plugin is fully usable without one.

If you do connect an account, you can pick a post, choose a format and a tone, and get back content to copy and use wherever you like. Available formats are a blog post, a LinkedIn post, an X thread and a newsletter, each in one of five tones.

Repagio offers both free and paid plans, and those plans differ in how many generations they include. That is an arrangement between you and that service. It is not a restriction in this plugin: there is one version of this plugin, every line of its code ships to every user, and nothing in it is reserved for paying customers.

= What this plugin will not do =

* It will not edit, publish or change any of your posts. It writes one post meta value recording what you have already repurposed, and nothing else.
* It will not generate in bulk. One post at a time, so you can read each result before requesting another.
* It will not make an external request on a normal page load, and it sends no telemetry or analytics of any kind.

= Open source =

Repagio is free software, licensed GPLv2 or later. Development happens in the open and patches are welcome — see CONTRIBUTING.md in the repository.

Source code: https://github.com/Gwatso/Repagio-Plugin

== External services ==

This plugin can connect to the Repagio API, a third-party service operated by Afriflare, to generate content from posts you choose. Generation is computation performed by that service rather than on your server, so the plugin cannot produce generated content without it. Everything else the plugin does works with no connection at all.

**Service:** Repagio
**Endpoint:** `https://repagio.app/api/v1`
**Provider:** Afriflare — https://repagio.app/

= What is sent, and when =

The plugin contacts Repagio in exactly three situations, all of them triggered by you:

1. **When you press "Test connection"** on the settings screen. Your API key is sent so the service can identify your account. No post content is sent. The service replies with your plan and how many generations your account has used and has left.

2. **When you press "Repurpose" and then "Generate"** on a post in the dashboard. The following is sent, and nothing else:
   * Your API key, to identify your account
   * The plain text of that single post — shortcodes, HTML tags and block markup stripped out first
   * The output format you chose (blog, linkedin, twitter or newsletter)
   * The tone you chose
   * The target keyword, only if you typed one and only for blog output

3. **When a Repagio admin screen is opened**, to read your account's plan and remaining generations so the dashboard can show them. Your API key is sent; no post content is. This result is cached for five minutes, so opening the screen repeatedly does not repeat the request. It does not happen at all if you have not saved an API key.

= What is never sent =

* No post content is transmitted during scanning, scoring, sorting, filtering or paging. All of that happens on your server.
* No content from any post other than the one you explicitly selected.
* No data on a normal page load, on plugin activation, or on any front-end request.
* No telemetry, analytics, usage statistics, site URL, administrator email or user information of any kind.

= Working without an account =

The archive scanner and the opportunity dashboard work fully with no Repagio account, no API key and no network access. With no API key saved, the plugin makes no external request whatsoever, and the generate action is not offered, because there is no service to send the request to.

= Terms and privacy =

Using the generation feature means sending your content to Repagio, and is subject to their terms:

* Terms of service: https://repagio.app/terms
* Privacy policy: https://repagio.app/privacy

== Installation ==

1. Upload the `repagio` folder to `/wp-content/plugins/`, or install the plugin through the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Go to **Repagio → Dashboard**. The scanner runs immediately — no account needed.
4. Optionally, to generate content, go to **Repagio → Settings**, paste the API key from your Repagio account, and press **Test connection**.

== Frequently Asked Questions ==

= Does this plugin require a paid account? =

No. Scanning, scoring and the full opportunity dashboard work with no account at all. Generating content uses the Repagio web service, which offers both free and paid plans.

= What does the plugin do without an account? =

Everything except generation. With no account and no network access, the plugin scans every published post, scores each one out of 100 for repurposing potential, explains each score in plain language, shows which posts have never been repurposed, filters by post type, category and date range, sorts by score, word count or date, pages through the whole archive, and reports totals including how many words of dormant content the site holds.

That is the substance of the plugin, and it is available to every user.

= Is any of the plugin's own functionality locked? =

No. There is one version of this plugin. Every line of its code ships to every user, and no feature inside it is reserved for paying customers, gated behind a licence key, or limited by a trial period.

The only thing the plugin cannot do on its own is generate content, because generating content is computation performed by an external web service rather than by code running on your server. That describes where the work happens. It is not a restriction imposed by the plugin.

= Does my content leave my site? =

Only when you ask it to. Scanning, scoring and filtering happen entirely on your server with no network access.

When you press Generate on a post, the plain text of that one post is sent to Repagio so it can be repurposed. No other post is sent, and nothing is sent in the background. See the External services section above for the full detail.

= How many generations does my account get? =

That depends on the plan on your Repagio account. The service offers free and paid plans that differ in how many generations they include. The dashboard shows how many your account has left before you use one, and tells you when the account has none remaining.

= Why has Repagio not issued me an API key? =

Repagio currently issues API keys on its Pro and Agency plans, with support for Free and Creator accounts on the way. That is the service's own policy about its API, and is nothing the plugin controls.

The scanner and the dashboard work with no key at all, on any plan or with no account, so the plugin remains fully usable meanwhile.

= Can I repurpose my whole archive at once? =

No, and that is deliberate. Each generation takes the better part of a minute and consumes one of the generations on your Repagio account. A bulk action would spend a small allowance in a single click with nothing to show for it. The plugin works one post at a time so you can read each result before deciding on the next.

= Will this change my published posts? =

No. The plugin never edits or publishes a post. The only thing it writes is a single post meta value recording which formats you have already generated, so the dashboard can show you what has been reused.

= Why is a post I know is good scoring badly? =

The score rewards length, heading structure and time spent dormant. A short post scores low however good it is, because there is not enough there to turn into a thread or a newsletter. Read the reason text under any score to see what drove it.

= Can I point this at a development API instance? =

Yes. The API base URL is editable on the settings page.

= Where is my API key stored? =

In your site's options table. It is never written to logs, never included in error messages, never placed in a page attribute, and never shown in full on the settings page once saved — only a mask of the last four characters is displayed.

== Screenshots ==

1. The opportunity dashboard, ranking every published post by repurposing potential. This view works with no account.
2. Choosing an output format and tone for a post, with the extracted word count shown before anything is sent.
3. A finished generation, ready to copy.
4. The settings screen, showing the saved API key as a mask and the result of a connection test.

== Changelog ==

= 0.7.0 =
* The plugin is now called Repagio, and the service has moved to repagio.app. The plugin folder, main file and text domain are now repagio.
* Your API key, settings and the record of which posts you have converted carry over automatically from the previous name.
* No change to scanning, scoring, quota handling or generation.

= 0.6.0 =
* Settings and Dashboard links in the Plugins list, with a matching set on the network plugins screen for multisite.
* Documentation, Support and Report an issue links under the plugin's own row.
* View details now opens a real modal, populated from readme.txt rather than a second copy of the same text kept in code.
* Updates are served from GitHub releases until the plugin is hosted on WordPress.org, which is what makes the Enable auto-updates control appear. Auto-updates are never switched on for you.
* Release builds are produced by a workflow that verifies the tag, the Version header and the Stable tag all agree before publishing.
* Clearer wording throughout about which work happens on your server and which happens on the Repagio service. No functional change.

= 0.5.0 =
* Compliance pass for the WordPress.org directory: full GPL-2.0 licence text, a complete external services disclosure, a translation template, and repository hygiene files.
* Escaped two output statements that were safe but unescaped.
* Renamed an internal method whose name collided with a PHP function on security audit lists.
* No change to scanning, scoring, quota handling or generation.

= 0.4.0 =
* The dashboard now shows which plan your Repagio account is on and how many generations it has left.
* Checks the account's remaining generations before opening the generate dialog, rather than spending a request to be refused.
* Clearer guidance when an account has no generations remaining.
* Settings page now explains which Repagio plans can currently issue an API key.

= 0.3.0 =
* Generation flow: turn a published post into a blog post, LinkedIn post, X thread or newsletter.
* Extracts clean text from post content, refusing posts too short to work with and trimming those past the conversion limit on a word boundary.
* Records each conversion against the post so the dashboard shows what has already been reused.
* Distinct, actionable handling for invalid keys, exhausted accounts, rate limits, server faults and network failures.

= 0.2.0 =
* Archive scanner and opportunity dashboard, scoring every published post out of 100.
* Sorting, filtering and paging, with batched scanning for large archives.
* Works entirely offline, with no account required.

= 0.1.0 =
* Initial release.
* Settings page storing the Repagio API key and API base URL.
* API client with distinct, readable handling for invalid keys, missing endpoints, rate limits and server errors.
* Connection test that reports your account's plan and remaining generations.

== Upgrade Notice ==

= 0.7.0 =
Repagify is now Repagio. Your settings and conversion history carry over. If the plugin shows as deactivated after updating, activate Repagio again from the Plugins screen.

= 0.6.0 =
Adds Plugins list links, a working View details modal, and updates served from GitHub releases so auto-updates can be enabled.

= 0.5.0 =
Licensing, disclosure and translation housekeeping. No functional change.

= 0.4.0 =
Shows your Repagio account's plan and remaining generations, and checks before opening the generate dialog.

= 0.3.0 =
Adds the generation flow. Turn a published post into a blog post, LinkedIn post, X thread or newsletter.

= 0.2.0 =
Adds the archive scanner and opportunity dashboard. Works with no account.

= 0.1.0 =
First release. Connects your site to your Repagio account.
