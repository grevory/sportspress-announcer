=== SportsPress Announcer ===
Contributors: grevory
Tags: sportspress, discord, announcements, sports, webhooks
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically posts game results from SportsPress to Discord when event results are published.

== Description ==

Rec leagues run on two systems that don't talk to each other: the website (SportsPress) and the group chat (Discord). Today the bridge is a human — someone copies the score into chat by hand, or nobody does. SportsPress Announcer eliminates that step.

**How it works**

1. A SportsPress event result is published or updated.
2. The plugin reads the teams, score, and competition from SportsPress.
3. A formatted embed is POSTed to your configured Discord webhook.

No OAuth. No login flow. Paste a webhook URL and it runs itself.

**Features**

* Automatic Discord announcements when results are saved
* Rich embed with team names, score, competition, and match outcome colour
* Optional brand colour per team (shown as the embed sidebar colour)
* Configurable SportsPress result column (default: `goals`)
* Upcoming fixtures digest — admin notice with copy button and optional Discord push
* Scheduled digest — send the week's fixtures to Discord automatically
* Duplicate-announcement guard (won't re-post if the score hasn't changed)

== Installation ==

1. Upload the `sportspress-announcer` folder to `/wp-content/plugins/`.
2. Activate **SportsPress Announcer** in the WordPress admin under Plugins.
3. Go to **Settings → SportsPress Announcer**.
4. Paste your Discord channel's webhook URL and save.

Results will now post to Discord automatically when a SportsPress event result is saved.

== Frequently Asked Questions ==

= Where do I get a Discord webhook URL? =

Open your Discord server, go to a channel's settings → Integrations → Webhooks → New Webhook. Copy the URL and paste it into the plugin settings.

= The score isn't posting — what should I check? =

Make sure the event post status is **Published** and that a score has actually been entered in the SportsPress result columns. Use the **Send Test Message** button to confirm the webhook URL is valid.

= My site uses a custom result column, not "goals". =

Go to **Settings → SportsPress Announcer → SportsPress → Score Column** and enter the slug of your column (e.g. `points` or `runs`). This must match the column key shown in SportsPress → Result Columns.

= Will it re-post every time I update the event? =

No. The plugin stores a hash of the last announced score and only posts when the score changes.

== Screenshots ==

1. Settings page — Discord webhook URL, score column, and digest options.
2. Example Discord embed showing team names, score, and competition.

== Changelog ==

= 0.1.0 =
* Initial release: Discord result announcements, upcoming fixtures digest, scheduled digest, brand colour per team, configurable score column.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
