<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;

it('renders the authorized risk disclosures and shipped hook recipes', function (): void {
    $user = User::query()->create(['name' => 'Risk Member', 'email' => 'risk@example.test']);
    $user->forceFill(['role' => UserRole::Member->value])->save();

    $response = $this->actingAs($user)->get(route('assay.risk'))->assertOk();

    foreach (['risk-page', 'risk-storage', 'risk-access', 'risk-retention', 'risk-queue-egress', 'risk-hook-recipes'] as $marker) {
        $response->assertSee('data-testid="'.$marker.'"', false);
    }

    $response->assertSee('30 days')
        ->assertSee('395 days')
        ->assertSee('365 days')
        ->assertSee('24 hours')
        ->assertSee('pseudonymous')
        ->assertSee('Infrastructure backups', false)
        ->assertSee('assay:erasures:reapply')
        ->assertSee('performs no redaction')
        ->assertSee('forthcoming MCP surface')
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
        ->assertSee('until operators prune them')
        ->assertSee("payload->product !== 'assay'", false);
});

it('keeps the risk page behind admission', function (): void {
    $this->get(route('assay.risk'))->assertUnauthorized();
});
