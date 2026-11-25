# WP Security Diagnostic Feed Server — Setup

This directory is a temporary copy of the standalone feed server. It lives inside the WordPress document root so it can be edited from the same workspace, but it must be removed after it has been pushed to GitHub.

It contains the GitHub Actions workflow that turns the Wordfence Intelligence v3 feed into signed data packs and publishes them as GitHub Release assets.

> **Security:** this directory contains `keys/signing-secret.key`, `.git`, and `data/` from the development environment. It is inside `/var/www/html`, so the web server could expose those files. An Apache `.htaccess` deny rule is included, but nginx ignores `.htaccess`. Remove this directory as soon as the repository is pushed to GitHub, or add the nginx rule below.

## What is included

```
.
├── .github/workflows/feed-release.yml   # GitHub Actions workflow
├── deploy/github-actions/               # Workflow copy and setup notes
├── bin/refresh.php                      # fetch → normalize → sign → publish
├── bin/build-release.php                # stage release assets
├── lib/                                 # client, normalizer, pack, state
├── public/                              # optional PHP feed server entry point
├── fixtures/                            # v3-shaped test feed
├── tests/                               # state, normalizer, and end-to-end tests
├── keys/                                # signing keypair (gitignored)
├── data/                                # state, packs, logs (gitignored)
├── config.example.php
└── README.md
```

## 1. Prepare a production signing key

The key currently in `keys/` may be a development key. Generate a fresh production keypair:

```bash
cd /var/www/html/wpdiag-feed
mv keys/signing-secret.key keys/signing-secret.dev.key
mv keys/signing-public.key keys/signing-public.dev.key
php bin/gen-keypair.php
```

Keep `keys/signing-secret.key` private. The public key is printed and stored in `keys/signing-public.key`.

If you use the existing key instead, that is fine for a test deployment; just keep the matching public key for the plugin.

## 2. Create a public GitHub repository

GitHub Release assets in a private repository cannot be downloaded anonymously by plugin installs, so the repository must be public.

```bash
cd /var/www/html/wpdiag-feed
git add -A
git commit -m "Add GitHub Releases feed workflow and v3 support"
git branch -M main
git remote add origin git@github.com:<owner>/<repo>.git
git push -u origin main
```

Before committing, confirm `git status` does not include `keys/`, `data/`, `config.php`, or other local secrets. They are ignored by `.gitignore`.

## 3. Add repository secrets

In the GitHub repository:

**Settings → Secrets and variables → Actions → New repository secret**

| Name | Value |
|---|---|
| `WPDIAG_SIGNING_SECRET_KEY_B64` | `base64 < keys/signing-secret.key \| tr -d '\n'` |
| `WORDFENCE_API_KEY` | Free Wordfence Intelligence v3 bearer token |

The signing secret is required for fixture and live runs. The Wordfence key is required for live runs.

## 4. Run the fixture workflow

GitHub → **Actions** → **Refresh vulnerability pack** → **Run workflow** → mode **fixture**.

This publishes a prerelease tagged `feed-fixture` and prints:

```
https://github.com/<owner>/<repo>/releases/download/feed-fixture/latest.wpdiag-pack
```

Use this URL only to validate the pipeline. It contains fixture data, not real intelligence.

## 5. Run live mode

Run the workflow again with mode **live**, or wait for the hourly schedule.

The stable live URL is:

```
https://github.com/<owner>/<repo>/releases/latest/download/latest.wpdiag-pack
```

The workflow:

- checks the six-hour guard in the `feed-state` branch;
- commits the preflight attempt before calling Wordfence;
- fetches, normalizes, signs, and publishes the pack;
- keeps the previous release if the fetch or build fails;
- keeps the ten most recent `feed-live-*` releases.

Do not delete or protect the `feed-state` branch.

## 6. Configure the WordPress plugin

The plugin is at:

```
/var/www/html/wp-content/plugins/wp-security-diagnostic
```

It must include the current feed-client change that follows GitHub Release redirects (`redirection => 5`). This workspace already has that change; include it when deploying the plugin to other sites.

### Set the feed URL

WP Admin → **WP Security Diagnostic → Settings → Vulnerability feed URL**, or WP-CLI:

```bash
wp --allow-root --path=/var/www/html eval '\WPSD\Core\Settings::update(array(
  "feed_url" => "https://github.com/<owner>/<repo>/releases/latest/download/latest.wpdiag-pack"
));'
```

### Trust the signing key

Copy the example mu-plugin:

```bash
cp /var/www/html/wpdiag-feed/deploy/github-actions/wpdiag-feed-key.example.php \
   /var/www/html/wp-content/mu-plugins/wpdiag-feed-key.php
```

Replace `PASTE_SIGNING_PUBLIC_KEY_HERE` with the contents of:

```bash
cat /var/www/html/wpdiag-feed/keys/signing-public.key
```

Alternatively, replace the plugin’s `Pack::PUBLIC_KEY` constant.

### Trigger the first import

WP Admin → **WP Security Diagnostic → Software → Check for updates**, or run an assessment. The vulnerability matching step also refreshes the pack automatically.

Force a refresh while testing:

```bash
wp --allow-root --path=/var/www/html eval \
  '(new \WPSD\Vulnerability\Feed_Client())->update(true);'
```

Check feed status:

```bash
wp --allow-root --path=/var/www/html eval \
  '$f = new \WPSD\Vulnerability\Feed_Client(); echo wp_json_encode($f->get_status());'
```

A successful import reports a pack version, advisory count, and `signature_valid: true`.

## 7. Remove this temporary directory

After the repository is pushed and the secrets are stored, remove the webroot copy:

```bash
rm -rf /var/www/html/wpdiag-feed
```

Do not remove the WordPress plugin directory.

## Web server protection for the temporary copy

Apache uses the included `.htaccess`:

```apache
Require all denied
```

For nginx, add this before removing the directory:

```nginx
location ^~ /wpdiag-feed/ {
    deny all;
}
```

## Troubleshooting

### The plugin reports “feed returned HTTP 404”

- Confirm the repository is public.
- Confirm the URL ends in `/releases/latest/download/latest.wpdiag-pack`.
- Confirm at least one non-draft `feed-live-*` release exists.
- The fixture URL is different: `/releases/download/feed-fixture/latest.wpdiag-pack`.

### Signature verification fails

- The plugin’s `wpdiag_pack_public_key` does not match `keys/signing-public.key`.
- The signing secret in GitHub Actions was rotated after the release was published.
- Re-run the workflow after fixing the key, then import the new pack.

### GitHub Actions says “No Wordfence API key configured”

Add the `WORDFENCE_API_KEY` repository secret. The workflow only reads it from the environment.

### GitHub Actions skips the fetch

The `feed-state` branch still has a six-hour or 429 backoff window. This is intentional. Check the workflow log for `skipped` and the next allowed time.

### The release is created but the pack is not live

Release publishing is atomic: the workflow creates a draft, uploads assets, then publishes it as latest. If upload fails, the previous release remains latest. Check the workflow log for `gh release upload`.

## Current test status

- Feed server verification: PASS (durable guard, normalizer, 429, serving, retention, release assets).
- Plugin acceptance suite: PASS.
- Live Wordfence v3 fetch: not tested here because no live API key exists in this environment. The first live workflow run will validate the full 158 MB feed and the produced pack size.
