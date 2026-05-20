# AGENTS.md

Operational knowledge for AI agents and contributors working on this repo. Read this file before making structural changes — most surprises here have already been chased once.

## Project at a glance

A WordPress plugin (`telegram-auth`) that lets visitors sign in to WordPress with their Telegram account, using Telegram's OpenID Connect login. Built for the wp.org plugin repository. Repo: <https://github.com/Automattic/telegram-auth>. Default branch: `trunk` (not `main`).

Stack:

- **PHP** ≥ 8.1, WordPress ≥ 6.8, GPL-2.0-or-later. Classmap autoloading via Composer (`autoload.classmap: ["src/"]`), with `automattic/jetpack-autoloader` doing version-aware deduplication at runtime. Files under `src/` follow WPCS naming (`class-foo-bar.php` for class `Foo_Bar`); class names are snake_case. Run `composer dump-autoload` after adding a new class so it shows up in the classmap.
- **JS / TypeScript** with [`@wordpress/build`](https://www.npmjs.com/package/@wordpress/build) (`wp-build`) as the bundler. esbuild under the hood. Pinned to `0.13.0` (the package is young and ships breaking changes regularly).
- **Local dev** via [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) — Docker-based WP stack with the plugin mounted from the working tree.
- **Runtime dependency**: WordPress Core ≥ 7.0 _or_ the Gutenberg plugin active. The settings page is rendered by wp-build's experimental `wpPlugin.pages` feature, which depends on the `@wordpress/boot` script module shipped by Gutenberg/Core 7+.

## Repository layout

```
telegram-auth/
  telegram-auth.php          # Plugin bootstrap (header, ABSPATH guard, autoload + build/build.php loader, calls Bootstrap::init()).
  uninstall.php              # WP_UNINSTALL_PLUGIN guard only; real cleanup lands later.
  readme.txt                 # wp.org-format readme.
  README.md                  # GitHub-facing readme (local dev quickstart).
  composer.json              # firebase/php-jwt + dev tooling.
  package.json               # @wordpress/build, lint stack, npm scripts.
  phpcs.xml.dist             # WPCS ruleset (full WordPress standard, no exclusions).
  eslint.config.js           # Flat config: @eslint/js + @wordpress/eslint-plugin recommended.
  jest.config.cjs            # @wordpress/jest-preset-default + ts-jest.
  phpunit.xml.dist           # Strict; bootstraps Composer autoload.
  tsconfig.json              # ES2022, strict, jsx: react-jsx, jsxImportSource: react.
  .wp-env.json               # Latest WP, PHP 8.1, plugin + Gutenberg + Query Monitor mounted.
  .wp-env.override.json      # GITIGNORED — local bot creds (TELEGRAM_AUTH_CLIENT_ID/SECRET).
  .npmrc                     # install-strategy=linked.
  .editorconfig
  .gitignore                 # Ignores build/, vendor/, node_modules/, .wp-env/, /temp/, .wp-env.override.json, .vscode/* outliers, etc.
  src/
    class-bootstrap.php      # Telegram_Auth\Bootstrap — registers Settings → submenu + WP Build polyfill.
  routes/                    # wp-build's file-based router (one dir per route).
    telegram-auth/
      package.json           # { route: { path: '/', page: 'telegram-auth' } }
      stage.tsx              # React content for the route.
  bin/
    postbuild-shim-boot.cjs  # CRITICAL — see "wp-build pages gotcha" below.
  build/                     # GITIGNORED. Produced by `npm run build`.
  vendor/                    # GITIGNORED. Produced by `composer install`.
  tests/php/                 # PHPUnit tests (Brain Monkey for stubbing WP).
  docs/contributing.md       # Branch protection, lint commands.
  .github/workflows/
    ci.yml                   # PHP matrix 8.1/8.2/8.3 + JS lint/typecheck/test/build.
    plugin-check.yml         # Runs WP Plugin Check against the assembled artifact.
```

## Local development

```bash
composer install
npm install
npm run env:start          # Docker-backed WP stack on http://localhost:8888 (admin/password).
npm run dev                # wp-build watch mode in another terminal.
```

**Bot credentials**: create `.wp-env.override.json` (gitignored) at repo root with the OIDC credentials BotFather's mini app produces (open [@BotFather](https://t.me/BotFather), launch the mini app from the attachment menu, pick the bot under **My bots → Login widget**, and switch to OpenID Connect if the bot is still on the legacy widget). Example shape lives in [README.md](README.md). The values become real PHP constants (`TELEGRAM_AUTH_CLIENT_ID`, `TELEGRAM_AUTH_CLIENT_SECRET`) inside the container via wp-env's `config` block. Restart the stack (`npm run env:reset`) after editing the override.

**Companion scripts**:

- `npm run env:stop` — stop containers (state preserved).
- `npm run env:reset` — destroy and recreate.
- `npm run env:cli -- <subcommand>` — run WP-CLI inside the container.
- `npm run env:logs` — tail container logs.

**Quality gates** (CI runs the same):

```bash
composer phpcs
composer phpunit
npm run lint:js
npm run lint:css
npm run typecheck
npm test
npm run build
```

## Architecture

### Bootstrap

`telegram-auth.php` is a thin entry: ABSPATH guard, friendly admin notice when `vendor/autoload.php` or `build/build.php` are missing, then `\Telegram_Auth\Bootstrap::init()`.

`Telegram_Auth\Bootstrap` (`src/class-bootstrap.php`):

- `has_runtime()` — returns true when `get_bloginfo('version') >= 7.0` OR the Gutenberg plugin is in `active_plugins`. The wp-build-generated page templates depend on `@wordpress/boot` which only ships with Core 7+/Gutenberg, so the menu is gated on this.
- `register_menu()` — `add_submenu_page('options-general.php', …, 'telegram-auth-wp-admin', $callback)` on the `admin_menu` hook. Renders the **wp-admin-mode** page (integrated into standard wp-admin layout — vs. the full-page mode that takes over the screen with its own sidebar).
- `maybe_show_dependency_notice()` — admin notice when `has_runtime()` is false.

The render callback we hand to `add_submenu_page` is `telegram_auth_telegram_auth_wp_admin_render_page`. wp-build generates that name as `<wpPlugin.name>_<page-id-with-underscores>_wp_admin_render_page`. The prefix duplication (`telegram_auth_telegram_auth_…`) is unavoidable — wp-build doesn't decouple the page id from the function-name suffix.

### Settings page architecture

We use wp-build's **experimental** `wpPlugin.pages` feature:

- `package.json` declares `wpPlugin.pages: [ "telegram-auth" ]`.
- wp-build generates `build/pages/telegram-auth/page.php` (full-page mode) and `page-wp-admin.php` (wp-admin mode). We register `add_submenu_page` against the wp-admin-mode callback only.
- The page mounts a React app rendered by `@wordpress/boot` (provided by Gutenberg or WP 7+).
- Routes live under `routes/`, each in its own directory with a `package.json` describing `{ route: { path: '/', page: '<page-id>' } }` and a `stage.tsx` (required), plus optional `inspector.tsx`, `canvas.tsx`, `route.tsx` files. wp-build builds these into `build/routes/<name>/content.{js,min.js}` + asset.php.
- On page load, wp-build's generated `page-wp-admin.php` registers a "prerequisites" classic script (handle: `telegram-auth-wp-admin-prerequisites`) with the script handles needed before the inline `import("@wordpress/boot")` resolves, then enqueues the page's loader script-module which has the route content modules as dynamic deps.

### Auth flow (planned, not yet implemented)

The OIDC auth flow follows Telegram's [bots/telegram-login](https://core.telegram.org/bots/telegram-login) spec:

- `wp-login.php?action=telegram_auth_start` → builds an authorize URL with PKCE/state/nonce, redirects to Telegram.
- `wp-login.php?action=telegram_auth_callback` → exchanges code for `id_token`, validates against Telegram's JWKS (RS256, kid-aware), maps `sub` claim to a WP user, calls `wp_set_auth_cookie`.
- `wp-login.php?action=telegram_auth_link` → same flow but for logged-in users connecting an existing WP account; nonce-protected.

**No** `admin-post.php` is used — many membership/LMS/security plugins gate `/wp-admin/` for non-admin users, breaking linking for subscribers. The entire auth path lives on `wp-login.php` for compatibility.

**No** REST routes for the auth flow — caching/CDN/security-plugin interference. REST is used only for the settings UI.

### Telegram OIDC quirks (verified against live discovery doc)

`https://oauth.telegram.org/.well-known/openid-configuration` is real OpenID Connect, but minimal:

- Auth-Code + PKCE; tokens are RS256.
- **No `userinfo` endpoint** — every claim is in the `id_token`.
- **No `email` claim** — we cannot match WP users by email.
- Scopes: `openid`, `profile`, `phone`, `telegram:bot_access`.
- The OIDC `client_secret` is generated by BotFather's mini app (My bots → Login widget → Switch to OpenID Connect Login), distinct from the bot token. UX: format-validate to catch the common "I pasted my bot token" mistake.

## Library choices (don't re-litigate)

| Concern               | Pick                                             | Why                                                                                                                                                                                                                                                                                |
| --------------------- | ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| JWT/JWKS verification | `firebase/php-jwt`                               | BSD-3, ships `CachedKeySet` for JWKS rotation, no session dep, no userinfo coupling.                                                                                                                                                                                               |
| OIDC client           | Hand-rolled (~150 LOC)                           | `jumbojett/openid-connect-php` defaults to `$_SESSION` (no-go in WP) and calls `userinfo` implicitly (Telegram has none). `web-token/jwt-framework` is too heavy. `league/oauth2-client` plus firebase/php-jwt buys ~80 LOC at the cost of a Guzzle/PSR-7 dep tree — not worth it. |
| Build tool            | `@wordpress/build` (`wp-build`)                  | Per the project's stated requirement. Pinned to `0.13.0`; package is young and ships breaking changes regularly.                                                                                                                                                                   |
| Lint stack            | ESLint 9 (NOT 10), `@wordpress/eslint-plugin` 25 | ESLint 10 is blocked upstream by `@wordpress/eslint-plugin → @babel/eslint-parser` peer-depending on eslint 7-9.                                                                                                                                                                   |
| Phone number storage  | Plain text in `telegram_auth_phone` usermeta     | Plugin-owned key preserves the "verified by Telegram" guarantee — only the plugin ever writes it, on a verified id_token.                                                                                                                                                          |

## Coding conventions

### PHP

- WPCS (`WordPress` ruleset) — **no sniff exclusions**. Class files follow `class-foo-bar.php` for class `Foo_Bar`; class names are snake_case (e.g. `OIDC_Exception`, not `OIDCException`, because WPCS would otherwise expect `class-o-i-d-c-exception.php`). Composer uses classmap autoload (not PSR-4), so adding a new class requires `composer dump-autoload`.
- Global prefix: `telegram_auth_` (or `Telegram_Auth\` for namespaced PHP, `TELEGRAM_AUTH_` for constants).
- Text domain: `telegram-auth`.
- VS Code's PHPCS extensions don't always read `phpcs.xml.dist` cleanly. `composer phpcs` is the truth source. `.vscode/settings.json` configures the obliviousharmony extension to point at our ruleset, but extension-loading quirks can still cause spurious diagnostics — trust the CLI.

### TypeScript / JavaScript

- All source under `routes/` (and `packages/` once we add Phase 3 work) is TypeScript. JSX uses the `react-jsx` transform with `jsxImportSource: react` — no need to import React in every file.
- `@types/react` pinned to ^18.3.27 to match `@wordpress/element`'s React 18. **`@wordpress/*` does not support React 19 yet — do not bump.**
- Two ESLint rules are disabled in `eslint.config.js`: `import/no-extraneous-dependencies` and `import/no-duplicates`. The shared `eslint-import-resolver-typescript@4` doesn't yet handle TypeScript 6's API surface ("invalid interface loaded as resolver"). `tsc --noEmit` covers what these rules would catch.

### Query parameter naming

All plugin-owned query params use the `telegram_auth_` prefix:

- `telegram_auth_error=<code>` for failure-code redirects to `wp-login.php`.
- `telegram_auth_redirect_to=<url>` for the link flow's custom post-link redirect.
- WP-standard params (`_wpnonce`, `redirect_to` as consumed natively by `wp-login.php`) keep their core names.

## Known gotchas (the actual hard-won knowledge)

### 1. `wp-build`'s `wpPlugin.pages` template renders blank without a boot asset shim

**Symptom**: settings page renders an empty `<body><div id="…-app"></div></body>` with no console errors and no JS enqueued.

**Cause**: `build/pages/<slug>/page.php` (and `page-wp-admin.php`) gates ALL script enqueueing on `file_exists( __DIR__ . '/../../modules/boot/index.min.asset.php' )`. wp-build 0.10+ stopped emitting that file (`@wordpress/boot` is "now expected to be provided by WordPress Core 7.0+ or the Gutenberg plugin") but the page template wasn't updated. With the file missing, the entire block is skipped, no scripts enqueue, the page is blank.

**Fix**: `bin/postbuild-shim-boot.cjs` (wired as `npm run build && node bin/postbuild-shim-boot.cjs`) writes a stub asset.php with the script-handle prerequisites `@wordpress/boot` needs. The script writes `build/modules/boot/index.min.asset.php` with a hand-curated `dependencies` array. Tracked upstream at [WordPress/gutenberg#77883](https://github.com/WordPress/gutenberg/issues/77883) — drop the shim once that lands.

**Second gotcha**: the stub's dependency list MUST NOT include script-module-only handles (`wp-icons`, `wp-lazy-editor`, `wp-route`). Listing them as classic-script deps causes WP to silently refuse to print the prerequisites script — the inline `import("@wordpress/boot")` never reaches the DOM. Only handles registered as classic scripts (verified per dep) belong in the shim's `dependencies` array.

### 2. Two render modes: full-page vs. wp-admin-integrated

`wpPlugin.pages` generates two render callbacks per page:

- `<prefix>_<slug>_render_page` — **full-page mode**. Hooks `admin_init`, calls `exit()` after rendering its own `<html><body>`. Replaces the entire admin chrome with a custom sidebar. For editor-like apps.
- `<prefix>_<slug>_wp_admin_render_page` — **wp-admin mode**. Hooks `admin_enqueue_scripts` against `?page=<slug>-wp-admin`. Renders into a mount div inside the standard wp-admin layout. For settings pages.

We use wp-admin mode. The menu slug must include the `-wp-admin` suffix (`telegram-auth-wp-admin`) so `page-wp-admin.php`'s URL matcher fires.

### 3. wp-env doesn't accept raw PHP expressions in `config`

`.wp-env.json`'s `config` block runs `wp config set <key> <value>` per entry. String values are auto-quoted; only non-string values pass `--raw`. There's no escape hatch — `"WP_SITEURL": "constant( 'DOCKER_REQUEST_URL' )"` becomes a string literal, not a `constant()` call. To define `WP_SITEURL` / `WP_HOME` from `DOCKER_REQUEST_URL`, mount an mu-plugin via `mappings`.

### 4. ESLint version pinned at 9

ESLint 10 is blocked upstream: `@wordpress/eslint-plugin@25` → `@babel/eslint-parser@^7.28` → peer `eslint@^7 || ^8 || ^9`. `--legacy-peer-deps` would force install but the runtime behaviour is uncertain. Stay on 9 until Babel widens its peer range.

### 5. wp-env's `testsEnvironment` deprecation

`.wp-env.json` sets `"testsEnvironment": false` to silence wp-env's deprecated double-environment behaviour. We don't run end-to-end WP tests; PHPUnit uses Brain Monkey to stub WP without booting it.

## CI

Two GitHub Actions workflows, both required-to-pass on every PR:

- **CI** ([.github/workflows/ci.yml](.github/workflows/ci.yml)) — PHP 8.1/8.2/8.3 matrix runs `composer phpcs` + `composer phpunit`. JS job runs `npm ci` + `lint:js` + `lint:css` + `typecheck` + `test` + `build`.
- **Plugin Check** ([.github/workflows/plugin-check.yml](.github/workflows/plugin-check.yml)) — assembles a production artifact (composer no-dev + npm build) and runs [WordPress/plugin-check-action](https://github.com/WordPress/plugin-check-action) to catch wp.org-review issues before submission.

Trigger: `pull_request` and `push` to `trunk`.

## Deploying

Not yet wired. Plan: `10up/action-wordpress-plugin-deploy@stable` triggered on `v*.*.*` tags, with `slug: telegram-auth`, `assets-dir: .wordpress-org`, `generate-zip: true`. `.distignore` will exclude tests/, packages/\*/src, lint configs, source maps, and the gitignored planning artifacts.

## Things to remember when changing course

- **Never** suggest reverting to plain `add_menu_page` + manual script enqueue (i.e. abandoning `wpPlugin.pages` + `routes/`). The user explicitly chose to keep the wp-build pages flow despite the rough edges.
- **Never** bundle `@wordpress/boot` ourselves — it's owned by WP/Gutenberg now.
- **Never** put draft planning docs / scratch notes into a tracked path. The `.gitignore` includes `/temp/` for that purpose.
- Match the script-handle dep list in `bin/postbuild-shim-boot.cjs` to handles that are _actually registered as classic scripts_ in your target WP/Gutenberg version. Adding script-module-only handles silently breaks the shim.
