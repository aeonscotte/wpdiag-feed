# GitHub Actions + GitHub Releases deployment

This directory contains a ready-to-copy workflow for running the feed server entirely inside GitHub Actions and publishing the signed pack through GitHub Releases.

The workflow file must live at:

```
.github/workflows/feed-release.yml
```

A copy is kept here as `feed-release.yml` for reuse in another repository.

## How it works

- `schedule` runs an hourly check. The durable six-hour guard decides whether to call Wordfence.
- `workflow_dispatch` lets you run `fixture` or `live` mode manually.
- The `feed-state` branch stores `fetch-state.json`. The workflow commits the preflight attempt **before** fetching, then commits the final status.
- A successful live run creates a draft release, uploads `latest.wpdiag-pack`, `meta.json`, and `status.json`, then publishes it as the latest release.
- A fixture run publishes to the fixed prerelease tag `feed-fixture`, so it never becomes `releases/latest`.
- A failed run leaves the previous release untouched.
- The stable live plugin URL is:

```
https://github.com/<owner>/<repo>/releases/latest/download/latest.wpdiag-pack
```

The fixture URL is:

```
https://github.com/<owner>/<repo>/releases/download/feed-fixture/latest.wpdiag-pack
```

GitHub redirects both URLs to the release asset. The plugin must allow redirects (see below).

## Requirements

- A **public** GitHub repository. Release assets of a private repository cannot be downloaded anonymously by plugin installs.
- GitHub Actions enabled.
- A Wordfence Intelligence API key for live mode.
- A signing keypair.
- PHP 8.3 with `sodium`, `zip`, and `mbstring` — installed by the workflow.

## Setup

### 1. Push the repository

Create a public GitHub repository and push this feed-server tree. The workflow must be at `.github/workflows/feed-release.yml`.

### 2. Generate a signing keypair

```bash
php bin/gen-keypair.php
```

This writes:

```
keys/signing-secret.key    # 64 raw bytes; never commit
keys/signing-public.key    # base64 public key; give to the plugin
```

### 3. Add repository secrets

Settings → Secrets and variables → Actions:

| Secret | Value |
|---|---|
| `WPDIAG_SIGNING_SECRET_KEY_B64` | `base64 < keys/signing-secret.key \| tr -d '\n'` |
| `WORDFENCE_API_KEY` | Free Wordfence Intelligence v3 bearer token |

The API key is required only for live mode. The signing secret is required for both modes.

### 4. Enable GitHub Actions

No Pages setup is required. The workflow only needs `contents: write` permission, which is declared in the file.

### 5. Run the fixture smoke test

Actions → **Refresh vulnerability pack** → **Run workflow** → mode **fixture**.

This builds the small fixture pack, creates a prerelease tagged `feed-fixture`, and prints the fixture URL in the run summary.

Send that URL to the plugin maintainer. It is enough to validate:

- Actions → release upload
- redirect behavior
- plugin download and signature verification
- pack import

The fixture pack contains no real advisories.

### 6. Run live mode

Once the live-feed code is ready:

- Add the `WORDFENCE_API_KEY` secret.
- Run the workflow manually with mode **live**, or let the hourly schedule run.
- The first live run creates the `feed-state` branch automatically.
- Do not delete or protect the `feed-state` branch. It is the rate-limit guard.

### 7. Configure the plugin

The feed URL is:

```
https://github.com/<owner>/<repo>/releases/latest/download/latest.wpdiag-pack
```

The plugin’s `Feed_Client` follows GitHub’s redirect with:

```php
'redirection' => 5,
```

Set the plugin’s `feed_url` to the release URL, and configure the plugin to trust `keys/signing-public.key`. A sample mu-plugin is included as `wpdiag-feed-key.example.php`; copy it to `wp-content/mu-plugins/wpdiag-feed-key.php` and replace the placeholder with the public key.

The fixture URL is only for pipeline testing:

```
https://github.com/<owner>/<repo>/releases/download/feed-fixture/latest.wpdiag-pack
```

## State branch

The workflow creates an orphan branch named `feed-state` containing only `fetch-state.json`.

Two commits happen per live fetch:

1. **Preflight** — written and pushed before the Wordfence request.
2. **Final** — written after the fetch/build, even on failure.

If a runner dies between the two, the preflight commit remains and the next run waits for the six-hour window. The persisted state also carries a 429 `Retry-After` backoff.

The workflow uses a `concurrency` group so two runs cannot overlap.

## Release retention

Each successful live run creates a release tagged `feed-live-<pack-version>`. The workflow keeps the ten most recent non-draft `feed-live-*` releases and deletes older ones and their tags. The `feed-fixture` prerelease is retained separately.

## Limitations

- GitHub Actions schedules can be delayed or dropped; the guard prevents over-fetching but not staleness.
- GitHub release assets are limited to 2 GB per file. The plugin’s 100 MB feed download cap may need adjusting if the real pack is larger.
- The repository and release assets are public.
- GitHub Actions is not an SLA scheduler. For client production at scale, use a paid host or object storage/CDN.
- The live path uses the Wordfence v3 endpoint and the real v3 normalizer. A live run still needs a valid API key and enough memory for the full feed; the workflow sets `memory_limit=4G`.
