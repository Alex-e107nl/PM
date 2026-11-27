# e107 Private Messenger (PM) — Enhanced Edition

**A hardened, modern 3-pane private messaging plugin for e107 2.x — with at-rest AES-256-GCM encryption, server-side full-mailbox search and Bootstrap numbered pagination.**

---

## Warning / Disclaimer

Please note! Although we have made every effort and conducted extensive testing, 
you must ALWAYS create a system backup before applying our code. We are under no 
circumstances responsible for any damage or data loss resulting from the use of 
our code. It is impossible to test every possible combination of systems, CMS 
versions, servers, and PHP versions currently in use.

---

## Badges

[![e107](https://img.shields.io/badge/e107-2.x-1f6feb)](https://e107.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%20%7C%208.x-777bb3)](https://www.php.net)
[![Database](https://img.shields.io/badge/MySQL-5.7%20%7C%20MariaDB-4479a1)](https://mariadb.org)
[![UI](https://img.shields.io/badge/UI-Bootstrap_5-7952b3)](https://getbootstrap.com)
[![Encryption](https://img.shields.io/badge/crypto-AES--256--GCM-2ea44f)](https://en.wikipedia.org/wiki/Galois/Counter_Mode)
[![Version](https://img.shields.io/badge/version-3.3-success)](#)
[![License](https://img.shields.io/badge/license-GPL--2.0-blue)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

---

## Key Features

- **AES-256-GCM encryption at rest** — every message subject and body is encrypted before it touches the database (`base64(version|nonce|tag|ciphertext)`, 12-byte nonce, 128-bit auth tag).
- **Versioned key management** — keys are auto-generated on install, stored in a web-protected plugin folder, and can be **rotated** (re-encrypts the whole mailbox in batches).
- **Legacy migration** — one call re-encrypts pre-existing plaintext messages (`migrateEncryptMessages()`); read paths transparently decrypt mixed (legacy + encrypted) mailboxes.
- **Modern 3-pane interface** — folder navigation, message list, and reader side by side (Inbox / Outbox / Drafts / Blocked), styled with Bootstrap 5.
- **Server-side search across all pages** — `?inbox&pm_search=term` searches subject, body and participant name in the *entire* mailbox (decrypt-then-filter), with a results count, clear button and search-aware pagination.
- **Bootstrap numbered pagination** — `‹ 1 2 3 … 7 ›` pager (no dropdown), and opening a message keeps the list on the page where that message lives.
- **Drafts** — per-user draft storage (`private_msg_draft` table) with save, resume and delete.
- **Blocked senders** — per-user block list with admin-friendly management.
- **Attachments with protected storage** — per-user attachment directories, web-access deny rules (auto-applied to legacy sites on upgrade), and queued-attachment handling.
- **Class-based permissions** — who may send, receive, attach, send-all and send multi-recipient is controlled through e107 user classes.
- **Notifications & receipts** — configurable notify/receipt classes with per-send options.
- **Admin UI on `e_admin_ui`** — full CRUD for messages with encryption normalization on create/update and safe plaintext rendering in the backend.
- **Internationalization** — English and Dutch included (front + admin language files).

---

## Tech Stack

| Layer | Technology |
|---|---|
| CMS | [e107](https://e107.org) 2.x (`e_admin_dispatcher`, `e_admin_ui`, shortcode/parser pipeline) |
| Language | PHP 7.4+ (tested on PHP 8.3), no Composer dependencies |
| Database | MySQL 5.7+ / MariaDB 10.3+ (`#private_msg`, `#private_msg_block`, `#private_msg_draft`) |
| Cryptography | OpenSSL (`openssl_encrypt` / `openssl_decrypt`), AES-256-GCM, `random_bytes()` nonces |
| Frontend | Bootstrap 5 utilities, Font Awesome, vanilla JS (live search debounce, no frameworks) |

---

## Prerequisites

- **e107 2.x** with a working plugin system
- **PHP ≥ 7.4** with the `openssl` and `mbstring` extensions enabled
- **MySQL 5.7+ / MariaDB 10.3+**
- A Bootstrap 5 based e107 theme (recommended — the UI and numbered pager are built on it)
- Web server with `.htaccess` support (Apache) or equivalent deny rules (the plugin writes them for its key/attachment folders)

---

## Installation

### 1. Fresh install

1. Copy this folder to `e107_plugins/pm/` on your server.
2. In the e107 admin panel, go to **Plugins** and press **Install** next to *Private Messenger*.
3. During install the plugin will:
   - create/upgrade the `private_msg`, `private_msg_block` and `private_msg_draft` tables,
   - **auto-generate the AES-256-GCM key** and protect its folder from web access,
   - apply deny rules to existing attachment directories.
4. Configure the plugin under **Plugins → Private Messenger → Configure** (see [Configuration](#configuration)).

### 2. Upgrading from the stock e107 PM plugin

> ⚠️ **Important:** an e107 "plugin update" for the PM plugin will **overwrite this customized version** with the stock code. Before running any e107 update that touches the PM plugin, keep a backup of the whole folder (e.g. `backup_PM_original/`).

1. Back up the existing `e107_plugins/pm/` folder.
2. Replace it with this version (your database tables are untouched).
3. Re-run the install/upgrade in the e107 admin (the version bump in `plugin.xml` triggers the post-install routines: key creation, draft table, attachment protection).
4. If your mailbox still contains **plaintext legacy messages**, run a one-time migration:

   ```php
   require_once e_PLUGIN . 'pm/pm_class.php';
   $pm = new private_message();
   $pm->migrateEncryptMessages(200); // encrypts up to 200 rows per call; loop until it returns 0
   ```

5. Purge the e107 caches (Admin → Tools → Purge caches).

---

## Configuration

There are no environment variables — all settings are **e107 plugin prefs**, managed in **Admin → Plugins → Private Messenger → Configure** (defaults from `plugin.xml`):

| Setting | Default | Description |
|---|---|---|
| `perpage` | `10` | Messages per page in the list pane |
| `pm_class` | `e_UC_MEMBER` | User class allowed to use the PM system |
| `send_to_class` | `e_UC_MEMBER` | User class a message may be sent to |
| `notify_class` | `e_UC_ADMIN` | Recipients in this class trigger a "new PM" notification |
| `receipt_class` | `e_UC_MEMBER` | Recipients in this class get a delivery receipt |
| `attach_class` | `e_UC_ADMIN` | User class allowed to attach files |
| `attach_size` | `500` | Max attachment size (KB) |
| `sendall_class` | `e_UC_ADMIN` | User class allowed "send to all" |
| `multi_class` | `e_UC_ADMIN` | User class allowed multi-recipient sends |
| `pm_limits` | `0` | Enable per-class send limits (`1`) |
| `pm_max_send` | `100` | Max sends per period when limits are enabled |
| `maxlength` | `0` | Max message body length in characters (`0` = unlimited) |
| `allow_userclass` | `1` | Allow sending to whole user classes |
| `popup` / `popup_delay` | `0` / `3` | Unread-message popup (enabled / seconds) |
| `read_timeout` / `unread_timeout` | `0` | Auto-mark read/unread after N minutes (`0` = off) |
| `animate` | `1` | Animate folder counts |
| `vip_class` | `e_UC_NOBODY` | VIP recipients (highlighted) |

**Encryption key** — no configuration needed. The key is auto-created on install, versioned (`v1`, `v2`, …), and lives in a web-denied folder inside the plugin. Status (supported / exists / version / path) is shown on the admin screen via `cryptoStatus()`.

---

## Usage

### Frontend routes

The PM page is a single entry point; the route lives in the query string:

| Route | Action |
|---|---|
| `?inbox` | Inbox, page 1 |
| `?inbox.20` | Inbox, record offset 20 (page 3 with `perpage = 10`) |
| `?inbox&pm_search=invoice` | Inbox, **server-side search across all pages** |
| `?show.12.inbox` | Open message 12 (list stays on the page containing it) |
| `?outbox` / `?drafts` / `?blocked` | Other folders (same offset/search syntax) |
| `?send` | Compose new message |
| `?reply.12` | Reply to message 12 |

Search tips:

```text
?drafts&pm_search=meeting     → drafts matching "meeting" (subject + recipient)
?outbox&pm_search=report      → all sent messages matching "report"
```

### Encryption (standalone)

`pm_crypto` is a self-contained class:

```php
require_once e_PLUGIN . 'pm/pm_crypto.php';

$crypto = new pm_crypto();          // auto-loads the protected key folder
$crypto->ensureKey();               // generate key + protection if missing

$secret  = 'Meet at noon?';
$encoded = $crypto->encrypt($secret);   // base64('v1|nonce|tag|ciphertext')

var_dump($crypto->isEncrypted($encoded)); // true
$plain   = $crypto->decrypt($encoded);    // 'Meet at noon?' (false on tag/key failure)
echo $crypto->versionOf($encoded);        // 'v1'
```

### Key rotation (re-encrypt everything under a new key)

```php
require_once e_PLUGIN . 'pm/pm_class.php';

$pm = new private_message();
$pm->rotateKey(200);  // new key version + re-encrypts up to 200 rows per call
```

### Shortcodes

```text
{PM_NEXTPREV=inbox}      → numbered pager for the inbox (search-aware)
{PM_NEXTPREV=outbox}     → … outbox
{PM_NEXTPREV=drafts}     → … drafts
```

---

## Developer Reference

### File structure

| File | Purpose |
|---|---|
| `pm.php` | Frontend controller: route parsing, 3-pane layout assembly, search bar, page-keeping logic |
| `pm_class.php` | `private_message` — mailboxes, drafts, blocks, attachments, notifications, crypto orchestration |
| `pm_crypto.php` | `pm_crypto` — self-contained AES-256-GCM class (key lifecycle, encrypt/decrypt, versioning) |
| `pm_shortcodes.php` | `pm_handler_shortcodes` — row/message shortcodes, `sc_pm_nextprev()` (numbered, search-aware pager) |
| `admin_config.php` | `e_admin_ui` admin controller + form: CRUD with encryption normalization & safe backend rendering |
| `pm_setup.php` | Install/upgrade routines (tables, key creation, attachment protection) |
| `pm_sql.php` | Table definitions |
| `pm_func.php`, `pm_menu.php`, `pm_default.php`, `e_shortcode.php`, `e_url.php`, `e_cron.php` | Plugin glue (functions, menu, defaults, URL routing) |
| `pm.css` | 3-pane layout, rows, search bar, pager styles |
| `templates/pm_template.php` | List/reader/empty-state templates (incl. no-JS pager hooks) |
| `languages/` | English & Dutch — root files **and** `English/`, `Dutch/` subfolders (new keys must go into **both** sets) |
| `plugin.xml` | Plugin metadata + pref defaults |

### Key API

```php
// private_message (pm_class.php)
pm_get_inbox($uid, $from = 0, $limit = 10, $search = '')   // + pm_get_outbox() — search = decrypt-then-filter
pm_folderOffset($pmid, $folder)                             // list offset of the page a message lives on
draft_save($to, $subject, $message, $option = '', $draftId = 0)
draft_getList($from = 0, $limit = 10, $search = '')
block_add($from, $to = USERID) / block_del($from, $to = USERID)
migrateEncryptMessages($batch = 200)                        // one-time legacy plaintext → encrypted
rotateKey($batch = 200)                                     // new key version + full re-encrypt

// pm_crypto (pm_crypto.php)
encrypt($plain) / decrypt($encoded) / isEncrypted($encoded) / versionOf($encoded)
ensureKey() / createKey($version = '') / keyPath() / supported()
```

### Notes for contributors

- **Trumbowyg interplay:** the `bbarea` editor hook wraps values in `[html]…[/html]` on `toDB()`. The admin form therefore normalizes encryption in `beforeCreate()`/`beforeUpdate()` and strips the wrapper on all read paths (`renderValue()` / `decryptRow()`). Keep both sides in sync if you touch the write path.
- `pm_size` must reflect the **plaintext** length and is computed before encryption.
- The strict `isEncrypted()` check (base64 + `vN|` prefix) is what guards the read paths — never store non-canonical values in `pm_subject` / `pm_text`.

---

## Contributing

1. **Back up before e107 updates** — any e107 PM plugin update overwrites this code. Keep the stock version aside (see `backup_PM_original/` pattern).
2. **Verify syntax** after any change: `php -l <file>` (the plugin ships with CRLF line endings on Windows — preserve them).
3. **Language keys:** add new `LAN_PM_*` keys to **all four** front files — `languages/English.php`, `languages/English/English.php`, `languages/Dutch.php`, `languages/Dutch/Dutch.php` (same for `_admin` files when admin-visible).
4. **Test on a staging site** before production: pagination offsets, search across pages, opening a message from page > 1, key rotation, and an admin create/edit round-trip (subject + body must round-trip as plaintext).
5. **Purge e107 caches** after uploading template or language changes.
6. Follow the existing e107 coding style (tabs, `varset()`/`defset()` helpers, no new external dependencies).

---

## License

Released under the **GNU General Public License v2.0** — the same license as the upstream e107 PM plugin this project is based on. See the license headers in each file and <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.

Based on the **e107 Private Messenger** plugin by [e107 Inc.](https://e107.org).
