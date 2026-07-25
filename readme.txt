=== Announcer for SportsPress ===
Contributors: grevory
Tags: sportspress, discord, slack, announcements, webhooks
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically posts SportsPress game results to Discord and Slack, with fixture digests, a weekly recap, and a shortcode/block that shows the latest results and upcoming games right on your site.

== Description ==

Rec leagues run on two systems that don't talk to each other: the website (SportsPress) and the group chat (Discord or Slack). Today the bridge is a human. Someone copies the score into chat by hand, or nobody does. Announcer for SportsPress eliminates that step.

**How it works**

1. A SportsPress event result is published or updated.
2. The plugin reads the teams, score, and competition from SportsPress.
3. A formatted message is posted to your configured Discord and Slack webhooks.

No OAuth. No login flow. Paste a webhook URL and it runs itself.

**Result announcements**

* Automatic Discord and Slack announcements when results are saved
* Rich Discord embed with team names, score, competition, and match outcome colour
* Optional brand colour per team (shown as the embed sidebar colour)
* Customizable message template with placeholders like {home}, {away}, {home_score}, and {event_url}, plus an emoji picker
* Per-competition channel routing: send each division to its own Discord or Slack channel
* Duplicate-announcement guard (won't re-post if the score hasn't changed)
* Configurable SportsPress result column (default: goals)

**Digests**

* Upcoming fixtures digest: preview the next 7 days in wp-admin, copy it, or send it to Discord and Slack with one click
* Auto-send the fixtures digest on a daily or weekly schedule
* Weekly Recap: results, standings with movement arrows, stat leaders, and upcoming games for each league, posted on a schedule
* Optionally publish the Weekly Recap as a post on your site

**Show recaps on your site**

* [spa_announcement] shortcode embeds a league recap in any post or page, showing the latest results and, optionally, upcoming games
* A matching "SportsPress Announcement" block for the block editor, with a live preview

**Admin tools**

* Dashboard tab with channel status, recent announcements, and one-click retry or resend
* Full announcement log with filtering and search
* Send Test Message buttons to verify each webhook
* Copyable recent-results digest with a Share to Facebook button

A failed webhook delivery is logged and never breaks publishing on your site.

Some advanced features (Slack announcements, the scheduled Weekly Recap, and Facebook tools) are planned to become part of a paid Pro tier. Pro is not for sale yet, so everything is currently unlocked and free to use.

**Requirements**

SportsPress must be installed and active. The plugin reads teams, results, leagues, and venues from SportsPress data.

== Installation ==

1. Upload the `announcer-for-sportspress` folder to `/wp-content/plugins/`.
2. Activate **Announcer for SportsPress** in the WordPress admin under Plugins.
3. Go to **Settings → Announcer for SportsPress → Channels**.
4. Paste your Discord (and optionally Slack) webhook URL and save.

Results will now post automatically when a SportsPress event result is saved.

== Frequently Asked Questions ==

= Where do I get a Discord webhook URL? =

Open your Discord server, go to a channel's settings → Integrations → Webhooks → New Webhook. Copy the URL and paste it into the plugin settings.

= Where do I get a Slack webhook URL? =

Create an Incoming Webhook for your Slack workspace (Slack: Apps → Incoming Webhooks, or a Workflow Builder webhook). Paste the URL into the Slack card on the Channels tab.

= The score isn't posting. What should I check? =

Make sure the event post status is **Published** and that a score has actually been entered in the SportsPress result columns. Use the **Send Test Message** button to confirm the webhook URL is valid.

= My site uses a custom result column, not "goals". =

Go to **Settings → Announcer for SportsPress → Templates → Score Column** and pick your column (e.g. Points or Runs). The list comes straight from SportsPress → Result Columns.

= Will it re-post every time I update the event? =

No. The plugin stores a hash of the last announced score and only posts when the score changes.

= How do I show a recap on my site? =

Use the `[spa_announcement league="your-league-slug"]` shortcode (optionally with `days="7"`), or add the **SportsPress Announcement** block and pick a league in the block settings.

= Does the scheduled digest need anything special? =

Schedules use WP-Cron, which runs on site traffic. On low-traffic sites, point a real server cron job at `wp-cron.php` for reliable delivery.

== Screenshots ==

1. Dashboard tab: channel status, recent announcements with resend, and the fixtures digest card.
2. Channels tab: Discord webhook setup with per-competition channel routing.
3. Templates tab: result and fixtures templates with placeholder chips and emoji picker.
4. Weekly Recap preview: league standings with the recap header and footer.

== Changelog ==

= 0.1.0 =
* Initial release.
* Discord and Slack result announcements with per-competition channel routing.
* Customizable result and fixtures message templates with placeholders and emoji picker.
* Upcoming fixtures digest with copy, one-click send, and scheduled auto-send.
* Weekly Recap digest: results, standings movement, stat leaders, and upcoming games per league, with optional publish-as-post.
* [spa_announcement] shortcode and SportsPress Announcement block for on-site recaps.
* Dashboard, announcement log with retry, per-team brand colours, and configurable score column.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
