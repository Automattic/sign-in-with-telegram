# Contributing

## Branching

The default branch is `trunk`. Open feature branches off `trunk` and target your PRs back at it.

## CI gates

Two GitHub Actions workflows run on every PR (and on every push to `trunk`):

- **CI** ([.github/workflows/ci.yml](../.github/workflows/ci.yml)) — runs PHPCS + PHPUnit across PHP 8.1 / 8.2 / 8.3, plus the JS/TS stack (`npm run lint:js`, `lint:css`, `typecheck`, `test`, `build`).
- **Plugin Check** ([.github/workflows/plugin-check.yml](../.github/workflows/plugin-check.yml)) — runs [Plugin Check](https://wordpress.org/plugins/plugin-check/) against an assembled artifact (`composer install --no-dev` + `npm run build`) so we catch wp.org-review issues before they reach the reviewer.

Both workflows must be green before merge to `trunk`. Configure branch protection on the repo to require both jobs.

## Local quick checks

Before pushing, run the same gates locally:

```bash
composer phpcs
composer phpunit
npm run lint:js
npm run lint:css
npm run typecheck
npm test
npm run build
```

## Style notes

- PHP: WPCS (`WordPress` ruleset) with `WordPress.Files.FileName` excluded so PSR-4 PascalCase filenames are allowed.
- JS / TS: ESLint flat config combining `@eslint/js` recommended with `@wordpress/eslint-plugin` recommended; the WP config auto-detects the installed `typescript` and layers in typescript-eslint. All source under `packages/*/src/` is TypeScript.
- SCSS: Stylelint with `@wordpress/stylelint-config/scss`.
- See [.editorconfig](../.editorconfig) for tab/space conventions.
