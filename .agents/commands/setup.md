# /setup

Bootstrap a fresh checkout for local development.

1. Verify the runtime versions first:
   - If `npm -v` reports below 11.15, run `npm install -g npm@latest`. Node 24
     ships with an older npm than this repo's `devEngines.packageManager` floor
     requires; without this step `npm install` will fail with a devEngines error.
   - If `node -v` reports below 24.15, ask the user to install a satisfying
     Node version (the repo's `.nvmrc` pins to 24.15 — `nvm use` is the
     fastest path).
2. Run `npm run setup` (which runs `composer install && npm install`).
3. Report any errors with the full output of the failing command.
4. After it completes successfully, suggest `npm run env:start` to bring up the
   local WP stack (Docker required).
5. If the contributor is on Claude Code, also suggest running
   `npm run setup:claude` to install the agent slash-command shims.
