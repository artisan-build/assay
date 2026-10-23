<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Credential;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class McpAccess
{
    public function __construct(
        private AssayAccessPolicy $policy,
        private ActingPrincipalResolver $principals,
    ) {}

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
        $resolved = $this->resolve(required: true);
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
    private function resolve(bool $required = false): array
    {
        $principal = $required
            ? $this->principals->require(request())
            : $this->principals->resolve();

        if ($principal->delegated) {
            return [
                'principal' => $principal,
                'decision' => $this->policy->decide($principal, null),
            ];
        }

        $credential = $principal->principal;

        if (! $credential instanceof Credential) {
            return $this->nobody();
        }

        return [
            'principal' => $principal,
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
