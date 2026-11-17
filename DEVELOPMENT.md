# Development

`readme.txt` is the WordPress.org readme for store owners. This file is for working on the code.

The plugin is procedural WordPress code: a main file and `includes/`, every function, constant,
option, table, CSS class and REST route prefixed `pirkoo` / `PIRKOO` (since 0.2.3; nothing on a
store carries another name). It supports PHP 7.4+ and WordPress 6.5+, and
needs WooCommerce. Text domain: `pirkoo`, the WordPress.org slug
(it must always equal the slug). The plugin is called Pirkoo; "Multi-Store WooCommerce
Manager" is its description, never its name. The repo and folder are `pirkoo`.

## How it works

Since 0.3.0 the plugin is a connector to app.pirkoo.com, with no screen in wp-admin (the app's
repo, `docs/PROPOSAL-0.3.0.md` 3.5, has the protocol).

```
Plugins screen: Connect to Pirkoo ──▶ admin-post pirkoo_connect: one-time code (10 min)
        ──▶ browser to app.pirkoo.com/connect?store=…&code=…  ──▶ an Owner or Admin confirms
        ◀── app: POST /wp-json/pirkoo/v1/connect {code} ──▶ {secret} (stored in the option pirkoo)
App "Scan now" ──▶ POST /wp-json/pirkoo/v1/collect (signed) ──▶ the store's data ──▶ gateway
Plugins screen: Disconnect from Pirkoo ──▶ POST app/api/stores/disconnect (signed), secret deleted
App "Disconnect" ──▶ POST /wp-json/pirkoo/v1/disconnect (signed) ──▶ secret deleted
```

- Nothing is collected or sent before the store is connected, and then only when the app asks.
- Signed requests: headers `X-Pirkoo-Timestamp` and `X-Pirkoo-Signature`, the hex HMAC-SHA256 with
  the secret of timestamp, method, the route as `/wp-json/pirkoo/v1/<route>` (whatever the site's
  REST address) and the body's sha256,
  one per line (`pirkoo_signature()`). Refused: more than 5 minutes of clock difference, a
  signature already used (a transient for 10 minutes), a wrong one. `tests/test-connect.php` and
  the app's `internal/storelink` tests share one signature vector: keep them in line.
- One collection at a time (`pirkoo_lock()`): a second gets 409.
- `PIRKOO_APP_URL` (default `https://app.pirkoo.com`) can be overridden in wp-config.php.
- The Connect link also sends the plugin's REST address (`rest_url( 'pirkoo/v1' )`, 0.3.1), so
  stores with plain permalinks (`?rest_route=`) or WordPress in a subfolder connect too; the app
  accepts it only on the store's own host.

## Files

| File | What it holds |
|---|---|
| `pirkoo.php` | Plugin header, constants, includes and every hook |
| `includes/settings.php` | The option `pirkoo`: secret, `connected_at`, the connection code in progress; the clean-up after an update from 0.2.x |
| `includes/connect.php` | The Plugins screen links and their handlers, the REST routes (connect, collect, disconnect), signatures, the collection lock, the suggested privacy policy text |
| `includes/metrics.php` | The store metrics sent with each scan (schema 3) |
| `includes/site-health.php` | The site checks sent as `detailed.site_health` (updates, debug output, search visibility, table engines, transients, administrators, file editor), merged with the two files below |
| `includes/server-checks.php` | Upload limit, PHP extensions, disk space, load and memory (null when the host doesn't let PHP read them) |
| `includes/wordpress-checks.php` | Paused plugins and themes, fatal errors (7 days, a count from the log), automatic updates, permalinks, database latency and clutter, core checksums (api.wordpress.org), wp-config.php permissions, an "admin" username, open registration |
| `includes/business.php`, `funnel.php`, `detailed.php`, `profile*.php`, `class-pirkoo-profiler.php` | The collectors: business context, daily shop totals (table `pirkoo_funnel`), detailed diagnostics, the checkout profiler (header `X-Pirkoo-Profile`) |
| `uninstall.php` | Removes the option, lock, tokens and the daily totals table |

## Checks

The CI (`.github/workflows/ci.yml`) runs phpcs (WordPress Coding Standards + PHP 7.4
compatibility), PHPStan level 6, `php -l` and PHPUnit on PHP 7.4 and 8.4 with MySQL. Locally, without PHP installed: `PHP=7.4 npx @php-wasm/cli` runs
`vendor/bin/phpcs` and `vendor/bin/phpstan` (phpcs needs `--runtime-set installed_paths` and
`-d sys_temp_dir`; its exit code is always 0, read the output).

`tests/` uses the WordPress test suite with WooCommerce loaded; every request to
`PIRKOO_APP_URL` (`https://app.test` in the tests) is answered by the fake app in
`Pirkoo_Test_Case`, and any other request fails as if offline. PHPStan through php-wasm needs one
worker (`parallel.maximumNumberOfProcesses: 1` in a local config).

## Release

Build the zip from a tagged commit, its folder named after the slug
(`pirkoo/`), with only the shipped files: `pirkoo.php`, `uninstall.php`,
`readme.txt`, `includes/`, `languages/`, `legal/`.

`languages/pirkoo-pt_BR.po` is the Brazilian Portuguese translation (owner decision of 15 Nov 2026) and
`pirkoo-pt_BR.mo` its compiled form: change both together (`msgfmt -o languages/pirkoo-pt_BR.mo
languages/pirkoo-pt_BR.po`), and add every new translatable text to them in the same commit.

`legal/privacy-policy.txt` is Pirkoo's privacy policy (the app at app.pirkoo.com, this plugin and
pirkoo.com), published at https://pirkoo.com/privacy/: the pirkoo-website repo (`../../website`)
generates that page from a copy of this file (its `content/privacy-policy.txt`,
`go run ./cmd/legalpage`), and its test fails when they differ. `legal/terms-of-use.txt` is Pirkoo's
Terms of Use, published at https://pirkoo.com/terms/ the same way. tvarkom.com keeps only a short
notice for its own site. Both texts change only with the owner's approval. Pirkoo's addresses:
support@pirkoo.com (help), privacy@pirkoo.com (privacy requests), contact@pirkoo.com.

The dashboard, the scores and the scan schedule live in app.pirkoo.com (repo `pirkoo-app`) since
0.3.0: this plugin only connects the store and answers its scans.
