# Site Bridge

WordPress плъгин, който дава на AI асистент (Claude Code) поглед върху сайта и — по избор — възможност да го **управлява**, докато работите заедно в чата. Асистентът вика REST API с таен токен и чете каквото му трябва: версии, опции, постове и meta, Oxygen шаблони и код блокове, debug.log, файлове, SELECT заявки, дори рендерирана админ страница.

По подразбиране плъгинът е **само за четене**. Има отделен превключвател „Разреши писане“, който отключва `/write/*` маршрутите: създаване и промяна на постове, meta, опции, Oxygen код блокове и файлове. Всяка промяна пази стойността отпреди и се вписва в журнал, така че се връща с един бутон (или `/write/undo/{id}`).

Типичен случай 1 (четене): работим по `proof-shrine`, неговият Oxygen checker показва „0 блока“, а аз не виждам защо. С Site Bridge чета `/oxygen`, виждам в кой meta key реално стоят код блоковете на този сайт, и поправям плъгина без да гадая.

Типичен случай 2 (писане): „Свали темплейтите от FunnelKit checkout за one-click upsell.“ Асистентът намира стъпките и офертите (`/posts?type=wfocu_offer`, `/post/{id}`), чете шаблона им (напр. `_elementor_data`, `_wfocu_setting`), и когато ги преработваме един по един в чата, качва новата версия обратно през `/write/meta` — без да отваряте редактора. Ако нещо се счупи, връщаме промяната от журнала.

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
| `GET /info` | версии, debug константи, пътища, тема, активни плъгини, builders, брой постове по тип |
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

## Какво може да променя (само при включено „Разреши писане“)

| Маршрут | Какво прави |
|---|---|
| `POST /write/post` | създава пост (title, content, status, type, meta) |
| `POST /write/post/{id}` | променя полета на пост (title, content, status, slug, parent…) |
| `DELETE /write/post/{id}` | праща пост в кошчето (с `?force=1` — окончателно) |
| `POST /write/meta` | задава или трие един meta ключ; `json=1` декодира JSON стойност, `raw=1` я пази буквално |
| `POST /write/option` | задава или трие една опция (с `autoload`) |
| `POST /write/code-block` | подменя PHP/CSS/JS на един Oxygen код блок по `element_id` |
| `POST /write/file` | записва файл на диска (само при отделния превключвател „файлове“) |
| `POST /write/undo/{id}` | връща предишната стойност на по-ранна промяна |

Всеки отговор от `/write/*` съдържа `undo_id`. Промените се виждат и се връщат и от екрана **Настройки → Site Bridge → „Журнал на промените“**.

```bash
bin/sb ansa post 123                                  # прочети текущия шаблон
bin/sb ansa set-meta post_id=123 key=_elementor_data value=@new-layout.json json=1
bin/sb ansa update-post 123 status=publish
bin/sb ansa undo w6f2a1c9...                           # ако трябва да се върне
```

## Сигурност

- По подразбиране всичко е само за четене. Писането се включва отделно и съзнателно.
- Писането минава през същия токен, IP allow-list и rate limit като четенето; изисква отделен флаг `allow_write` (а файловете — още един, `allow_write_files`).
- Всяка промяна пази стойността отпреди (до 1 MB на снимка) и е обратима през `/write/undo/{id}` или бутона в журнала. Окончателното (`force`) триене на пост не е обратимо.
- Собствените опции на плъгина (токен, настройки, журнали) не могат да се пренапишат през API-то.
- Писането на файлове спазва същия обхват като четенето; `wp-config.php`, `.env*`, `*.pem`, `*.key` остават забранени и за писане, а `..` в пътя се отхвърля.
- Изключвайте „Разреши писане“, когато не работите активно с асистента — тогава плъгинът пак е чист read-only мост.
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

- 2.0.0 — добавен слой за писане (по избор, изключен по подразбиране): `/write/post`, `/write/post/{id}`, `/write/meta`, `/write/option`, `/write/code-block`, `/write/file`, `/write/undo/{id}`. Всяка промяна е с резервна стойност и е обратима; нов „Журнал на промените“ в админа; нови превключватели „Разреши писане“ и „файлове“; write команди в `bin/sb` (`set-meta`, `update-post`, `new-post`, `set-option`, `code-block`, `write-file`, `trash-post`, `undo`).
- 1.0.0 — първа версия: info, plugins, constants, options, posts/post, meta, oxygen, users, cron, hooks, errors, health, log, file, ls, sql, render; админ екран; `bin/sb`; самообновяване от GitHub.

## Изисквания

PHP 7.4+, WordPress 5.8+. Не зависи от Oxygen; детекцията на builders е best effort и суровото meta винаги е достъпно през `/post/{id}`.

## Лиценз

GPL-2.0-or-later.
