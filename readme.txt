=== SEOmatic – SEO Audit & Search Console Insights ===
Contributors: seomatic
Tags: seo, seo audit, search console, ai, content optimization
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-click SEO audit of every post and page, plus AI agents that fix what your Search Console data says matters. Runs locally, no account needed.

== Description ==

**Scan your whole site in one click — free, instant, and nothing leaves your server.**

SEOmatic audits every published post and page for the problems that actually cost search traffic:

* **Titles that get cut off in Google** (over 60 characters) or are missing entirely
* **Missing or overlong meta descriptions** — read from Yoast, Rank Math, All in One SEO, or SEOPress, whichever you use
* **Thin posts** under 300 words that rarely rank
* **Images without alt text**
* **Published pages accidentally marked noindex** — invisible to Google for no reason

You get a prioritized **"fix these first"** list with one-click edit links. The audit runs entirely on your own server: no account, no signup, and no data sent anywhere.

**Then connect Google Search Console (free, read-only) and the audit gets teeth:**

* Which keywords sit one step from page 1
* Which pages rank well but never get clicked — your titles are the problem
* Which of your pages compete with each other for the same query
* Whether a traffic dip is real or just seasonal

**And when you want the fixes done for you:** on paid [SEOmatic](https://seomatic.ai) plans, AI agents write and refresh posts, rewrite titles and metas, and add internal links. Every change is a diff you approve first, with one-click revert. Nothing ever changes your site without your approval.

The connection uses WordPress core's own Application Passwords - SEOmatic never sees your password. The plugin can also notify SEOmatic the moment you publish or delete content, so its picture of your site is never stale.

**A SEOmatic account is required** (free to create, no card). The free plan includes connecting your site and Search Console plus all analysis and chat features, with a monthly question quota. Applying fixes to your site is part of paid plans, from $99/month. See [pricing](https://seomatic.ai/pricing).

== External services ==

This plugin communicates with the SEOmatic service (app.seomatic.ai), operated by SEOmatic, **only after you connect**:

* When you save a SEOmatic API key, the plugin verifies it against SEOmatic's API and shows your connection status (the key and your site's URL are sent).
* When you configure the freshness webhook URL, publishing/deleting content sends SEOmatic the post ID and event type (no content).
* The plugin sends nothing anywhere before you connect.

SEOmatic's [Terms](https://seomatic.ai/terms) and [Privacy Policy](https://seomatic.ai/privacy) apply to the connected service.

== Installation ==

1. Install and activate the plugin.
2. Open **SEOmatic** in the admin menu and click **Scan my site** — the audit runs locally, no account needed.
3. Optional: click **Connect Google Search Console** to rank the findings by real traffic impact.
4. Optional: connect a SEOmatic account (Settings) for the AI agents, the dashboard status card, and the freshness webhook.

== Frequently Asked Questions ==

= Does the audit send my content anywhere? =

No. The scan runs entirely on your own server with WordPress's own APIs. The results are stored in your database and shown only in your wp-admin. Nothing is transmitted until you explicitly connect an account or Search Console.

= Do I need a SEOmatic account to use the audit? =

No. The site audit is fully functional with no account, forever. An account (free) adds the Search Console insights and lets you ask questions about your data; paid plans add the AI agents that apply fixes with your approval.

= Does it work with Yoast, Rank Math, All in One SEO, or SEOPress? =

Yes. The audit reads titles, meta descriptions, and noindex flags from all four, whichever is active. It complements them: they score the post you are editing, SEOmatic ranks your whole site by what to fix first.

= Does SEOmatic get my WordPress password? =

No. The connection uses WordPress core's Application Passwords: your site issues a scoped credential on a screen inside your own wp-admin, and you can revoke it there at any time.

= What is free and what is paid? =

Connecting, Search Console analysis, and chat questions are free (monthly quota). Agents applying fixes to your site are paid, always with your approval and one-click revert.

= Does the plugin slow my site down? =

No. It adds no front-end code at all. The only requests it makes are a cached hourly status check in wp-admin and an optional non-blocking ping when you publish or delete content.

== Changelog ==

= 1.1.0 =
* New: AI-crawler analytics — see visits from GPTBot, ChatGPT-User, PerplexityBot, ClaudeBot and other AI bots in your SEOmatic dashboard. Paste the endpoint from SEOmatic (AI Visibility page); human visitors are never logged, and the ping is non-blocking so your site is never slowed.

= 1.0.0 =
* Initial release: one-click local SEO audit (titles, meta descriptions, thin content, alt text, accidental noindex) with a prioritized fix-first list.
* One-click connect to SEOmatic via Application Passwords, dashboard status card, content-freshness pings.
* Freshness pings are limited to public post types, and deleting a post sends one ping instead of one per revision.
