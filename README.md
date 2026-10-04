# Assay

Assay is a self-hosted AI telemetry server for Laravel applications using
[`laravel/ai`](https://laravel.com/docs/ai). It collects complete model-usage telemetry from multiple
applications and can capture selected traffic into curated, versioned datasets. The server, its database,
queue, object storage, prompts, and responses remain on infrastructure controlled by the operator.

Assay records usage by provider, model, application, environment, operation, agent, and opaque subject. It
supports traffic review, labels and ratings, dataset curation, on-demand JSONL export, and MCP access. It
does not ship model prices, reconcile invoices, replay or score runs, or provide built-in redaction.

## Repository Layout

- `/` is the Assay Laravel server.
- `packages/assay-contracts` contains the framework-independent Envelope v1 contract.
- `packages/assay-client` is the Laravel package installed in reporting applications.
- `docs/dataset-export-v1.md` defines the versioned dataset export format.

The packages are Composer path repositories in this monorepo. Their split repositories are a release
prerequisite, so the installation instructions below do not claim that public package mirrors already
exist.

## Server Setup

Assay requires PHP 8.4 or newer, Composer, PostgreSQL, a durable queue, and a scheduler. From a source
checkout:

```bash
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
php artisan migrate --force
```

Configure the normal Laravel database, cache, queue, mail, filesystem, and application URL settings. Set a
dedicated `ASSAY_ERASURE_KEY` containing at least 32 random bytes, either raw or encoded with the `base64:`
prefix; do not reuse `APP_KEY`. The erasure journal is written to the application's default filesystem
disk, so no extra storage configuration is required; set a backup policy for that disk before accepting
traffic. Run a queue worker and `php artisan schedule:work`, or invoke Laravel's scheduler every minute.

Laravel Cloud supplies environment values for attached resources. Do not add application environment
values that shadow Cloud-managed database, cache, queue, or storage configuration.

The public package landing is `GET /`. Sign in through its **Open application** entry. The Built for Cloud
package UI at `/settings` provides role-aware member management, installation credentials, sessions, and
managed-authority transitions. Personal credential management is intentionally absent. Assay uses the
released package views and assets without host copies or overrides.

## Reporting Applications

Until the split package repositories are released, install the client from a local Assay checkout by adding
both package directories as Composer path repositories in the reporting application:

```bash
composer config repositories.assay-contracts path /absolute/path/to/assay/packages/assay-contracts
composer config repositories.assay-client path /absolute/path/to/assay/packages/assay-client
composer require artisan-build/assay-client:^1.0
php artisan vendor:publish --tag=assay-config
```

The client requires Laravel 13 and supports `laravel/ai` 1.x. It is source-agnostic internally and binds its
included `laravel/ai` driver by default. The package is inert when no supported capture source is installed
or when either transport setting is absent.

In Assay, an admitted Owner, Admin, or Member can open `/settings/credentials/installation` and issue the
displayed `assay.ingest` credential for one reporting installation. Save the bearer secret when shown; it
cannot be recovered. Configure the reporting application with the complete ingest endpoint and that secret:

```dotenv
ASSAY_URL=https://assay.example.test/ingest
ASSAY_TOKEN=<installation credential shown once>
ASSAY_CAPTURE=usage
```

An installation credential maps only to Built for Cloud's `consumption` purpose. It can submit envelopes
for the installation named by the credential, but it has no `assay.usage` or `assay.content` ability and
cannot enter the human UI. App identity comes from the credential, never from request data.

Set an opaque application subject before an AI root begins when subject-level reporting or erasure is
needed:

```php
use Illuminate\Support\Facades\Context;

Context::add('assay.subject', 'user:123');
```

Assay does not resolve this value. Missing subjects are stored as `unknown`. An Assay application subject
is unrelated to a Built for Cloud credential subject.

## Capture And Sampling

`usage` is the default capture mode. It records identifiers and tree linkage, operation and agent class,
provider and requested/responded model, tool names, timings, outcomes, approval outcomes, failure classes,
and reported usage metrics. It does not record prompt, response, tool argument/result, or exception-message
content.

`full` adds supported instructions, messages, responses, structured output, tool arguments/results,
exception messages, and non-agent inputs. Attachments, media bytes and locators, embedding vectors,
credentials, headers, endpoints, provider options, continuation tokens, replay blocks, and raw provider
responses are always excluded.

Usage is never sampled. Full capture samples once per root and applies the result to the entire tree.
`ASSAY_SAMPLE_RATE` defaults to `1`; exact agent-class overrides live in `agent_sample_rates` in the
published config. `ASSAY_ALWAYS_ON_FAILURE` defaults to `true`: unsampled agent trees send usage immediately
and retain post-filter content only in a bounded in-process buffer. Success discards it. Agent or step
failure sends retained content as `content.attach`; buffer overflow is reported as truncated. Standalone
non-agent operations have no failure event and are not covered by always-on-failure capture.

## Payload Filtering And Redaction

Assay performs no redaction, PII detection, or content classification. The reporting application owns any
masking, hashing, suppression, or dropping policy by rebinding
`ArtisanBuild\BuiltForCloudContracts\PayloadFilter` in its service provider. The default binding is pass
through. The hook runs after deny-by-default projection and before queue persistence.

Every custom filter must branch on `$payload->product` first and return unhandled products unchanged. The
authenticated risk guide at `/assay/risk` includes complete product-first recipes for all four supported
patterns: drop one agent, mask known content fields, hash known identifiers, and keep usage while dropping
content. A throwing filter drops Assay telemetry and increments the hook-drop total rather than sending
unfiltered data.

## Access Model

The three abilities are literal and none implies another:

| Ability | Access |
|---|---|
| `assay.ingest` | Submit envelopes only; installation credentials only |
| `assay.usage` | Usage dashboards, metadata, subjects, CSV, and usage MCP tools |
| `assay.content` | Captured content, curation, datasets, export, search, and subject erasure |

Every admitted person has usage access. Owners always have content access, Admins have content by role
default, and Members do not. Owners can override Admin and Member content access. An Admin who currently has
content can override Members. Overrides survive removal and re-invitation, but Built for Cloud admission is
always checked first. A content reader needs both `assay.usage` and `assay.content`.

## Retention And Erasure

The default retention periods are 30 days for run content, 395 days for usage metadata, and 365 days from
addition for dataset items. `ASSAY_DATASET_RETENTION_DAYS=no-expiry` is explicit no-expiry. A live dataset
item pins its source run and ancestors' usage metadata, not their messages or content blobs, until the item
expires or is removed. Scheduled pruning is bounded and uses the code-owned content-store registry.

The content-scoped MCP `delete_subject` tool uses preview then confirm. It removes matching content from
registered live stores and dataset items, blocks delayed pre-cutoff writes, and leaves token counts under a
versioned `deleted:<hmac>` tombstone. Tombstones are pseudonymous, not anonymous: activity remains
correlatable. Activity after the cutoff is admitted.

Erasure does not reach infrastructure backups. Keep backup retention no longer than the content promise
made to users. After restoring a database snapshot, open a shell in the intended Assay server environment
and reapply journal entries newer than the snapshot before normal use:

```bash
php artisan assay:erasures:reapply --since=2026-10-01T12:00:00Z
```

Assay maintenance commands run in the environment where the server process executes and do not provide a
`--local` switch. Select the local or remote server shell deliberately before invoking them. By contrast,
Built for Cloud state-changing commands can use its remote command runner; examples for those commands below
include `--local` unless remote execution is the stated intent.

Retention, stale-run reconciliation, held content-attach reconciliation, and encrypted queue-residue
pruning are scheduled. To probe them manually, run the Assay command directly inside the intended server
environment:

```bash
php artisan assay:retention:prune
php artisan assay:reconcile-stale-runs
php artisan assay:reconcile-content-attaches
php artisan assay:queue:prune-residue
```

## Dataset Export

Content-capable people can curate runs at `/assay/datasets`, then request the documented versioned JSONL
export. Exports are rendered on demand from live rows and are never stored as files. A download URL expires
after 15 minutes, is not a bearer capability, and requires the same requesting principal to still hold
content access. Later subject erasure is therefore reflected in later renders. See
[`docs/dataset-export-v1.md`](docs/dataset-export-v1.md) for the schema and `replay_fidelity` rules.

## MCP Egress

MCP credentials are operator-created credentials, not installation credentials and not personal UI
credentials. The default MCP grant is usage-only. A content credential must separately carry both Assay
abilities. These local examples intentionally include `--local`; omit it only when deliberately using Built
for Cloud's remote command runner against a named Laravel Cloud environment:

```bash
php artisan bfc:credential:mint external_consumer reporting-agent --purpose=mcp --abilities=assay.usage --local
php artisan bfc:credential:mint external_consumer curation-agent --purpose=mcp --abilities=assay.usage,assay.content --local
```

A content MCP tool exports raw customer data from Assay to the MCP client and onward to that client's model
provider, transcript, and logs. Treat all of them as additional processors. Tool admission also applies the
calling person's current access cap to person-bound credentials.

Assay reports usage, not money. Its MCP instructions direct cost-reporting agents to fetch current published
provider pricing, cite the URL and retrieval date, and label results as current-list-price estimates that
exclude failed attempts, dropped telemetry, and unreported metrics.

## Queue Lifecycle

The client projects events to primitive data and runs the payload hook synchronously. It then queues an
encrypted `ShipEnvelope` job. Delivery retries for 24 hours by default; terminal failures are deleted and
counted, never left with their payload in the reporting application's `failed_jobs`. Run a worker for the
configured client queue or telemetry will remain pending.

The server also queues accepted envelopes in encrypted jobs. Pending and failed ingest residue stays
encrypted, retries cross the erasure barrier, and scheduled pruning limits failed residue to 72 hours by
default and never longer than run-content retention. Transport and hook drops are cumulative and displayed
separately.

## Local Development

Create PostgreSQL databases named `assay` and `assay_app_test`, install the root and both package dependency
sets, then run focused commands while developing. Assay is nodeless; committed assets under `public/build`
need no Node, npm, Vite, or frontend build.

`composer ready` is the hard gate. It regenerates IDE helpers, runs Rector and Pint, performs root and
package static analysis and tests, and audits every Composer lock file. CI uses PostgreSQL 16 and defines the
required `ci (8.4)`, `ci (8.5)`, and `quality` contexts.

## License

Assay is open-source software licensed under the [MIT license](LICENSE).
