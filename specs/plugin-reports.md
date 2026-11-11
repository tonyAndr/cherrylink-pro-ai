# Plugin lifecycle reports

Status: implemented 2026-11-11, pushed on `refactor/plugin-modernization`, and
released as plugin `v1.2.0`. Queue-only on the request path; the only network
call is a bounded WP-Cron event.

## Contract

The plugin reports two milestones to the indexing API:

- `plugin.activated` — activation, or the first run of this reporter on an
  already-active install.
- `plugin.upgraded` — a detected version change, carrying `from_version` and
  `plugin_version`.

Each report includes a UUID `event_key` and this allowlisted environment:

- WordPress, PHP, and database versions
- web server, OS family/kernel release
- theme name/version, locale, multisite flag

Never add paths, database credentials, API keys, IPs, arbitrary request paths,
or free-form text. `Support\Environment` is the only collector; do not send the
debug textarea or `W18T` output.

## Request isolation

| Path | Behavior |
|---|---|
| Activation | Writes a pending option and schedules one WP-Cron event. No HTTP. |
| Update detection | Local option/version check in the existing five-minute capability cron. No HTTP. |
| Ordinary admin/front-end/AJAX/save | No report work and no HTTP. |
| Report send | One batched POST per due attempt, bounded retries. |

- `Support\PluginReports` stores pending events in the non-autoloaded
  `cherrylink_plugin_reports` option.
- The existing `Capabilities::REFRESH_HOOK` tick runs `sweep()`: it detects a
  version change and recovers a pending send. It performs no HTTP.
- `send()` runs on the single `cherrylink_report_send` event, takes
  `RequestLock`, uses `Client::CONTEXT_BACKGROUND` with a 10-second timeout, and
  sends up to ten events in one request. It never sleeps or retries inside the
  HTTP client.
- Failures use bounded backoff (5 minutes, 30 minutes, 2 hours, 6 hours; five
  attempts). An unlicensed site leaves the event queued; it is scheduled once the
  licence becomes valid.
- Activation/update hooks must never call the API and must not let a reporting
  error escape.
- The plugin truncates each environment field to the API limits before sending;
  the API rejects over-limit or malformed values; the control adapter clears
  invalid values. The three layers are intentionally defense in depth, not an
  invitation to relax the API allowlist.
- The API rate-limits a site to 30 stored reports per rolling hour and rejects
  event timestamps more than five minutes in the future.

## Web environment snapshot

Cron and WP-CLI run under PHP CLI and would otherwise report `web_server=cli` and
the CLI PHP version. `admin_init` stores the web-request environment once per
plugin version in `cherrylink_web_environment`; background reports prefer that
snapshot. If no snapshot exists, the collector falls back to the current context.
If a plugin upgrade is detected in cron before any admin request refreshes the
snapshot, the upgrade report can carry the previous version's (usually identical)
runtime snapshot; this is cosmetic and is refreshed on the next admin load.

## API

`POST /v1/client-events` is tenant-authenticated via the existing license/domain
headers. The server derives `client_id`, rejects unknown/free-form fields, limits
a batch to ten, rate-limits to 30 stored reports per site per rolling hour, and
is idempotent on `(client_id, event_key)`. The API stores the milestone in
`ops_activity` and the runtime snapshot in `ops_plugin_reports`;
`/admin/v1/sites` and `/admin/v1/client-activity` expose the latest values.
The shareable validation vocabulary lives in `api/plugin_report_schema.py`.

## Tests

- `EnvironmentTest` pins the allowlist and control-character bounds.
- `ApiClientTest` pins the report payload/URL/timeout.
- API tests cover payload validation, tenant derivation, and idempotent replay.
- Manual wp-dev check must show zero `/v1/client-events` calls during activation
  and an ordinary admin load, then exactly one batched call from cron.
