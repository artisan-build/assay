# Assay

Assay is a pre-launch, self-hosted AI telemetry server for Laravel applications using
[`laravel/ai`](https://laravel.com/docs/ai). It is designed to collect model usage across multiple
applications and curate captured traffic into versioned datasets while keeping prompts and responses
on infrastructure the customer owns.

> **Status: pre-launch scaffold.** This repository currently contains the Laravel server shell,
> package boundaries, and quality/release tooling. Telemetry capture, ingest, dashboards, curation,
> retention, deletion, and MCP behavior have not shipped yet.

## Repository Layout

- `/` - the Assay Laravel server application.
- `packages/assay-contracts` - versioned wire contracts shared by the client and server.
- `packages/assay-client` - the Laravel package installed in reporting applications.

The packages are Composer path repositories during development and are designed to be split into
read-only mirrors for lockstep releases.

## Product Boundaries

Assay will support usage-only capture by default and optional full-content capture. It will not relay
captured content to an Artisan Build SaaS, ship model price tables, perform built-in redaction, or
provide replay and scoring in v1. Full product decisions and the build sequence live in
`/Users/edgrosvenor/Herd/brain/ideas/2026-09-29-assay-prd.md`.

## Local Development

Requires PHP 8.4+, Composer, and PostgreSQL. Create local databases named `assay` and
`assay_app_test`, then run:

```bash
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
php artisan migrate
composer -d packages/assay-contracts install --no-interaction --prefer-dist
composer -d packages/assay-client install --no-interaction --prefer-dist
composer dev
```

Assay is nodeless by design. Do not add Node, npm, Vite, or a frontend build step. Static assets are
committed under `public/build`.

## Ingest Limits

The server admits envelopes up to 8 MiB, 500 records, and 16 sources by default. Deployments may tune
these limits with `ASSAY_INGEST_MAX_BODY_BYTES`, `ASSAY_INGEST_MAX_RECORDS`, and
`ASSAY_INGEST_MAX_SOURCES`. A client `batch_size` configured above the server's record limit is
unsupported.

## Quality Gate

`composer ready` is the hard gate. It regenerates IDE helpers, runs Rector and Pint, performs root
and package static analysis, runs the root and both package Pest suites, and audits every Composer
lock file.

Focused package commands are also available:

```bash
composer packages:lint:check
composer packages:stan
composer packages:test
composer packages:audit
```

CI defines the required `ci (8.4)`, `ci (8.5)`, and `quality` check contexts. The test matrix uses
PostgreSQL 16, not SQLite.

## Built For Cloud

The server uses `artisan-build/built-for-cloud` for its authentication and credential foundation.
The application manifest is owned in `config/built-for-cloud.php`. No Laravel Cloud environment or
resource has been provisioned by this scaffold.

## License

Assay is open-source software licensed under the [MIT license](LICENSE).
