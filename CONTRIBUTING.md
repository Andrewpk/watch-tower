# Contributing to Watch Tower

Thanks for helping out. Bug reports, fixes, docs and small features are all welcome.

For a suspected security vulnerability, do not open a public issue. See [SECURITY.md](SECURITY.md).

## Getting started

1. Fork the repository and clone your fork.
2. Follow [doc/install.md](doc/install.md) to get the app running locally. `composer run setup` does most of it.
3. Look for an open issue before starting. For anything larger than a small fix, comment on the issue first so we can agree on the approach.

Docker users can see [doc/docker.md](doc/docker.md) for running the release image.

## Checks to run before opening a PR

CI runs the following on every pull request. Run them locally first, from the repository root:

```bash
# PHP style (use `composer run lint` to auto-fix)
vendor/bin/pint --test

# Tests (Pest)
php artisan test --compact --ci

# Migrations: up, rollback, up on a throwaway SQLite database
(
  export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$(mktemp -d)/ci.sqlite"
  touch "$DB_DATABASE"
  php artisan migrate --force --no-interaction
  php artisan migrate:rollback --force --no-interaction
  php artisan migrate --force --no-interaction
)

# Frontend (use `npm run lint` and `npm run format` to auto-fix)
npm ci
php artisan wayfinder:generate --with-form
npm run lint:check
npm run format:check
npm run types:check
```

`composer run ci:check` runs most of this (ESLint, Prettier, `tsc`, Pint and the tests). It does not run the migration cycle, and it does not generate the Wayfinder route files that `tsc` needs, so run `php artisan wayfinder:generate --with-form` before it on a fresh checkout.

## Tests

Every behavior change needs a test. We use [Pest](https://pestphp.com). Add or update a test alongside your change:

```bash
php artisan make:test --pest SomeFeatureTest          # feature test (most tests)
php artisan make:test --pest --unit SomeUnitTest      # unit test
php artisan test --compact --filter=SomeFeatureTest   # run a subset
```

Bug fixes should include a test that fails without the fix.

## Branches and commits

Branch from `main` and name the branch `<type>/<issue-number>-<short-slug>`, for example `fix/11-test-suite` or `feat/35-release-image`.

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org), matching the existing history:

| Prefix | Use for |
| --- | --- |
| `feat:` | A new feature |
| `fix:` | A bug fix (`fix(tests):` style scopes are fine) |
| `docs:` | Documentation only |
| `ci:` | GitHub Actions and build pipeline changes |
| `chore:` | Maintenance that does not change behavior |
| `style:` | Formatting only (Pint, ESLint, Prettier) |

Use the imperative mood, for example `fix: handle empty stacktrace in ingest`.

## Pull requests

- Keep one issue per PR where practical. Smaller PRs get reviewed faster.
- Reference the issue in the PR description with `Closes #N` so it closes on merge.
- Fill in the PR template, and make sure CI is green.
- Update `README.md` or `doc/` if you change behavior or setup.

## AI assistant configuration files

`CLAUDE.md`, `AGENTS.md`, `.mcp.json`, `boost.json` and `opencode.json` configure AI coding assistants through [Laravel Boost](https://github.com/laravel/boost). They are not needed to build, run or contribute to the project, and you can ignore them. Using an AI assistant is optional, and you are responsible for any code you submit either way.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
