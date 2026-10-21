# Site Bridge API

Base URL: `https://<site>/wp-json/site-bridge/v1` (or `https://<site>/?rest_route=/site-bridge/v1/...` without pretty permalinks).

Auth: header `X-Site-Bridge-Token: <token>` (or `Authorization: Bearer <token>`). Every response is JSON:

```json
{ "ok": true, "count": 3, "data": ... }
```

Errors follow the WordPress REST shape: `{ "code": "...", "message": "...", "data": { "status": 401 } }`. Codes: `site_bridge_disabled` (403), `site_bridge_no_token`/`site_bridge_bad_token` (401), `site_bridge_ip` (403), `site_bridge_rate` (429), `site_bridge_feature_off` (403).

Everything is read-only. Secrets (passwords, salts, keys, tokens, licenses) are replaced with `[redacted ...]` wherever they would appear.

## Routes

### `GET /`
Route list, plugin version, which optional features (`files`, `sql`, `render`) are enabled.

### `GET /info`
`site` (urls, locale, timezone, environment, permalinks), `versions` (WP, PHP, MySQL), `security.php2shell` (CVE-2026-109618 / CVE-2026-109619: `state` patched|vulnerable|eol|unknown, `fixed_in`, `session_upload_progress`, `fatal_error_backtraces`, `post_max_size` — проверка само по номер на версията), `server` (memory, time limits, error_log, disabled functions, extensions), `debug` (WP_DEBUG & friends), `paths` (ABSPATH, content dir, debug.log path + exists + size, file scope), `db` (prefix, name, host), `theme`, `plugins` (active only), `mu_plugins`, `dropins`, `builders` (Oxygen/Breakdance/Elementor/Bricks version constants), `post_types` (counts by status), `taxonomies`, `users_total`, `errors_captured`.

### `GET /plugins`
All installed plugins: `file`, `slug`, `name`, `version`, `active`, `network`, `update` (new version if pending), `requires`.

### `GET /constants?search=`
User-defined constants (`get_defined_constants(true)['user']`), filtered by substring. Sensitive ones redacted.

### `GET /options`
- `?name=a,b,c` exact names, or
- `?search=prefix` (`%` allowed for a custom LIKE), `&limit=100` (max 500)
- `&raw=1` returns the stored string instead of unserialized/JSON-decoded value.

Each item: `name`, `autoload`, `bytes`, `value`.

### `GET /posts`
Params: `type` (comma list; default all types except revision), `status` (comma list; default `any` = not trash; `any` explicitly = every status incl. trash; `trash`), `search`, `meta_key` (only posts having it), `per_page` (50, max 200), `page`, `with_meta_keys=1` (adds `meta_keys: {key: bytes}` per post). Sorted by modified desc. Extra: `total`, `pages`.

### `GET /post/{id}`
Full post: fields, `link`, `edit_link`, `template`, `terms`, `content` (`content=0` to omit), `meta` (`meta=0` to omit; each key → `{bytes, value}` or list of those; `raw=1` to skip unserialize/JSON decode), and `code_blocks` when builder data is found (see `/oxygen/code-blocks`).

### `GET /meta/keys?like=ct_%&limit=200`
Distinct `meta_key` with post count and total bytes. Without `like`: top keys overall.

### `GET /meta?key=exact` or `?like=pattern%` (`&limit=200`)
Rows `post_id, meta_key, bytes, post_type, post_status, post_title`, plus `by_post_type` summary. Use it to see **where** a builder stores data.

### `GET /oxygen?limit=500`
`constants` (CT_VERSION, __BREAKDANCE_VERSION, …), `post_types` (builder CPTs with counts), `meta_keys` (every key matching `ct_%`, `%oxygen%`, `%breakdance%`, `oxy_%`, `_elementor%`, `_bricks%` with counts), `option_names` (oxygen/ct_/breakdance options with sizes), `posts` (every post carrying a known tree meta key, with the key and its size).

### `GET /oxygen/code-blocks?post=&limit=300&full=1`
Extracted code blocks across builder posts (or one `post`). Each: `post_id`, `post_title`, `post_type`, `post_status`, `meta_key`, `format` (`oxygen_json`, `oxygen_shortcodes`, `deep_scan`), `element_id`, `label`, `php`, `css`, `js`, `php_lines`. Code longer than 4000 chars is truncated unless `full=1`. Base64-encoded code (Oxygen shortcodes) is decoded.

Formats handled: Oxygen classic `ct_builder_json` (nodes named `ct_code_block`, options.original.code-php/css/js), Oxygen classic `ct_builder_shortcodes` (`[ct_code_block ct_options='…']`), and a generic deep scan for Breakdance / Oxygen 6 style trees (any key like `code-php`, `php_code`, `phpCode`, `php`). If a site stores blocks differently, `/post/{id}` shows the raw tree.

### `GET /users?search=&limit=100`
`id`, `login`, `display`, `email`, `roles`, `registered`.

### `GET /cron`
Scheduled events: `hook`, `next` (UTC), `in_sec`, `schedule`, `interval`, `args`; plus `disable_wp_cron`, `doing_cron_lock`.

