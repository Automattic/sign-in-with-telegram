# /pr

Open a pull request for the current branch.

1. Verify we're on a branch, not detached HEAD
   (`git symbolic-ref --short HEAD` returns a name). If detached, tell the
   user to `git checkout -b <branch>` first and stop.
2. Verify the branch name follows the CC-prefixed convention
   (`<type>/<short-description>`, see [CONTRIBUTING.md](../../CONTRIBUTING.md)).
   If it doesn't, suggest a rename: `git branch -m <new-name>`. Do not push
   a non-conforming branch — the PR-title check will pass independently, but
   we keep branch names consistent.
3. If the branch isn't on the remote yet, push it:
   `git push -u origin HEAD`.
4. Check whether a PR already exists for this branch:
   `gh pr view --json state -q .state 2>/dev/null`. If yes, point the user
   at `gh pr view --web` and stop.
5. Build the PR title from the branch's CC prefix. Examples:
   - `feat/account-linking` → `feat: account linking`
   - `fix/oidc-jwks-cache` → `fix: oidc jwks cache`
   The title must pass `.github/workflows/pr-title.yml`. If the latest
   commit subject is already CC-prefixed and a better summary than the
   branch name, prefer that.
6. Open the PR with the project template as the body:
   ```bash
   GH_PROMPT_DISABLED=1 gh pr create \
     --title "<title>" \
     --body-file .github/pull_request_template.md \
     --base trunk \
     --head "$(git branch --show-current)"
   ```
   Pass every flag explicitly so `gh` never prompts.
7. Report the PR URL.

If anything in steps 1–6 fails, report what failed and what to do next — do
not silently retry.
