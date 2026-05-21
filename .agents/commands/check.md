# /check

Run all local CI gates.

Run `npm run check`, which expands to:

- `composer phpcs` (WPCS PHP lint)
- `composer phpunit` (PHP unit tests)
- `npm run lint:js` (ESLint flat config)
- `npm run lint:css` (Stylelint)
- `npm run typecheck` (`tsc --noEmit`)
- `npm test` (Jest)
- `npm run build` (wp-build production build)

On first failure, stop and report:

- which command failed,
- the last 20 lines of its output,
- a one-line hypothesis for the cause if it's obvious from the output.

If everything passes, say "all green" and nothing more.
