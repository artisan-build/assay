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
        <p>The client applies your payload filter before its encrypted queued job is created. Accepted content is then stored in this Assay installation's PostgreSQL database as per-record content and deduplicated message bodies. Assay performs no redaction, PII detection, or content classification.</p>
    </section>

    <section data-testid="risk-access">
        <h2>Who can read content</h2>
        <p>Every admitted Owner, Admin, and Member can view usage. Owners always have content access, Admins receive it by role default, and Members do not. Owners can override Admin or Member access; a content-capable Admin can override Members. Returning a person to their role default removes the override.</p>
    </section>

    <section data-testid="risk-retention">
        <h2>Retention and erasure model</h2>
        <p>The frozen operational defaults are 30 days for run content, 395 days for usage metadata, and 365 days for curated dataset items. Curated datasets are intentional copies and can outlive the run content from which they were created.</p>
        <p>The content-store registry, retention jobs, erasure state machine, datasets, and pseudonymous tombstones are forthcoming surfaces. Tombstones are pseudonymous, not anonymous: usage remains correlatable without retaining the erased subject identifier.</p>
        <p>Infrastructure backups are outside Assay's deletion guarantee. Operators should keep backup retention no longer than the content promise they make to users. In the forthcoming erasure surface, <code>assay:erasures:reapply</code> is a required post-restore runbook step; the command does not ship in this release.</p>
    </section>

    <section data-testid="risk-queue-egress">
        <h2>Queue and egress</h2>
        <p>The client projects source events to primitive data, applies the hook, and places only an encrypted queued job on the host queue. Delivery retries are bounded to 24 hours by default; terminal failures are discarded and counted rather than retained in failed-job payloads.</p>
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
