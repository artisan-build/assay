<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;

it('renders the authorized risk disclosures and shipped hook recipes', function (): void {
    $user = User::query()->create(['name' => 'Risk Member', 'email' => 'risk@example.test']);
    $user->forceFill(['role' => UserRole::Member->value])->save();

    $response = $this->actingAs($user)->get(route('assay.risk'))->assertOk();

    foreach (['risk-page', 'risk-storage', 'risk-sampling-subject', 'risk-access', 'risk-retention', 'risk-queue-egress', 'risk-hook-recipes'] as $marker) {
        $response->assertSee('data-testid="'.$marker.'"', false);
    }

    $response->assertSee('30 days')
        ->assertSee('395 days')
        ->assertSee('365 days')
        ->assertSee('24 hours')
        ->assertSee('pseudonymous')
        ->assertSee('never anonymous or de-identified')
        ->assertSee('On-demand exports read live state')
        ->assertSee('Retain every old dedicated key')
        ->assertSee('ASSAY_ERASURE_KEY')
        ->assertSee('separate from')
        ->assertSee('fails subject-bearing ingest closed')
        ->assertSee('Infrastructure backups', false)
        ->assertSee('assay:erasures:reapply')
        ->assertSee('before normal use is mandatory')
        ->assertSee('performs no redaction')
        ->assertSee('U+0000')
        ->assertSee('U+FFFD')
        ->assertSee('MCP client is an additional processor')
        ->assertSee('Content tools export raw customer data')
        ->assertSee('default MCP credential is usage-only')
        ->assertDontSee('forthcoming MCP surface')
        ->assertSee('PayloadFilter')
        ->assertSee('OutboundPayload')
        ->assertSee('PayloadDisposition::Droppable')
        ->assertSee('PassThroughPayloadFilter')
        ->assertSee('Drop one agent')
        ->assertSee('Mask known content fields')
        ->assertSee('Hash known identifiers in content')
        ->assertSee('Keep usage and drop content')
        ->assertSee('SensitiveAgent')
        ->assertSee('[masked]')
        ->assertSee('hash_hmac')
        ->assertSee("unset(\$data['content'])", false)
        ->assertSee('encrypted queued jobs')
        ->assertSee('failed_jobs')
        ->assertSee('remains encrypted in both')
        ->assertSee('72 hours by default')
        ->assertSee('prunes this bounded residue automatically')
        ->assertSee('cannot write erased content back into Assay')
        ->assertSee('512 KiB')
        ->assertSee('assay.subject')
        ->assertSee('Usage is never sampled away')
        ->assertSee('Standalone non-agent operations are excluded')
        ->assertSee('displays as unknown')
        ->assertSee("payload->product !== 'assay'", false);
});

it('keeps the risk page behind admission', function (): void {
    $this->get(route('assay.risk'))->assertUnauthorized();
});
