# SportsPress Announcer

[![CI](https://github.com/grevory/sportspress-announcer/actions/workflows/ci.yml/badge.svg)](https://github.com/grevory/sportspress-announcer/actions/workflows/ci.yml)

Automatically posts game results and standings from your SportsPress site to your league's Discord (and more).

## The problem

Rec leagues run on two systems that don't talk to each other:

1. **The website** (SportsPress) — source of truth for fixtures, results, and standings.
2. **The group chat** (Discord, Slack, etc.) — where players actually hang out and ask "who won?"

Today the bridge is a human. Someone copies the score into chat by hand, or nobody does. SportsPress Announcer eliminates that step.

## How it works

1. A SportsPress event result is published or updated.
2. The plugin reads the teams, score, and competition from SportsPress.
3. A formatted message is POSTed to your configured webhook URL.

No OAuth. No login flow. Just paste a webhook URL and it runs itself.

## Free vs. Pro

### Free (WordPress.org)
- Discord support
- One webhook / one channel
- Basic result message: "Final: Sharks 5, Eels 3"
- Manual on/off per competition

### Pro
- Slack and additional platforms
- Multiple channels — route each division to its own channel
- Updated standings table posted after each result
- Custom message templates (mentions, emojis, logos, event page links)
- Scheduled "this week's fixtures" digest
- Priority support

**Pro pricing: ~$30–50/year per site.**

## Installation (development)

1. Clone this repo into your WordPress plugins directory:
   ```
   wp-content/plugins/sportspress-announcer/
   ```
2. Activate **SportsPress Announcer** in the WordPress admin under Plugins.
3. Go to **Settings → SportsPress Announcer**.
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
sportspress-announcer/
├── sportspress-announcer.php   # Main plugin file, hooks bootstrap
├── includes/
│   ├── class-spa-event-handler.php   # Detects result saves, extracts data
│   ├── class-spa-message-formatter.php  # Builds the message string
│   └── class-spa-webhook-discord.php    # POSTs to Discord webhook
├── admin/
│   └── class-spa-settings.php          # Settings page
└── assets/
    ├── css/
    └── js/
```

## Roadmap

- [x] MVP: Discord result announcer (free)
- [ ] Standings table after each result (Pro)
- [ ] Slack support (Pro)
- [ ] Multiple channels per competition (Pro)
- [ ] Custom message templates (Pro)
- [ ] Weekly fixtures digest (Pro)
- [ ] Mobile score-entry companion (future)

## License

GPLv2 or later — required for WordPress.org distribution.
