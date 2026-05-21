# Contributing

This guide covers how to make contributions to `sign-in-with-telegram`. It's the front door for both human and AI-agent contributors — the conventions defined here apply uniformly. For codebase architecture, gotchas, and operational knowledge, read [AGENTS.md](AGENTS.md) before making non-trivial changes.

## Setting up your local environment

Requirements:

- **Node 24.15+** and **npm 11.12.1+**. Both are enforced by `package.json`'s `devEngines` block — running `npm install` against a lower version exits with an error.
- **PHP 8.1+** and **Composer**.
- **Docker** (for the local WordPress stack via `wp-env`).

Bootstrap:

```bash
npm run setup    # composer install && npm install
```

If you use `nvm`, the repo's `.nvmrc` pins to `24.15` — run `nvm use` after cloning to switch to a satisfying version.

## Common workflows

Workflows are exposed two ways: as npm scripts (run with `npm run <name>`) and as slash-command skills (run via your AI agent's UI). The canonical skill content lives at `.agents/commands/*.md`; per-agent symlinks are created when you run the agent-specific setup script below.

| Task                                        | Command                 | Slash command |
| ------------------------------------------- | ----------------------- | ------------- |
| Bootstrap a fresh checkout                  | `npm run setup`         | `/setup`      |
| Run all local CI gates before pushing       | `npm run check`         | `/check`      |
| Open a PR with the right CC-prefixed title  | (use the slash command) | `/pr`         |
| Build a Conventional Commits commit message | (use the slash command) | `/commit`     |

`commit` and `pr` aren't npm scripts — the agent reads the skill markdown at `.agents/commands/{commit,pr}.md` and executes the workflow with its built-in `git` and `gh` tooling.

## Setting up your AI agent

Per-agent command directories (`.claude/commands/`, `.cursor/commands/`) are gitignored — each contributor runs the setup script for their agent of choice once after cloning, and the canonical files at `.agents/commands/` get symlinked into the per-agent directory. Editing a file in `.agents/commands/` immediately changes the behaviour for every linked agent.

| Agent       | Setup                                                                                              |
| ----------- | -------------------------------------------------------------------------------------------------- |
| Claude Code | `npm run setup:claude`                                                                             |
| Cursor      | `npm run setup:cursor`                                                                             |
| Codex CLI   | `npm run setup:codex` (no-op today; re-evaluate when Codex adds a per-project commands convention) |
| Gemini CLI  | `npm run setup:gemini` (no-op today, same reason)                                                  |

The setup script falls back to file-copy on platforms where symlink creation needs elevated privileges (typically stock Windows without developer mode). Copies don't auto-update from the canonical files, but the shims still work.

## Commit messages and PR titles

PR titles follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):

```
<type>[(<scope>)]: <description>
```

This is **required for everyone** — `.github/workflows/pr-title.yml` runs `amannn/action-semantic-pull-request` on every PR and fails the build if the title doesn't parse. Branch protection requires that check to pass.

| Type       | When to use                                     |
| ---------- | ----------------------------------------------- |
| `feat`     | New user-facing functionality                   |
| `fix`      | Bug fix                                         |
| `chore`    | Internal change (tooling, dependencies, config) |
| `docs`     | Documentation only                              |
| `refactor` | Internal restructure, no behavior change        |
| `test`     | Tests only                                      |
| `perf`     | Performance improvement                         |
| `ci`       | CI/CD workflow changes                          |
| `build`    | Build-system changes                            |
| `style`    | Formatting / whitespace only                    |

The `<scope>` is optional. Description should be in imperative mood, under 72 characters, no trailing period. Example: `feat(auth): add account linking from profile page`.

**Breaking-change syntax (`feat!:`, `BREAKING CHANGE:` footer) is not used in this repo.** Version bumps are controlled by `Release-As:` footers in commit bodies (see the deploy workflow), not by Conventional Commits' breaking-change marker. The `!` after a type is accepted by the PR-title linter but has no effect on the release pipeline.

Use the `/commit` slash command to build the message interactively; the skill walks through type selection, scope, and subject.

## Branch naming

All branches use a CC-prefixed pattern: `<type>/<short-description>`. Examples:

- `feat/account-linking`
- `fix/oidc-jwks-cache`
- `chore/release-please`
- `docs/contributing-conventions`

If you start a branch from Linear's "Create branch" button (which generates names like `rsm-3032-...`), rename it before pushing:

```bash
git branch -m feat/account-linking
```

The Linear ticket ID belongs in the PR description, not the branch name. Pre-existing branches following older schemes (`setup/stage-X`, `track-X/*`) are historical and don't need to be backfilled.

## Default branch

The default branch is `trunk`, not `main`. Open feature branches off `trunk` and target your PRs back at it.

## CI gates

Three GitHub Actions workflows run on every PR (and on every push to `trunk`):

- **CI** ([.github/workflows/ci.yml](.github/workflows/ci.yml)) — runs PHPCS + PHPUnit across PHP 8.1 / 8.2 / 8.3, plus the JS/TS stack (`npm run lint:js`, `lint:css`, `typecheck`, `test`, `build`).
- **Plugin Check** ([.github/workflows/plugin-check.yml](.github/workflows/plugin-check.yml)) — runs [Plugin Check](https://wordpress.org/plugins/plugin-check/) against an assembled artifact (`composer install --no-dev` + `npm run build`) so we catch wp.org-review issues before they reach the reviewer.
- **PR title** ([.github/workflows/pr-title.yml](.github/workflows/pr-title.yml)) — verifies the PR title follows Conventional Commits.

All three must be green before merge to `trunk`. Configure branch protection on the repo to require them.

## Local quick checks

Before pushing, run the same gates locally — bundled as `npm run check`:

```bash
npm run check
```

Which expands to:

```bash
composer phpcs
composer phpunit
npm run lint:js
npm run lint:css
npm run typecheck
npm test
npm run build
```

Run any single step in isolation while you're iterating.

## Style notes

- **PHP:** WPCS (`WordPress` ruleset) with no sniff exclusions. Class files follow `class-foo-bar.php` for class `Foo_Bar`; class names are snake_case.
- **JS / TS:** ESLint flat config combining `@eslint/js` recommended with `@wordpress/eslint-plugin` recommended; the WP config auto-detects the installed `typescript` and layers in typescript-eslint. All source under `routes/` and `packages/*/src/` is TypeScript.
- **SCSS:** Stylelint with `@wordpress/stylelint-config/scss`.
- See [.editorconfig](.editorconfig) for tab/space conventions.

For deeper conventions on naming, architecture, and known gotchas, read [AGENTS.md](AGENTS.md).
