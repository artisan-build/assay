<?php

declare(strict_types=1);

namespace App\Services;

use App\Authorization\AssayAccessPolicy;
use App\Authorization\EffectiveAccess;
use App\Enums\AppAction;
use App\Enums\ContentAccess;
use App\Models\ContentAccessOverride;
use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ContentAccessOverrideService
{
    public function __construct(
        private readonly AssayAccessPolicy $policy,
        private readonly AppActionRecorder $recorder,
    ) {}

    public function set(
        ActingPrincipal $principal,
        int|string $targetActorId,
        ContentAccess $access,
        ?string $reason = null,
    ): bool {
        return DB::transaction(function () use ($principal, $targetActorId, $access, $reason): bool {
            $decision = $this->currentDecision($principal);
            $target = $this->target($targetActorId);
            $targetRole = $target->roleValue();

            if (! $this->policy->mayManageContentAccess($decision, $targetRole)) {
                throw new AuthorizationException;
            }

            $override = ContentAccessOverride::query()
                ->whereKey((string) $target->getAuthIdentifier())
                ->lockForUpdate()
                ->first();

            if ($access === $this->roleDefault($targetRole)) {
                if (! $override instanceof ContentAccessOverride) {
                    return false;
                }

                $override->delete();
                $action = AppAction::ContentAccessOverrideReset;
            } else {
                if ($override instanceof ContentAccessOverride
                    && $override->access === $access
                    && $override->reason === $reason) {
                    return false;
                }

                ContentAccessOverride::query()->updateOrCreate(
                    ['actor_id' => (string) $target->getAuthIdentifier()],
                    [
                        'access' => $access,
                        'set_by_actor_id' => $decision->actor ?? throw new AuthorizationException,
                        'set_at' => now(),
                        'reason' => $reason,
                    ],
                );
                $action = AppAction::ContentAccessOverrideSet;
            }

            $this->recorder->record(
                action: $action,
                actor: AppActionActor::fromActingPrincipal($principal),
                reason: AppActionReason::Requested,
            );

            return true;
        });
    }

    public function reset(
        ActingPrincipal $principal,
        int|string $targetActorId,
    ): bool {
        return DB::transaction(function () use ($principal, $targetActorId): bool {
            $decision = $this->currentDecision($principal);
            $target = $this->target($targetActorId);

            if (! $this->policy->mayManageContentAccess($decision, $target->roleValue())) {
                throw new AuthorizationException;
            }

            $override = ContentAccessOverride::query()
                ->whereKey((string) $target->getAuthIdentifier())
                ->lockForUpdate()
                ->first();

            if (! $override instanceof ContentAccessOverride) {
                return false;
            }

            $override->delete();

            $this->recorder->record(
                action: AppAction::ContentAccessOverrideReset,
                actor: AppActionActor::fromActingPrincipal($principal),
                reason: AppActionReason::Requested,
            );

            return true;
        });
    }

    private function currentDecision(ActingPrincipal $principal): EffectiveAccess
    {
        if ($principal->delegated) {
            return $this->policy->decide($principal, null);
        }

        $identifier = $principal->identifier();

        if ((! is_int($identifier) && ! is_string($identifier))
            || ! $principal->principal instanceof User) {
            throw new AuthorizationException;
        }

        $user = User::query()->whereKey($identifier)->lockForUpdate()->first();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $this->policy->decide($principal, $user);
    }

    private function target(int|string $targetActorId): User
    {
        $target = User::query()->whereKey($targetActorId)->lockForUpdate()->first();

        if (! $target instanceof User
            || ! hash_equals((string) $targetActorId, (string) $target->getAuthIdentifier())) {
            throw (new ModelNotFoundException)->setModel(User::class, [$targetActorId]);
        }

        return $target;
    }

    private function roleDefault(?UserRole $role): ContentAccess
    {
        return match ($role) {
            UserRole::Admin => ContentAccess::Granted,
            UserRole::Member => ContentAccess::Denied,
            default => throw new AuthorizationException,
        };
    }
}
