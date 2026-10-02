<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Http\Controllers\LandingPage;
use ArtisanBuild\BuiltForCloud\StandaloneAccess;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\SubmissionNonce;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

function createPackageUiUser(UserRole $role): User
{
    $user = User::query()->create([
        'name' => 'UI '.$role->value,
        'email' => $role->value.'-'.Str::lower(Str::random(8)).'@example.test',
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user->refresh();
}

it('serves the package-owned Assay landing page with the canonical manifest and UI entry', function (): void {
    $route = Route::getRoutes()->getByName('bfc.landing');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('/')
        ->and($route?->getActionName())->toBe(LandingPage::class);

    $this->get('/')
        ->assertOk()
        ->assertSeeHtml('data-testid="landing"')
        ->assertSeeHtml('data-app-slug="assay"')
        ->assertSeeHtml('data-testid="landing-manifest-name"')
        ->assertSeeHtml('data-testid="landing-manifest-description"')
        ->assertSeeHtml('data-testid="landing-manifest-icon"')
        ->assertSeeHtml('data-testid="landing-manifest-product-link"')
        ->assertSeeHtml('data-testid="landing-ui-entry"')
        ->assertSee('Assay')
        ->assertSee('Self-hosted AI telemetry and dataset curation for Laravel applications.')
        ->assertSee('https://scalpels.app/img/products/transparent/assay.png', false)
        ->assertSee('https://scalpels.app/products/assay', false)
        ->assertSee(route('bfc.dashboard'), false);
});

it('shows only role-appropriate enabled package navigation', function (UserRole $role, array $present, array $absent): void {
    $user = createPackageUiUser($role);
    $response = $this->actingAs($user)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $user->auth_session_version,
    ])->get(route('bfc.ui.home'))->assertOk();

    $response->assertSeeHtml('data-testid="ui-shell"')
        ->assertSeeHtml('data-testid="ui-navigation"')
        ->assertSeeHtml('data-testid="ui-nav-session-management"')
        ->assertSeeHtml('data-testid="ui-nav-installation-credentials"')
        ->assertDontSeeHtml('data-testid="ui-nav-personal-credentials"');

    foreach ($present as $marker) {
        $response->assertSeeHtml('data-testid="'.$marker.'"');
    }

    foreach ($absent as $marker) {
        $response->assertDontSeeHtml('data-testid="'.$marker.'"');
    }
})->with([
    'owner' => [UserRole::Owner, ['ui-nav-member-management', 'ui-nav-managed-transitions'], []],
    'admin' => [UserRole::Admin, ['ui-nav-member-management'], ['ui-nav-managed-transitions']],
    'member' => [UserRole::Member, [], ['ui-nav-member-management', 'ui-nav-managed-transitions']],
]);

it('keeps member management role-gated and the disabled personal credential surface unavailable', function (): void {
    $member = createPackageUiUser(UserRole::Member);
    $before = Credential::query()->count();

    $this->actingAs($member)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $member->auth_session_version,
    ])->get(route('bfc.members.index'))
        ->assertOk()
        ->assertSeeHtml('data-testid="members-management"')
        ->assertSeeHtml('data-testid="members-list"')
        ->assertDontSeeHtml('data-testid="members-invitation-form"')
        ->assertDontSeeHtml('data-testid="members-role-form"')
        ->assertDontSeeHtml('data-testid="members-deactivation-form"');
    $this->get(route('bfc.ui.personal-credentials.index'))
        ->assertForbidden()
        ->assertDontSeeHtml('data-testid="personal-credentials-issue-option"');

    expect(Credential::query()->count())->toBe($before);
});

it('issues only mapped Assay ingest credentials without granting data or human UI authority', function (): void {
    $member = createPackageUiUser(UserRole::Member);
    $page = $this->actingAs($member)->withSession([
        StandaloneAccess::SESSION_VERSION_KEY => $member->auth_session_version,
    ])
        ->get(route('bfc.ui.installation-credentials.index'))
        ->assertOk()
        ->assertSeeHtml('data-testid="installation-credentials"')
        ->assertSeeHtml('data-testid="installation-credentials-issue-option"')
        ->assertSee('assay.ingest')
        ->assertDontSee('assay.usage')
        ->assertDontSee('assay.content');
    $content = (string) $page->getContent();

    expect(preg_match(
        '/name="'.SubmissionNonce::FIELD.'" value="([^"]+)"/',
        $content,
        $nonce,
    ))->toBe(1);
    $this->withCookie((string) config('session.cookie'), session()->getId());

    $issued = $this->post(route('bfc.ui.installation-credentials.store'), [
        SubmissionNonce::FIELD => html_entity_decode($nonce[1], ENT_QUOTES),
        'app_purpose' => 'assay.ingest',
        'kind' => 'bearer',
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'reporting-app-production',
        'name' => 'Reporting app production',
    ])->assertCreated()
        ->assertSeeHtml('data-testid="installation-credentials-delivery"')
        ->assertSee('Save this credential now');

    $credential = Credential::query()->where('subject_ref', 'reporting-app-production')->firstOrFail();
    expect($credential->purpose)->toBe(CredentialPurpose::Consumption)
        ->and($credential->subject_type)->toBe(SubjectType::Installation)
        ->and($credential->abilities)->toBeNull()
        ->and($credential->user_id)->toBeNull();

    expect(preg_match('/<strong>secret<\/strong>:\s*<code>([^<]+)<\/code>/', (string) $issued->getContent(), $delivery))->toBe(1);
    $secret = html_entity_decode($delivery[1], ENT_QUOTES);

    auth()->guard('web')->logout();
    session()->flush();

    $headers = ['Authorization' => 'Bearer '.$secret];
    $this->getJson(route('assay.dashboard'), $headers)->assertForbidden();
    $this->get(route('bfc.ui.home'), $headers)->assertRedirect(route('bfc.login', ['intended' => '/settings']));
});
