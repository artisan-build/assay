<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assay full-capture risk guide</title>
    <link rel="stylesheet" href="/build/assets/app.css">
</head>
<body>
<main data-testid="risk-page">
    <h1>Full-capture risk and operations guide</h1>

    <section data-testid="risk-storage">
        <h2>What full capture stores</h2>
        <p>Usage mode stores identifiers, model usage, timings, agent and tool names, outcomes, and failure classes. Full mode can additionally store instructions, message text, model output, structured output, tool arguments and results, exception messages, and supported non-agent inputs and outputs.</p>
        <p>The client applies your payload filter before its encrypted queued job is created. U+0000 in captured content strings is normalized to U+FFFD before delivery and again before server storage. Accepted content is then stored in this Assay installation's PostgreSQL database as per-record content and deduplicated message bodies. Assay performs no redaction, PII detection, or content classification.</p>
    </section>

    <section data-testid="risk-sampling-subject">
        <h2>Sampling, failures, and subjects</h2>
        <p>Full capture samples once per root and applies that decision to the complete agent tree, including sub-agents and linked non-agent operations. The global sample rate defaults to 1; exact per-agent-class overrides take precedence. Usage is never sampled away.</p>
        <p>Always-on-failure is enabled by default. Every record in an unsampled full-mode agent tree is sent immediately as usage capture without content, while post-filter content is kept only in process and bounded to 512 KiB per root by default. Oldest content is evicted first. Success discards the buffer; an agent or step failure sends retained content once through content-only <code>content.attach</code> records with fresh ids targeting the originals, without filtering it again, and reports complete or truncated status. Standalone non-agent operations are excluded because they have no failure event.</p>
        <p>Applications may put an opaque subject in Laravel Context at <code>assay.subject</code>. The client freezes it when the root starts and propagates it to descendants. Laravel Context crosses a queued-job boundary when it is set before dispatch. An absent subject is sent as <code>unknown</code> and displays as unknown. Assay subjects are unrelated to Built for Cloud credential subjects.</p>
    </section>

    <section data-testid="risk-access">
        <h2>Who can read content</h2>
        <p>Every admitted Owner, Admin, and Member can view usage. Owners always have content access, Admins receive it by role default, and Members do not. Owners can override Admin or Member access; a content-capable Admin can override Members. Returning a person to their role default removes the override.</p>
    </section>

    <section data-testid="risk-retention">
        <h2>Retention and erasure model</h2>
        <p>The frozen operational defaults are 30 days for run content, 395 days for usage metadata, and 365 days for curated dataset items. Curated datasets are intentional copies and can outlive the run content from which they were created.</p>
        <p>The code-owned content-store registry drives scheduled, bounded retention across live per-record content, deduplicated messages, held attaches, and content-bearing queue residue. Delete-by-subject sweeps subject-associated stores, while queued, retried, and delayed records pass through the same barrier before writing. On-demand exports read live state, so erased content cannot reappear in a later export. Dataset retention is reserved for the curation surface.</p>
        <p>Erasure replaces subjects on surviving usage metadata with versioned HMAC tombstones. Tombstones are pseudonymous, never anonymous or de-identified: usage remains correlatable without retaining the erased subject identifier. Retain old erasure-key versions during rotation; losing one disables historical subject lookup and its ingest barrier.</p>
        <p>Infrastructure backups are outside Assay's deletion guarantee. Operators must keep backup retention no longer than the content promise they make to users. After restoring a database snapshot, running <code>assay:erasures:reapply --since=&lt;snapshot-time&gt;</code> before normal use is mandatory.</p>
    </section>

    <section data-testid="risk-queue-egress">
        <h2>Queue and egress</h2>
        <p>The client projects source events to primitive data, applies the hook, and places only an encrypted queued job on the host queue. Client queue delivery retries are bounded to 24 hours by default; terminal failures are discarded and counted rather than retained in failed-job payloads.</p>
        <p>The Assay server also places accepted envelopes in encrypted queued jobs. Content-bearing jobs remain in <code>jobs</code> while queued, and encrypted payloads plus failure details remain in <code>failed_jobs</code> after a terminal failure until operators prune them; failed-job pruning is therefore part of the content-retention promise.</p>
        <p>The forthcoming MCP surface is an additional content processor and egress path when content-scoped credentials are enabled. Its operator must account for the MCP host, model provider, and calling agent in data-processing decisions.</p>
    </section>

    <section data-testid="risk-hook-recipes">
        <h2>Payload-filter recipes</h2>
        <p>Bind <code>ArtisanBuild\BuiltForCloudContracts\PayloadFilter</code> in the host application's service provider. Every filter must branch on <code>product</code> first and return the exact payload for unhandled products. Assay payloads use <code>OutboundPayload</code> with <code>PayloadDisposition::Droppable</code>; Built for Cloud supplies <code>PassThroughPayloadFilter</code> as the default.</p>

        <h3>Drop one agent</h3>
        <pre><code>public function filter(OutboundPayload $payload): ?OutboundPayload
{
    if ($payload->product !== 'assay') {
        return $payload;
    }

    return ($payload->attributes['agent'] ?? null) === App\Ai\SensitiveAgent::class
        ? null
        : $payload;
}</code></pre>

        <h3>Mask known content fields</h3>
        <pre><code>public function filter(OutboundPayload $payload): ?OutboundPayload
{
    if ($payload->product !== 'assay') {
        return $payload;
    }

    $data = $payload->data;
    if (isset($data['content']->instructions)) {
        $data['content']->instructions = '[masked]';
    }

    return new OutboundPayload($payload->product, $payload->kind, $payload->schemaVersion, PayloadDisposition::Droppable, $data, $payload->attributes);
}</code></pre>

        <h3>Hash known identifiers in content</h3>
        <pre><code>public function filter(OutboundPayload $payload): ?OutboundPayload
{
    if ($payload->product !== 'assay') {
        return $payload;
    }

    $data = $payload->data;
    if (isset($data['content']->arguments->customer_id)) {
        $data['content']->arguments->customer_id = hash_hmac('sha256', (string) $data['content']->arguments->customer_id, config('app.key'));
    }

    return new OutboundPayload($payload->product, $payload->kind, $payload->schemaVersion, PayloadDisposition::Droppable, $data, $payload->attributes);
}</code></pre>

        <h3>Keep usage and drop content</h3>
        <pre><code>public function filter(OutboundPayload $payload): ?OutboundPayload
{
    if ($payload->product !== 'assay') {
        return $payload;
    }

    $data = $payload->data;
    unset($data['content']);

    return new OutboundPayload($payload->product, $payload->kind, $payload->schemaVersion, PayloadDisposition::Droppable, $data, $payload->attributes);
}</code></pre>
    </section>
</main>
</body>
</html>
