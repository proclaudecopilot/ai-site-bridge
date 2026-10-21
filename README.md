# Site Bridge

WordPress плъгин, който дава на AI асистент (Claude Code) **read-only** поглед върху сайта, докато работите по друг плъгин. Асистентът вика REST API с таен токен и чете каквото му трябва: версии, опции, постове и meta, Oxygen шаблони и код блокове, debug.log, файлове, SELECT заявки, дори рендерирана админ страница. **Нищо не се записва през него.**

Типичен случай: работим по `proof-shrine`, неговият Oxygen checker показва „0 блока“, а аз не виждам защо. С Site Bridge чета `/oxygen`, виждам в кой meta key реално стоят код блоковете на този сайт, и поправям плъгина без да гадая.

## Инсталация

1. Качете папката като `wp-content/plugins/site-bridge` (или zip на repo-то през „Добави плъгин“).
2. Активирайте го.
3. **Настройки → Site Bridge → „Генерирай токен“.** Токенът се показва само веднъж. Пази се само като SHA-256 хеш.
4. Копирайте токена и базовия URL (`https://сайт/wp-json/site-bridge/v1`).

Алтернативно токенът може да е константа в `wp-config.php`:

```php
define( 'SITE_BRIDGE_TOKEN', 'sb_...' );
define( 'SITE_BRIDGE_DISABLED', true ); // аварийно изключване, независимо от настройките
```

## Как го ползва Claude

В Claude Code (облачна сесия) токенът и URL се подават като environment secrets на средата, по два на сайт:

```
SB_SHRINE_URL=https://example.com
SB_SHRINE_TOKEN=sb_xxxxxxxx
```

После в сесията:

```bash
bin/sb shrine info
bin/sb shrine oxygen
bin/sb shrine oxygen/code-blocks
bin/sb shrine posts type=ct_template with_meta_keys=1
bin/sb shrine post 123
bin/sb shrine meta/keys like=ct_%
bin/sb shrine log lines=300 grep=Fatal
bin/sb shrine errors
bin/sb shrine file plugins/proof-shrine/proof-shrine.php
bin/sb shrine sql "SELECT ID, post_title FROM {prefix}posts WHERE post_type='ct_template'"
bin/sb shrine render "wp-admin/admin.php?page=proof-shrine"
bin/sb shrine hooks init
```

Или с чист `curl`:

```bash
curl -s -H "X-Site-Bridge-Token: $TOKEN" "https://example.com/wp-json/site-bridge/v1/info"
```

Пълното описание на маршрутите е в [docs/API.md](docs/API.md). Там има и готов абзац за `CLAUDE.md` на другите repo-та, за да знае асистентът, че мостът съществува и как да го вика.

## Какво може да чете

| Маршрут | Какво връща |
|---|---|
| `GET /` | списък с маршрути и кои функции са включени |
| `GET /info` | версии, php2shell статус (`security.php2shell`: закърпен ли е PHP срещу CVE-2026-109618 / CVE-2026-109619), debug константи, пътища, тема, активни плъгини, builders, брой постове по тип |
| `GET /plugins` | всички плъгини, активни/неактивни, чакащи обновления |
| `GET /constants` | дефинираните константи, тайните са скрити |
| `GET /options` | опции по име или по префикс, unserialize-нати, тайните скрити |
| `GET /posts`, `GET /post/{id}` | постове от всякакъв тип, с цялото meta и извлечени код блокове |
| `GET /meta/keys`, `GET /meta` | кои meta ключове съществуват и на кои постове са |
| `GET /oxygen`, `GET /oxygen/code-blocks` | Oxygen / Breakdance детекция и PHP/CSS/JS код блокове |
| `GET /users`, `GET /cron`, `GET /hooks` | потребители, cron задачи, callbacks на даден hook с файл и ред |
| `GET /errors` | fatal грешки, уловени от самия плъгин (и warnings, ако е включено) |
| `GET /health` | данните от Site Health |
| `GET /log` | опашката на debug.log, PHP error log или друг лог |
| `GET /file`, `GET /ls` | четене на файлове и директории в wp-content (или цялата инсталация) |
| `POST /sql` | един `SELECT`/`SHOW`/`DESCRIBE`/`EXPLAIN`, автоматичен `LIMIT` |
| `POST /render` | зарежда страница от сайта като администратор и връща текста, заглавието, notices и PHP грешки по нея |

## Сигурност

- Всичко е само за четене. Няма маршрут, който пише в базата или по диска.
- Токенът е 48 hex символа, пази се хеширан, върти се с един бутон.
- Пароли, salts, API ключове, лицензи и всичко с подобно име в опции, meta, константи и SQL резултати се подменят с `[redacted ...]`.
- `wp-config.php`, `.env`, `.pem`, `.key` не могат да се четат при никакъв обхват.
- SQL: само един statement, без коментари, без `INTO OUTFILE`, `LOAD_FILE`, `SLEEP`, `BENCHMARK`, заключвания.
- Лимит на заявките за минута, незадължителен IP allow-list (точен IP, CIDR, `*`).
- Лог на последните 100 заявки в админа.
- `/render` ползва временна (5 минути) auth бисквитка само за вътрешната заявка на сървъра към самия себе си. Може да се изключи отделно.
- Аварийно: `define('SITE_BRIDGE_DISABLED', true)` в `wp-config.php` или просто деактивирайте плъгина.

## Обновления

Плъгинът се обновява сам от този GitHub repo (клон `main`). WordPress проверява версията в хедъра на `site-bridge.php` на всеки 6 часа и при разлика показва обновление в **Плъгини → Обновления**, както при всеки друг плъгин. Бутон „Провери сега“ има в Настройки → Site Bridge.

За да излезе ново обновление на сайтовете: вдигнете `Version:` в `site-bridge.php` (и константата `SITE_BRIDGE_VERSION`), допишете ред в „Промени“ по-долу и push-нете в `main`. Хедърите `GitHub Plugin URI` / `Primary Branch` правят плъгина съвместим и с Git Updater, ако някой сайт го ползва.

## Промени

- 1.0.1 — php2shell (CVE-2026-109618 / CVE-2026-109619, критичен RCE в самия PHP, поправен в 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12): `GET /info` връща `security.php2shell` (състояние по номер на версията, `session.upload_progress.enabled`, `fatal_error_backtraces`, `post_max_size`), известие в админа + тест в Състояние на сайта, докато PHP не е обновен; ред със статуса на екрана Настройки. Плъгинът не може да закърпи PHP — само показва и съветва (backport без смяна на номера не се вижда).
- 1.0.0 — първа версия: info, plugins, constants, options, posts/post, meta, oxygen, users, cron, hooks, errors, health, log, file, ls, sql, render; админ екран; `bin/sb`; самообновяване от GitHub.

## Изисквания

PHP 7.4+, WordPress 5.8+. Не зависи от Oxygen; детекцията на builders е best effort и суровото meta винаги е достъпно през `/post/{id}`.

## Лиценз

GPL-2.0-or-later.