### `GET /hooks?hook=init` / `GET /hooks?search=woocommerce`
With `hook`: every callback (`priority`, `accepted_args`, `callable`, `file`, `line`, `source` = `plugin:slug` | `theme:x` | `mu-plugin:x` | `core`). Without: hook names (filtered by `search`).

### `GET /errors` / `DELETE /errors`
Fatal errors captured by the plugin's shutdown handler (last 50): `time`, `fatal`, `type`, `message`, `file`, `line`, `source`, `url`, `method`, `is_rest`, `is_ajax`, `is_cron`. With "capture warnings" enabled in settings, warnings/notices are recorded too (max 20 per request). Note: a fatal thrown by a plugin that loads *before* Site Bridge may not be captured; use `/log` then.

### `GET /health?section=`
`WP_Debug_Data::debug_data()` sections (`wp-core`, `wp-server`, `wp-database`, `wp-constants`, `wp-filesystem`, `wp-plugins-active`, …) with private fields removed and secrets redacted.

### `GET /log` (feature: files)
`file=debug` (default: `WP_DEBUG_LOG` path or `wp-content/debug.log`), `file=php` (`ini_get('error_log')`), or a path inside the allowed scope. `lines=200` (max 5000), `grep=regex` (case-insensitive; falls back to literal). Returns the tail lines plus `path`, `exists`, `size`, `mtime`.

### `GET /file?path=` (feature: files)
Path relative to ABSPATH, wp-content or plugins dir (`plugins/foo/bar.php`, `wp-content/themes/x/functions.php`), or absolute. Whole file when ≤ 1 MB, else `from=1&lines=2000` paging (`next_from` in response). Returns `size`, `mtime`, `md5`, `total_lines`; `binary: true` for non-UTF-8. Directories return a listing. Scope is `wp-content` by default; "whole install" can be enabled in settings. `wp-config.php`, `.env*`, `*.pem`, `*.key` are always denied.

### `GET /ls?path=wp-content/plugins` (feature: files)
Directory entries: `name`, `type` (dir/file/link), `size`, `mtime`.

### `POST /sql` (feature: sql)
Body `{"query": "SELECT ...", "limit": 500}` (GET `?query=` also works). Allowed: one `SELECT` / `SHOW` / `DESCRIBE` / `EXPLAIN` / `WITH … SELECT`. Rejected: `;`, comments, `INTO OUTFILE/DUMPFILE`, `LOAD_FILE`, `SLEEP`, `BENCHMARK`, `FOR UPDATE`. `LIMIT <limit>` (max 2000) is appended when missing. `{prefix}` expands to the table prefix. Response: rows (`user_pass`, sensitive option/meta values redacted), `columns`, `count`, `ms`, `query` as executed. SQL errors come back as `sb_sql_error` with the message.

### `POST /render` (feature: render)
Body `{"path": "wp-admin/admin.php?page=my-plugin", "as": "admin|guest", "format": "text|html", "max": 200000}`. `path` can be a wp-admin path, a front-end path, or a full URL on the same host. The server performs a loopback HTTP request to itself with a 5-minute auth cookie for the configured admin user and returns:

- `data`: page text (admin: only `#wpbody-content`, scripts/styles stripped, tables tab-separated, inputs shown as `[value]`) or raw HTML
- `status`, `title`, `notices` (admin notices text), `php_errors` (Warning/Notice/Fatal strings found in the output), `login_page` (true if the cookie was not accepted), `truncated`, `ms`

Hosts that block loopback requests return `sb_render_http` (502).

## `bin/sb` helper

```
bin/sb <site> <endpoint> [key=value ...]   # GET with query params
bin/sb <site> sql "SELECT ..."             # POST /sql
bin/sb <site> render "wp-admin/..." [as=guest] [format=html]
bin/sb <site> file <path> | ls [<path>] | log [lines] [grep=..] | post <id> | hooks <hook> | options <name>
bin/sb <site> errors-clear
bin/sb list
```

Config: env `SB_<SITE>_URL` + `SB_<SITE>_TOKEN` (site name upper-cased, `-`/`.` → `_`), or `~/.config/site-bridge/sites.json` (`{"shrine": {"url": "...", "token": "..."}}`, override with `SB_CONFIG`). `SB_PLAIN_PERMALINKS=1` switches to `?rest_route=`. `--raw` disables jq formatting; string payloads (`file`, `render`) print as plain text with the metadata on stderr.

## Snippet for other repos' CLAUDE.md

```markdown
## Live site access (Site Bridge)
The production/staging sites run the Site Bridge plugin (https://github.com/proclaudecopilot/ai-site-bridge), a read-only REST API.
Credentials are environment secrets: `SB_<SITE>_URL` and `SB_<SITE>_TOKEN`.
Call it with `curl -H "X-Site-Bridge-Token: $SB_<SITE>_TOKEN" "$SB_<SITE>_URL/wp-json/site-bridge/v1/<route>"`
or clone the bridge repo and use `bin/sb <site> <route>`.
Start with `/info` and `/oxygen`; use `/post/{id}` for raw meta, `/log?grep=Fatal` and `/errors` for errors,
`/render` with `{"path":"wp-admin/admin.php?page=..."}` to see an admin screen as the user sees it,
and `/sql` for any SELECT. Never assume how a builder stores data on a site: check `/meta/keys?like=ct_%` first.
```
