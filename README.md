# LazySession

Omeka S module that keeps anonymous visitors out of the `session` table.

As long as a session holds nothing that has to stay on the server, its data travels in an
encrypted cookie instead of a database row. A crawler that ignores cookies leaves nothing
behind, a visitor who submits a form gets their CSRF token and anti-spam state back from the
cookie, and logged-in users keep an ordinary server-side session.

## Why

Omeka S starts a session on every page request. Looking up the logged-in user
(`Omeka\Mvc\MvcListeners::bootstrapLocale()`) opens the `Laminas_Auth` session container, and
opening a container calls `session_start()`. Every request that does not send the session cookie
back, which is what most crawlers do, gets a fresh session. Omeka's database save handler then
writes that session as a new row.

Those rows are not empty either. A page that renders a form stores a CSRF salt in the session,
and modules such as [Comment](https://gitlab.com/Daniel-KM/Omeka-S-module-Comment) keep their
anti-spam state there (load time, proof-of-work salt). On a site with a comment form on every
item page, each crawler hit costs about 1.5 KB in the database, and nobody ever reads it again.

On [Gouda Tijdmachine](https://www.goudatijdmachine.nl/) this added about 140,000 rows a day
(roughly 120 a minute), almost all of them from crawlers. With LazySession active, new rows
dropped to zero within minutes for anonymous traffic: only logged-in sessions are still written.

Simply not saving those sessions is not an option: the comment form, the login form and every
other form an anonymous visitor submits would fail CSRF validation. LazySession keeps the data,
just not on the server.

## How it works

Omeka core lets a module supply its own session save handler through the `session.save_handler`
configuration key. LazySession's handler wraps Omeka's database handler and decides, on every
write, where the session goes:

| The session... | Stored in |
|---|---|
| holds a logged-in identity, or another container listed in `force_db_containers` | database |
| does not fit in the cookie (`max_cookie_bytes`, default 3500 bytes encoded) | database |
| anything else (an anonymous visitor) | encrypted cookie, no database row |

A database session that no longer needs the server (after logout, or one created before the
module was installed) moves to the cookie and its row is deleted.

Reading works the other way round: a valid cookie for the requested session id is used as is,
without touching the database; otherwise the database handler is asked.

### The cookie

The cookie (`lazysession` by default) holds the session id, the time it was issued and the
serialized session data, compressed and then encrypted with libsodium's `secretbox` (XSalsa20
and Poly1305). A visitor can neither read nor alter it: anything that fails to decrypt, belongs
to another session id, or is older than `session.gc_maxlifetime` is ignored, as if there were no
cookie. It is sent with `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS and no expiry date, like
Omeka's own session cookie.

The key is created when the module is installed and kept in the `lazysession_key` setting.
Deleting that setting makes the module create a new key, which invalidates every anonymous
cookie at once. Logged-in sessions are not affected.

### Writing before the response is sent

A cookie can only be set while the response headers are still unsent, but PHP writes sessions
at the very end of a request, after the response has gone out. The module therefore closes the
session itself on Laminas' `finish` event, just before the response is sent. Before writing, it
drops containers whose Laminas expiry time has passed (old CSRF salts, typically), so a visitor
who browses many pages stays under the cookie size limit.

A request that ends some other way (a controller that streams a file and exits) has already sent
its headers. A database session is then written as usual; an anonymous session is dropped, since
a streamed response renders no form whose state would be needed later.

## Installation

Download the release zip, unzip it into Omeka's `modules/` directory as `LazySession`, and
install it from **Modules** in the admin. The module has no settings page and needs no
configuration.

From source:

```bash
cd /path/to/omeka-s/modules
git clone https://github.com/coret/Omeka-S-module-LazySession.git LazySession
```

There are no Composer dependencies to install.

Requires Omeka S 4.1 or later and PHP 8.1 or later with the `sodium` and `zlib` extensions,
which standard PHP builds include.

Deactivating the module restores Omeka's own behaviour on the next request. Anonymous visitors
then lose whatever their cookie held (a half-filled form has to be reloaded); logged-in users
are unaffected, because their sessions were in the database all along.

## Configuration

Everything adjustable lives in `config/module.config.php` under the `lazysession` key:

- `cookie_name`: name of the cookie, `lazysession` by default.
- `max_cookie_bytes`: upper bound for the encoded cookie value. Browsers accept about 4096
  bytes per cookie, name and attributes included.
- `force_db_containers`: session containers that must never leave the server. The defaults are
  `Laminas_Auth` (the logged-in identity) and the three containers of the
  [TwoFactorTotp](https://github.com/coret/Omeka-S-module-TwoFactorTotp) module. Add the
  containers of other modules that keep security-sensitive state in the session.

Omeka's own session settings still apply. Set `gc_maxlifetime` under `session.config` in
`config/local.config.php` to decide how long both kinds of session last:

```php
'session' => [
    'config' => [
        'gc_maxlifetime' => 172800, // two days
    ],
],
```

## What it depends on

Nothing beyond Omeka S and PHP: libsodium and zlib are PHP extensions, and the database save
handler that LazySession wraps is Omeka's own `Omeka\Session\SaveHandler\Db`.

## Known limits

- **Replay.** A visitor can send an old copy of their own cookie back, within the session
  lifetime. For anonymous data such as CSRF salts this gives them nothing a crawler that keeps
  its cookies does not already have. That is why anything security-sensitive belongs in
  `force_db_containers`.
- **Writes after the `finish` event are lost.** The session is closed just before the response is
  sent. Nothing in Omeka core writes to the session that late.
- **Parallel requests.** Two requests from the same browser that both change the session each
  send a cookie, and the browser keeps the last one. Omeka's database handler has no locking
  either, so this is not new, only visible in a different place.
- **Cookie size.** A session that grows past `max_cookie_bytes` moves to the database, so very
  large anonymous sessions still cost a row.

## Development

```sh
OMEKA_PATH=/path/to/omeka-s php test/verify-wiring.php
```

`test/verify-wiring.php` uses Omeka's autoloader but needs no database or web server. It checks
the configuration, the cookie codec (round trip, tampering, wrong key), and every decision the
save handler makes, against an in-memory stand-in for the database handler.

Translations are in `language/` (see `language/README.md`). Code style follows Omeka S core:
`php-cs-fixer fix` with the included `.php-cs-fixer.dist.php`.

## Roadmap

- [ ] Optional key rotation that accepts the previous key for one session lifetime.
- [ ] Report on the admin dashboard how many sessions are in the cookie and how many in the
      database.
- [ ] Find out whether Omeka core would accept looking up the identity without starting a
      session when no session cookie was sent, which would make most of this module unnecessary.

## Licence

GPL-3.0-or-later. This module is © 2026 Bob Coret.
