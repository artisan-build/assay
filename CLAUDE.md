# Assay

Assay is a pre-launch, self-hosted AI telemetry server for Laravel applications using `laravel/ai`.
It will collect model usage from many applications and curate opt-in captured traffic into versioned
datasets while keeping customer content on customer-owned infrastructure.

The authoritative product definition is
`/Users/edgrosvenor/Herd/brain/ideas/2026-09-29-assay-prd.md`; its section 2 is locked. Read it before
changing product behavior. The repository contains the server, contracts package, client package, and
quality/release tooling.

## Product Boundaries

- Usage capture is the default; full-content capture is optional.
- Assay does not provide built-in redaction. Applications will own filtering through the shared
  outbound payload hook defined by the product plan.
- v1 does not include model prices, budgets, invoice reconciliation, replay, scoring, non-`laravel/ai`
  drivers, or multi-tenant hosting.
- Do not redesign locked product behavior during maintenance or infrastructure changes.

## Workflow

Read `.solo/workflow.md` for repository policy, gates, CI, package commands, release prerequisites,
and role resolution. The hard gate is `composer ready`.

## Stack

- PHP 8.4+ and Laravel 13.
- PostgreSQL for local and CI tests; do not substitute SQLite for production-facing behavior.
- Root Laravel server plus `packages/assay-contracts` and `packages/assay-client` Composer packages.
- Nodeless: no Node, npm, Vite, or frontend build step.

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. Regenerate that
bundle only with `php artisan tailwind:optimize`, then commit the output.

## Built For Cloud

`artisan-build/built-for-cloud` provides the server authentication and credential foundation. Assay's
manifest lives at `config/built-for-cloud.php`. Laravel Cloud injects configuration for provisioned
resources; never add values that shadow Cloud-managed resource variables.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed because the gate regenerates them and
PHPStan scans the model helper. `.phpstorm.meta.php` stays ignored because it contains machine paths.

## Static Analysis

Root PHPStan/Larastan runs at level 6 and includes both packages. Each package also owns an independent
PHPStan configuration and suite. Keep `phpstan-baseline.neon` empty; fix findings instead of expanding it.
