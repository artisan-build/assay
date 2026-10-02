<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class McpAccess
{
    public function __construct(private AssayAccessPolicy $policy) {}

    public function allows(AssayCredentialAbility $ability): bool
    {
        $decision = $this->resolve()['decision'];

        return match ($ability) {
            AssayCredentialAbility::Usage => $decision->usage,
            AssayCredentialAbility::Content => $decision->content,
        };
    }

    public function authorize(AssayCredentialAbility $ability): ActingPrincipal
    {
        $resolved = $this->resolve();
        $allowed = match ($ability) {
            AssayCredentialAbility::Usage => $resolved['decision']->usage,
            AssayCredentialAbility::Content => $resolved['decision']->content,
        };

        if (! $allowed) {
            throw new AuthorizationException('This MCP principal is not authorized for that Assay data class.');
        }

        return $resolved['principal'];
    }

    /** @return array{principal: ActingPrincipal, decision: EffectiveAccess} */
    private function resolve(): array
    {
        $request = app('request');

        if (! $request instanceof Request) {
            return $this->nobody();
        }

        $delegated = RequestAssertion::principal($request);

        if ($delegated instanceof ActingPrincipal) {
            return [
                'principal' => $delegated,
                'decision' => $this->policy->decide($delegated, null),
            ];
        }

        $credential = $request->user();

        if (! $credential instanceof Credential) {
            return $this->nobody();
        }

        return [
            'principal' => ActingPrincipal::local('bfc', $credential),
            'decision' => $this->policy->projectCredential($credential),
        ];
    }

    /** @return array{principal: ActingPrincipal, decision: EffectiveAccess} */
    private function nobody(): array
    {
        $principal = ActingPrincipal::none();

        return [
            'principal' => $principal,
            'decision' => $this->policy->decide($principal, null),
        ];
    }
}
