# CLAUDE.md — SportsPress Announcer

WordPress plugin that announces SportsPress game results to Discord and Slack via webhooks, with scheduled daily/weekly digests. Some features are gated behind a Pro license check.

## Architecture

- **Delivery**: per-platform webhook classes (`includes/class-spa-webhook-discord.php`, `includes/class-spa-webhook-slack.php`) fed by formatters (`class-spa-message-formatter.php`). Event publication is handled by `includes/class-spa-event-handler.php`.
- **Digests**: `includes/digest/` holds the digest builder, formatter, and daily/weekly schedulers. Schedules use WP-Cron (`wp_schedule_event`), never custom cron tables.
- **Licensing**: Pro features are gated through `SPA_License::is_pro()` (`includes/licensing/class-spa-license.php`) — a homegrown check, **not** Freemius. There is no separate `pro/` directory; Pro and free code live together and the gate is the only boundary.
- **Loading**: classes are wired up with explicit `require_once` calls in `sportspress-announcer.php` (no autoloader).
- **Dependency**: SportsPress must be active. Check with `class_exists( 'SportsPress' )` on init and show an admin notice (not a fatal) if missing.
- **Data**: settings stored via the Settings API under a single option key (`spa_settings`). Current version is `0.1.0` (pre-1.0).

## Non-negotiable WordPress rules

These matter for WordPress.org plugin review — violations block or delist the plugin:

1. **Escape all output** (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), **sanitize all input** (`sanitize_text_field`, `esc_url_raw` for webhook URLs), **prepare all queries** (`$wpdb->prepare`).
2. Every admin form action needs a **nonce** and a **capability check** (`current_user_can( 'manage_options' )`).
3. Prefix everything: functions `spa_`, classes `SPA_`, hooks `spa/`, options `spa_`. No generic names.
4. All user-facing strings wrapped in i18n functions with the `sportspress-announcer` text domain.
5. Outbound HTTP only via `wp_remote_post` / `wp_safe_remote_post` — never cURL directly.

## Code style

**Design against smells, don't refactor them out later.** Before writing or editing PHP, design against the code-smell catalog (Fowler's _Refactoring_, Kent Beck, Robert C. Martin's _Clean Code_, Wake's _Refactoring Workbook_). Avoid introducing smells as the code is written rather than cleaning them up afterward. The `code-smells` skill remains available for an explicit review pass, and a pre-commit gate runs phpunit plus the smell check at commit time.

- PHP 7.4 minimum (match WordPress.org stated support); CI runs the suite on 7.4, local dev may be on 8.x — keep code compatible with both.
- WordPress Coding Standards enforced via PHPCS (`composer phpcs`, auto-fix with `composer phpcbf`). Ruleset in `.phpcs.xml.dist`.
- One class per file, `class-spa-*.php` naming.
- Webhook payloads built in dedicated formatter classes, unit-testable without WordPress loaded where possible.
- Fail soft: a failed webhook delivery logs (`SPA_Log`) and must never break result publishing for the site owner.

## Workflow

**Building.**

- Work in small, reviewable commits. One concern per commit.
- The pre-commit gate runs phpunit plus the smell check — a failed gate means fix the code, never bypass with `--no-verify`. Run `composer phpcs` and `composer test` proactively after significant edits rather than discovering failures at commit time. Note: legacy files can trip the smell hook.
- Invoke the `code-smells` skill for an explicit review pass before proposing any commit that touches more than one file, or any change to formatter or licensing/gating code. Fix flagged issues or explain why they're acceptable.

**Never do without asking:**

- Change the `spa_settings` schema (needs a migration routine).
- Add a new third-party dependency.
- Modify `readme.txt` stable tag or push to the wp.org SVN.

## Testing

- `composer test` — PHPUnit (`phpunit.xml.dist`). Runs against bare PHP, not wp-env, so mind PHP-version-specific behavior (e.g. `ReflectionMethod::setAccessible()` is required on < 8.1 and a deprecated no-op after).
- Digest features can be triggered manually via WP-Cron rather than waiting for the schedule.

## Deployment

Free build ships to WordPress.org SVN. Bump the version in the plugin header (`sportspress-announcer.php`), the `SPA_VERSION` constant, and the `readme.txt` stable tag **together**, and update the changelog. There is no deploy script checked in yet — deploy manually.

## Context for Claude

- Prioritize wp.org review compliance over cleverness — a rejected review costs weeks.
- The target user is a rec-league admin, not a developer. Settings UI copy should be plain-language; sensible defaults over configuration.
- Pro-vs-free boundary is `SPA_License::is_pro()`. Roughly: free = core announcing works end-to-end; Pro = scheduling/digest depth and multi-channel customization.
