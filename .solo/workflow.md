# Workflow - Assay

Project profile for the `multi-agent-build` skill and every agent working on Assay. The coordinator
reads this first.

Assay is a pre-launch, self-hosted AI telemetry server for Laravel applications. The monorepo contains
the server application and the contracts/client packages. Product behavior lands only through the PR
sequence in the authoritative plan.

## Phase And Mode

- phase: `pre-launch`
- default mode: `A-autonomous`
- merge policy: `merge on green CI; no human PR code review (Ed, 2026-09-30)`
- security review: PR4, PR5, PR6a, PR6b, PR6c, PR7, and PR8 require the full independent quality
  reviewer + acceptance judge pair before merge.
- merge method: `gh pr merge --squash`

## Role Resolution

Resolve implementer, quality reviewer, and acceptance judge roles at runtime from
`~/Herd/brain/agents.json` by following `~/Herd/brain/playbooks/resolve-agent-role.md`. This profile
does not pin a harness map.

## Hard Gate

- command: `composer ready`
- order: IDE helper generation, Rector, root Pint, package Pint, root PHPStan/Larastan, package
  PHPStan, root Pest, both package Pest suites, root Composer audit, and both package audits.
- package suites: `composer packages:lint:check`, `composer packages:stan`,
  `composer packages:test`, and `composer packages:audit`.
- monorepo: the Laravel server is at root; path repositories are
  `packages/assay-contracts` and `packages/assay-client`.

The coordinator runs the full hard gate once on the committed candidate with a clean tree.
Implementers run only focused tests covering their changes, then static analysis and lint once before
handoff.

## CI

- status: defined; the coordinator verifies it on the pull request.
- exact required contexts: `ci (8.4)`, `ci (8.5)`, and `quality`.
- `.github/workflows/tests.yml`: root PHPStan/Larastan, root Pest, both package static-analysis and
  Pest suites, and Composer audits on PHP 8.4 and 8.5 against PostgreSQL 16.
- `.github/workflows/lint.yml`: root Pint check on PHP 8.5.
- both workflows target pushes and pull requests to `main`.

Do not rename the jobs or matrix entries without updating branch protection; required contexts are
literal strings.

## Dependency Install

- root: `composer install --no-interaction --prefer-dist`
- contracts: `composer -d packages/assay-contracts install --no-interaction --prefer-dist`
- client: `composer -d packages/assay-client install --no-interaction --prefer-dist`
- local post-install: copy `.env.example` to `.env`, run `php artisan key:generate`, and migrate.
- PostgreSQL prerequisites: local databases `assay` and `assay_app_test`; CI creates
  `assay_app_test` through its PostgreSQL 16 service.
- tests use the real `assay_app_test` PostgreSQL database configured in `phpunit.xml`, never SQLite.

## Ship Details

- branch naming: `feat/<slug>` (`fix/<slug>` for fixes, `chore/<slug>` for maintenance)
- PR target: `artisan-build/assay`, branch `main`
- release trigger: tags matching `v*` run `.github/workflows/release.yml` and `kibble:split` both
  packages in lockstep.
- release prerequisites: seed the read-only `artisan-build/assay-contracts` and
  `artisan-build/assay-client` mirrors and grant a fine-grained `SPLIT_REPO_TOKEN` with Contents:
  write before any tag is pushed. Do not create mirrors or tags as part of ordinary feature work.

## Plan And Coordination

- plan: `/Users/edgrosvenor/Herd/brain/ideas/2026-09-29-assay-prd.md` (section 2 is locked)
- build brief: `/Users/edgrosvenor/Herd/brain/projects/assay/brief-build.md`
- Solo project: Assay, id 66
- run log: `Assay v1 build - run log`

## Built For Cloud App

- app role: central customer-owned server for AI usage telemetry and curated traffic datasets.
- manifest: `config/built-for-cloud.php`.
- deployment: no environment or resource is provisioned by the scaffold. Laravel Cloud resource
  settings are managed by Cloud and must not be shadowed with hand-written variables.

## Stack Notes

- Laravel 13, Livewire 4, Flux 2, and PHP 8.3 or newer.
- Nodeless by design: no Node, npm, Vite, or frontend build step.
- Tailwind is served from `public/build/assets/app.css`; regenerate it only with
  `php artisan tailwind:optimize` and commit the output.
- `composer ready` regenerates committed IDE helper files. `.phpstorm.meta.php` remains ignored.
- Keep `phpstan-baseline.neon` empty.
