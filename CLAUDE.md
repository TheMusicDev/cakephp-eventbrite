# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`themusicdev/cakephp-eventbrite`: a public CakePHP 5 plugin wrapping the framework-agnostic `themusicdev/eventbrite`
API client. It is meant to be used by many different apps, so it must not assume any one app's identity, schema or UI.

If `project-details.md` exists in the repo root, read it before doing anything else. It is gitignored and holds the
build plan and local context.

## This repo is public

Everything tracked is published. Machine paths, private app names, account or organization ids and credentials go only in
`project-details.md` or `.env`, both gitignored. Before staging, run `git check-ignore project-details.md` and search the
staged diff for local paths.

## Commands

`phpunit.xml.dist`, `phpcs.xml`, `phpstan.neon` and `docker-compose.yml` have to exist first; they are part of the
initial scaffold.

```bash
docker compose up -d dbtest               # MariaDB for tests, never sqlite
composer check                            # tests, coding standard, static analysis
composer test
composer cs-check
composer cs-fix
composer stan
vendor/bin/phpunit --filter=SomeTest      # one test
```

## Names

The plugin's natural names are taken by the API client, so the usual naming pattern can't apply. Don't "fix" these:

| What | Plugin | API client |
|---|---|---|
| Composer package | `themusicdev/cakephp-eventbrite` | `themusicdev/eventbrite` |
| PHP namespace | `TheMusicDev\CakeEventbrite` | `TheMusicDev\Eventbrite` |
| CakePHP plugin name | `TheMusicDev/CakeEventbrite` | none |

## Architecture

`docs/eventbrite-plugin-design.md` is the design of record: why each decision was made. The README covers how to use the
plugin. Keep rationale in `docs/` and usage in the README, without copying between them.

Rules that are easy to break by accident:

- **API client code stays in the client.** Anything that isn't CakePHP-specific, including API gaps found here, gets fixed
  in `themusicdev/eventbrite` and released first. Never copy client classes into this repo.
- **One client per token.** `EventbriteClient` takes its token in the constructor, so the container holds
  `EventbriteClientFactory`, never a shared client.
- **Tokens are never readable by accident.** They are encrypted at rest with `CakeEventbrite.encryptionKey`, hidden from
  entity serialization, and never logged. API responses contain attendee personal data, so don't log them either.
- **Connecting fails closed.** Without an `ownerResolver` that returns an owner, OAuth routes refuse the request.
- **OAuth state is bound to the session.** Pass `Session::id()` as the visitor value, after starting the session.
- **Webhook responses carry meaning.** Non-2xx makes Eventbrite retry, so use 5xx for transient failures and 4xx for
  refused deliveries. Never return 410: it unsubscribes the endpoint.
- **No screens or templates.** Visible UI belongs to the host app. Optional examples go in `examples/`.
- **Don't sleep in web requests.** The client's 429 backoff calls `sleep()`, so the factory uses no retries outside the CLI.

## Workflow

After the first push, every change goes through a pull request and lands as a merge commit. Commit messages follow
Conventional Commits, which the git hooks enforce.
