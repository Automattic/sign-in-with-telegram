# /commit

Build a Conventional Commits message and commit the currently staged changes.

1. Verify staged changes exist (`git diff --cached --quiet` returns non-zero).
   If nothing is staged, run `git status` and ask the user what to stage —
   do not stage anything on their behalf.
2. Look at `git diff --cached --stat` and `git diff --cached` to inform the
   message. Read the actual change rather than guessing from filenames.
3. Build a message of the form `<type>[(<scope>)]: <description>` where:
   - `<type>` is one of: `feat`, `fix`, `chore`, `docs`, `refactor`, `test`,
     `perf`, `ci`, `build`, `style` (see [CONTRIBUTING.md](../../CONTRIBUTING.md)
     for the full table).
   - `<scope>` is optional. Use a short, lowercase noun for the area of code
     touched (`auth`, `oidc`, `settings`, etc.) if it clarifies — skip
     otherwise.
   - `<description>` is in the imperative mood ("add" not "added"), under
     72 characters, no trailing period.
4. Show the proposed message to the user. If they're at a TTY, ask for
   confirmation or accept an edit. If invoked non-interactively, proceed
   without prompting.
5. Run `git commit -m "<message>"`.
6. Report the resulting commit SHA and one-line subject.

**Breaking-change syntax (`feat!:`, `BREAKING CHANGE:` footer) is not used in
this repo.** Version bumps are controlled by `Release-As:` footers in commit
bodies, not by Conventional Commits' breaking-change marker. The `!` is
accepted by the PR-title linter but has no release-pipeline effect.
