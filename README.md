# WP Security Diagnostic — Feed Server

Minimal, self-contained distribution service for generic vulnerability intelligence. It fetches the Wordfence Intelligence feed with a centrally managed API key, normalizes it into the plugin's signed data-pack format, and serves the same generic pack to every plugin installation. It does not implement site registration, remote scanning, diagnostic storage, or remote administration.

## Requirements

- PHP >= 8.0 with `sodium`, `zip`, `json` and stream support.
- Write access to `data/` and `keys/`.

## Install

1. Copy this tree to the server (for example `/var/www/wpdiag-feed`).
2. `cp config.example.php config.php` and adjust if needed.
3. Set the API key: `export WORDFENCE_API_KEY=...` (or put it in `config.php`).
4. `php bin/gen-keypair.php` — keep `keys/signing-secret.key` outside any web root and readable only by the service user.
5. Publish the printed public key into the plugin (`wpdiag_pack_public_key` filter or `Pack::PUBLIC_KEY`).
6. Point the web server document root at `public/` only. Nothing above `public/` may be served.

## Refresh

```
php bin/refresh.php            # hourly guard + lock
php bin/refresh.php --force    # bypass the interval guard
php bin/refresh.php --file=fixtures/wordfence-sample.json --force   # offline fixture mode
```

Crontab (hourly; more frequent runs are harmless due to the interval guard):

```
17 * * * * php /var/www/wpdiag-feed/bin/refresh.php >> /dev/null 2>&1
```

Exit codes: `0` ok, `2` build/fetch failed (old pack retained), `3` skipped, `4` configuration/usage error.

## Endpoints

- `GET /latest` — latest signed pack (ZIP).
- `GET /v/{version}` — specific pack.
- `GET /meta.json` — index metadata.
- `GET /healthz` — liveness.
- `GET /` — plain text status.

## Verify

```
php bin/verify-pack.php data/packs/pack-<version>.wpdiag-pack
tests/verify.sh
```

The verification script builds signed fixture packs without a Wordfence key, checks tamper rejection, serving, interval skipping, retention, and failure retention.

## Plugin configuration

Set the plugin's feed URL to `https://feed.example.com/latest` and keep the plugin's refresh interval at or above the server's interval.

## Security notes

- The signing secret never touches `public/` or the plugin.
- Incoming packs are parsed as data only; nothing is executed.
- Rotate keys by generating a new keypair and republishing the public key; previously published packs become invalid, which is expected.
- Serve over TLS and restrict `public/` to static pack downloads.

## Web server routing

`public/index.php` handles every route. Configure the web server to send all requests to it, for example nginx:

```
location / { try_files $uri /index.php?$query_string; }
```

The PHP built-in server for development: `php -S 127.0.0.1:8099 public/index.php`.
