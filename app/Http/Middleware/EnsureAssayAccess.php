<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Authorization\AssayAccessPolicy;
use App\Authorization\EffectiveAccess;
use ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\User;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureAssayAccess
{
    public const string PRINCIPAL = 'assay.principal';

    public const string DECISION = 'assay.access';

    public function __construct(
        private BearerAuthenticator $bearer,
        private ActingPrincipalResolver $principals,
        private AssayAccessPolicy $policy,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $authorization = $request->header('Authorization');
        $hasBearer = is_string($authorization)
            && preg_match('/^\s*Bearer(?:\s|$)/i', $authorization) === 1;

        if ($hasBearer) {
            $credential = $this->bearer->credential($request);

            if (! $credential instanceof Credential) {
                abort(401);
            }

            $principal = ActingPrincipal::local('bfc', $credential);
            $decision = $this->policy->projectCredential($credential);
        } else {
            $principal = $this->principals->resolve();
            $user = ! $principal->delegated && $principal->principal instanceof User
                ? $principal->principal
                : null;
            $decision = $this->policy->decide($principal, $user);
        }

        $allowed = match ($ability) {
            'usage' => $decision->usage,
            'content' => $decision->content,
            default => throw new LogicException("Unknown Assay ability [{$ability}]."),
        };

        if (! $allowed) {
            $principal->check() ? abort(403) : abort(401);
        }

        $request->attributes->set(self::PRINCIPAL, $principal);
        $request->attributes->set(self::DECISION, $decision);

        return $next($request);
    }

    public static function principal(Request $request): ActingPrincipal
    {
        $principal = $request->attributes->get(self::PRINCIPAL);

        return $principal instanceof ActingPrincipal
            ? $principal
            : throw new LogicException('The Assay access middleware did not resolve a principal.');
    }

    public static function decision(Request $request): EffectiveAccess
    {
        $decision = $request->attributes->get(self::DECISION);

        return $decision instanceof EffectiveAccess
            ? $decision
            : throw new LogicException('The Assay access middleware did not resolve an access decision.');
    }
}
