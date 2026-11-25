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
php bin/refresh.php              # six-hour guard + lock
php bin/refresh.php --force      # bypass the scheduled window (30-minute hard floor)
php bin/refresh.php --status     # print the durable fetch state as JSON
php bin/refresh.php --file=fixtures/wordfence-sample.json --force   # offline fixture mode
```

The live guard is persisted in `data/state/fetch-state.json` **before** the upstream request. A crash, failed build, or container restart therefore cannot trigger a second fetch inside the window. Fixture builds (`--file`) do not touch the live window.

The default window is six hours (`refresh_interval_seconds = 21600`). A `429` extends the guard using `Retry-After` when supplied; otherwise the normal six-hour window applies. `--force` cannot bypass a 30-minute hard floor or an active 429 backoff. If you later want the documented hourly cadence, set `refresh_interval_seconds = 3600`; the durable guard still prevents bursts.

Crontab (hourly; the durable guard makes more frequent runs harmless):

```
17 * * * * php /var/www/wpdiag-feed/bin/refresh.php >> /dev/null 2>&1
```

Exit codes: `0` ok, `2` build/fetch failed (old pack retained), `3` skipped, `4` configuration/usage error.

## Endpoints

- `GET /latest` — latest signed pack (ZIP).
- `GET /v/{version}` — specific pack.
- `GET /meta.json` — index metadata.
- `GET /status.json` — last fetch attempt/success, last status, next allowed time (including 429 backoff), current pack version.
- `GET /healthz` — liveness.
- `GET /` — plain text status.

`/status.json` does not expose the API key, error details, or filesystem paths.

## GitHub Actions + GitHub Releases

A ready-to-use workflow lives in `deploy/github-actions/`. It runs the refresh on GitHub Actions and publishes the signed pack as a GitHub Release asset.

The workflow file must be copied to `.github/workflows/feed-release.yml`. See `deploy/github-actions/README.md` for the repository secrets, fixture/live modes, state branch, and plugin URL.

## Verify

```
php bin/verify-pack.php data/packs/pack-<version>.wpdiag-pack
tests/verify.sh
```

The verification script runs in a temporary data tree, so it does not touch production packs or signing keys. It covers the normalizer against a real v3-shaped fixture, the durable interval guard across a new PHP process, fixture build, signature verification, tamper rejection, serving, retention, failure retention, and release asset staging.

## Plugin configuration

Set the plugin's feed URL to `https://feed.example.com/latest` and keep the plugin's refresh interval at or above the server's interval.

## Container deployment

Mount these paths as persistent volumes:

- `data/state` — the durable fetch guard (required to survive restarts)
- `data/packs` — published packs
- `keys` — signing key
- `data/logs` — refresh log

`data/work` may be ephemeral. If you prefer to reuse an existing volume, point `state_dir` at a persistent path such as `data/packs`; the state file stays outside `public/` and is never served. Run a single refresher process (or a Kubernetes CronJob with `concurrencyPolicy: Forbid`). Web containers can be stateless and read the packs volume; the refresh lock is only reliable for one writer.

## Security notes

- The signing secret never touches `public/` or the plugin.
- The Wordfence API key stays in the server environment or `config.php`; it is never sent to plugin installations.
- Incoming packs are parsed as data only; nothing is executed.
- Rotate keys by generating a new keypair and republishing the public key; previously published packs become invalid, which is expected.
- Serve over TLS and restrict `public/` to static pack downloads.

## Web server routing

`public/index.php` handles every route. Configure the web server to send all requests to it, for example nginx:

```
location / { try_files $uri /index.php?$query_string; }
```

The PHP built-in server for development: `php -S 127.0.0.1:8099 public/index.php`.
