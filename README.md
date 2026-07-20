# Announcer for SportsPress

[![CI](https://github.com/grevory/sportspress-announcer/actions/workflows/ci.yml/badge.svg)](https://github.com/grevory/sportspress-announcer/actions/workflows/ci.yml)

Automatically posts game results and standings from your SportsPress site to your league's Discord (and more).

## The problem

Rec leagues run on two systems that don't talk to each other:

1. **The website** (SportsPress) — source of truth for fixtures, results, and standings.
2. **The group chat** (Discord, Slack, etc.) — where players actually hang out and ask "who won?"

Today the bridge is a human. Someone copies the score into chat by hand, or nobody does. Announcer for SportsPress eliminates that step.

## How it works

1. A SportsPress event result is published or updated.
2. The plugin reads the teams, score, and competition from SportsPress.
3. A formatted message is POSTed to your configured webhook URL.

No OAuth. No login flow. Just paste a webhook URL and it runs itself.

## Free vs. Pro

Free covers the event ("a game happened"). Pro covers the ritual ("the weekly rhythm").

### Free (WordPress.org)
- Discord support
- Per-competition channel routing
- Score announcement on result save
- Upcoming fixtures digest — admin notice with manual "Send to Discord" push button
- **Weekly Digest preview** — generate and preview a full digest using real SportsPress data in wp-admin (no scheduling or posting)

### Pro
- **Weekly Digest** — scheduled, auto-posted recap: results, standings with movement arrows, configurable stat leaders (goals/assists/any SP stat)
- Slack support
- Publish digest as a WordPress post
- Multiple channels per competition
- Custom message templates
- Priority support

**One plugin, two tiers.** Pro is unlocked by a license key — no separate download required.

## Run locally

The quickest way to a working site is the [WordPress Playground CLI](https://github.com/WordPress/wordpress-playground), no Docker or local server stack needed. From the repo root:

```
npx @wp-playground/cli@latest start
```

That boots a throwaway WordPress with this plugin mounted from the current directory and logs you in as admin at the URL it prints. Then:

1. Install and activate **SportsPress** under Plugins → Add New (the dependency notice will remind you).
2. Seed a league, two teams, and an event with a result under SportsPress.
3. Go to **Settings → Announcer for SportsPress**, paste a Discord webhook URL, and save.

Useful variants:

```
npx @wp-playground/cli@latest start --wp=7.0     # verify against a specific WordPress version
npx @wp-playground/cli@latest start --php=7.4    # verify against the minimum supported PHP
```

Tests and coding standards run outside WordPress:

```
composer test     # PHPUnit
composer phpcs    # WordPress Coding Standards
```

## Installation (development, manual)

1. Clone this repo into your WordPress plugins directory:
   ```
   wp-content/plugins/announcer-for-sportspress/
   ```
2. Activate **Announcer for SportsPress** in the WordPress admin under Plugins.
3. Go to **Settings → Announcer for SportsPress**.
4. Paste your Discord webhook URL and save.

Results will now post to that channel automatically when a SportsPress event result is saved.

## Requirements

- WordPress 6.0+
- SportsPress 2.7+
- PHP 7.4+

## MVP scope

The first shippable version does exactly three things:

1. Hooks `save_post` on the `sp_event` post type.
2. Formats a plain-English result message.
3. POSTs it to one Discord webhook.

Everything else is Pro or a later iteration.

## Plugin structure

```
announcer-for-sportspress/
├── announcer-for-sportspress.php   # Main plugin file, hooks bootstrap
├── includes/
│   ├── class-spa-event-handler.php           # Detects result saves, extracts data
│   ├── class-spa-message-formatter.php       # Builds platform-agnostic messages
│   ├── class-spa-webhook-discord.php         # POSTs to Discord webhook
│   ├── class-spa-webhook-slack.php           # POSTs to Slack Incoming Webhook
│   ├── class-spa-digest-scheduler.php        # WP-Cron for upcoming-games digest
│   ├── class-spa-log.php                     # In-memory send log
│   ├── digest/
│   │   ├── class-digest-builder.php          # Queries SP data → DigestData (FREE)
│   │   ├── class-digest-formatter.php        # Renders DigestData → Discord/HTML (FREE)
│   │   └── class-digest-scheduler.php        # Weekly cron dispatch (PRO-gated)
│   └── licensing/
│       └── class-license.php                 # SPA_License::is_pro() — stub for now
├── admin/
│   ├── class-spa-settings.php                # Settings page + AJAX handlers
│   ├── class-digest-settings-tab.php         # Weekly Digest tab (locked/unlocked)
│   ├── class-spa-facebook-notice.php         # Admin notice: recent results (Facebook share)
│   ├── class-spa-upcoming-notice.php         # Admin notice: upcoming fixtures
│   ├── class-spa-upcoming-discord.php        # AJAX handler: manual Discord digest push
│   ├── class-spa-upcoming-slack.php          # AJAX handler: manual Slack digest push
│   └── class-spa-team-color.php              # Team brand color meta box
└── assets/
    ├── css/spa-admin.css
    └── js/spa-emoji-picker.js
```

### DigestData shape

`SPA_Digest_Builder::build()` returns:

```php
[
  'league_id'    => int,
  'period'       => ['start' => 'Y-m-d', 'end' => 'Y-m-d'],
  'results'      => [['home', 'away', 'home_score', 'away_score', 'competition', 'event_url', 'date'], ...],
  'standings'    => [['rank', 'name', 'played', 'points', 'movement' => 'up|down|same|new'], ...],
  'stat_leaders' => [['stat', 'label', 'players' => [['name', 'team', 'value'], ...]], ...],
  'upcoming'     => [...],   // SPA_Upcoming_Notice format, filtered by league
  'is_empty'     => bool,
]
```

Standings movement is diffed against a snapshot stored in `spa_digest_standings_snapshot_{league_id}`.
Last-sent timestamps per league are stored in `spa_digest_last_sent_{league_id}` for idempotency.

## Roadmap

- [x] MVP: Discord result announcer
- [x] Upcoming fixtures digest — admin notice with manual Discord push
- [x] Team brand colors in Discord embeds
- [x] Scheduled upcoming-games digest via WP-Cron
- [x] Weekly Digest builder + formatter (results, standings movement, stat leaders) — FREE preview
- [x] Weekly Digest scheduling + posting (Pro-gated, license stub)
- [x] Digest settings tab (locked/unlocked states)
- [ ] Wire real license-key validation (Freemius or custom EDD — TBD)
- [ ] Slack Weekly Digest formatter
- [ ] Post-success upsell notice linking to Digest tab
- [ ] Unit tests: standings diff, empty-week detection, Discord field truncation
- [ ] Mobile score-entry companion (future)

## License

GPLv2 or later — required for WordPress.org distribution.
