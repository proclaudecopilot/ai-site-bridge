# Site Bridge API

Base URL: `https://<site>/wp-json/site-bridge/v1` (or `https://<site>/?rest_route=/site-bridge/v1/...` without pretty permalinks).

Auth: header `X-Site-Bridge-Token: <token>` (or `Authorization: Bearer <token>`). Every response is JSON:

```json
{ "ok": true, "count": 3, "data": ... }
```

Errors follow the WordPress REST shape: `{ "code": "...", "message": "...", "data": { "status": 401 } }`. Codes: `site_bridge_disabled` (403), `site_bridge_no_token`/`site_bridge_bad_token` (401), `site_bridge_ip` (403), `site_bridge_rate` (429), `site_bridge_feature_off` (403).

The `GET` routes are read-only; secrets (passwords, salts, keys, tokens, licenses) are replaced with `[redacted ...]` wherever they would appear. The `POST /write/*` routes **do** change the site and are available only when the `write` feature is enabled in settings (and `write_files` for `/write/file`). Every write snapshots the previous value and returns an `undo_id` — see [Write routes](#write-routes).

## Routes

### `GET /`
Route list, plugin version, which optional features (`files`, `sql`, `render`) are enabled.

### `GET /info`
`site` (urls, locale, timezone, environment, permalinks), `versions` (WP, PHP, MySQL), `server` (memory, time limits, error_log, disabled functions, extensions), `debug` (WP_DEBUG & friends), `paths` (ABSPATH, content dir, debug.log path + exists + size, file scope), `db` (prefix, name, host), `theme`, `plugins` (active only), `mu_plugins`, `dropins`, `builders` (Oxygen/Breakdance/Elementor/Bricks version constants), `post_types` (counts by status), `taxonomies`, `users_total`, `errors_captured`.

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

## Write routes

All require the `write` feature (Settings → Site Bridge → „Разреши писане“) on top of the usual token/IP/rate checks; without it they return `site_bridge_feature_off` (403). Parameters can be sent as a JSON body or query string. Every successful write returns `{ "ok": true, "wrote": true, "undo_id": "w...", "data": {...} }`; replay the `undo_id` through `POST /write/undo/{id}` to revert it. All writes are also listed under „Журнал на промените“ in the admin screen with an **Undo** button.

**Value encoding** (for `/write/meta` and `/write/option`): by default the `value` is stored as given. `json=1` runs `json_decode` on a string value first (so you can store arrays/objects, e.g. `_elementor_data`). `raw=1` stores the exact string without any decoding. Arrays sent directly in a JSON body are used as-is. WordPress serializes arrays automatically on save.

### `POST /write/post`
Create a post. Fields: `post_title`/`title`, `post_content`/`content`, `post_excerpt`, `post_status`/`status` (default `draft`), `post_type`/`type` (default `post`), `post_name`/`slug`, `post_parent`, `menu_order`, `post_author`, `post_date`, `page_template`, plus optional `meta` (object of key→value). Returns `id`, `edit_link`, `link`. Undo trashes the created post.

### `POST /write/post/{id}`
Update an existing post's fields (same field names as create, except `type`/`author`). Only the fields you send change; the previous values of exactly those fields are snapshotted. Returns `id`, `changed`.

### `DELETE /write/post/{id}`
Trash the post (restorable). `?force=1` deletes permanently (**not** restorable). Returns `id`, `forced`, `restorable`.

### `POST /write/meta`
Body `{ "post_id", "key", "value", "json": 1, "raw": 1, "delete": 1 }`. Sets or (with `delete`) removes one post meta key. Snapshots every prior value of that key so undo restores it exactly. This is the route for builder templates: `key=_elementor_data value=<json> json=1`, `key=_wfocu_setting`, `key=_funnel_steps`, Oxygen's `key=ct_builder_json`, etc.

### `POST /write/option`
Body `{ "name", "value", "json": 1, "raw": 1, "autoload": true|false, "delete": 1 }`. Sets or removes one option. The plugin's own options (`site_bridge_settings`, `site_bridge_log`, `site_bridge_write_log`, `site_bridge_errors`) are refused with `sb_protected` (403).

### `POST /write/code-block`
Body `{ "post_id", "element_id", "php", "css", "js", "meta_key" }`. Replaces only the code parts you send on the Oxygen code block whose id is `element_id` (find it in `/oxygen/code-blocks`). Handles `ct_builder_json` (JSON tree) and `ct_builder_shortcodes` (re-encodes base64); pass `meta_key` to target one, otherwise both are tried. Returns `sb_block_not_found` (404) if the id is not present. For non-Oxygen builders, edit the raw meta via `/write/meta` instead.

### `POST /write/file` (feature: write_files)
Body `{ "path", "content" }`. Writes a file inside the allowed scope (same roots and deny-list as `/file`; `..` rejected; max 5 MB). Creates the file if the parent directory exists. Returns `path`, `relative`, `bytes`, `created`, `md5`. Undo restores the previous content, or deletes the file if it did not exist before.

### `POST /write/undo/{id}`
Revert an earlier write by its `undo_id`. Fails with `sb_already_undone` (409), `sb_undo_omitted` (410, snapshot was >1 MB and dropped), `sb_undo_impossible`/`sb_undo_gone` (410) where a revert is not possible.

## `bin/sb` helper

```
bin/sb <site> <endpoint> [key=value ...]   # GET with query params
bin/sb <site> sql "SELECT ..."             # POST /sql
bin/sb <site> render "wp-admin/..." [as=guest] [format=html]
bin/sb <site> file <path> | ls [<path>] | log [lines] [grep=..] | post <id> | hooks <hook> | options <name>
bin/sb <site> errors-clear
bin/sb list

# writing (needs the "write" feature on):
bin/sb <site> set-meta post_id=123 key=_elementor_data value=@layout.json json=1
bin/sb <site> update-post 123 content=@page.html status=publish
bin/sb <site> new-post type=wfocu_offer title="Offer" status=draft
bin/sb <site> set-option name=foo value=@data.json json=1
bin/sb <site> code-block post_id=123 element_id=abc php=@block.php
bin/sb <site> write-file plugins/foo/bar.php content=@bar.php
bin/sb <site> trash-post 123 [force=1]
bin/sb <site> undo <write-id>
```

`value=@file` / `content=@file` read a local file into the request body. Write commands need `jq`.

Config: env `SB_<SITE>_URL` + `SB_<SITE>_TOKEN` (site name upper-cased, `-`/`.` → `_`), or `~/.config/site-bridge/sites.json` (`{"shrine": {"url": "...", "token": "..."}}`, override with `SB_CONFIG`). `SB_PLAIN_PERMALINKS=1` switches to `?rest_route=`. `--raw` disables jq formatting; string payloads (`file`, `render`) print as plain text with the metadata on stderr.

## Snippet for other repos' CLAUDE.md

```markdown
## Live site access (Site Bridge)
The production/staging sites run the Site Bridge plugin (https://github.com/proclaudecopilot/ai-site-bridge), a REST API.
Credentials are environment secrets: `SB_<SITE>_URL` and `SB_<SITE>_TOKEN`.
Call it with `curl -H "X-Site-Bridge-Token: $SB_<SITE>_TOKEN" "$SB_<SITE>_URL/wp-json/site-bridge/v1/<route>"`
or clone the bridge repo and use `bin/sb <site> <route>`.
Start with `/info` and `/oxygen`; use `/post/{id}` for raw meta, `/log?grep=Fatal` and `/errors` for errors,
`/render` with `{"path":"wp-admin/admin.php?page=..."}` to see an admin screen as the user sees it,
and `/sql` for any SELECT. Never assume how a builder stores data on a site: check `/meta/keys?like=ct_%` first.
The GET routes are read-only. When the user turns on the "write" feature, you can also change the site via
`/write/meta` (post meta, e.g. `_elementor_data`), `/write/option`, `/write/post`, `/write/code-block` and
`/write/file`; GET `/` shows whether writing is enabled. Every write returns an `undo_id` you can replay through
`/write/undo/{id}`. Read the current value before overwriting it, and tell the user what you changed.
```
