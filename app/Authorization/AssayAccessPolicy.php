<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Enums\ContentAccess;
use App\Models\ContentAccessOverride;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\RolePolicy;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;

final class AssayAccessPolicy
{
    public function decide(ActingPrincipal $principal, ?User $user): EffectiveAccess
    {
        $actor = $this->actorId($principal);
        $role = $this->admittedRole($principal, $user, $actor);

        if ($actor === null || $role === null) {
            return $this->notAdmitted($actor);
        }

        return $this->effective($actor, $role);
    }

    public function project(User $user): EffectiveAccess
    {
        $actor = (string) $user->getAuthIdentifier();
        $role = $user->status === 'active' && RolePolicy::canUseProduct($user->role)
            ? $user->roleValue()
            : null;

        return $role instanceof UserRole
            ? $this->effective($actor, $role)
            : $this->notAdmitted($actor);
    }

    public function mayManageContentAccess(
        EffectiveAccess $actor,
        ?UserRole $targetRole = null,
    ): bool {
        if (! $actor->content || ! in_array($actor->role, [UserRole::Owner, UserRole::Admin], true)) {
            return false;
        }

        if ($targetRole === null) {
            return true;
        }

        $mayManageRole = match ($targetRole) {
            UserRole::Admin => $actor->role === UserRole::Owner,
            UserRole::Member => true,
            default => false,
        };

        return $mayManageRole;
    }

    private function effective(string $actor, UserRole $role): EffectiveAccess
    {
        if ($role === UserRole::Owner) {
            return new EffectiveAccess(
                usage: true,
                content: true,
                source: ContentAccessSource::Owner,
                redundant: false,
                role: $role,
                actor: $actor,
            );
        }

        $roleDefault = $role === UserRole::Admin;
        $override = ContentAccessOverride::query()->find($actor);

        if (! $override instanceof ContentAccessOverride) {
            return new EffectiveAccess(
                usage: true,
                content: $roleDefault,
                source: ContentAccessSource::RoleDefault,
                redundant: false,
                role: $role,
                actor: $actor,
            );
        }

        $content = $override->access === ContentAccess::Granted;

        return new EffectiveAccess(
            usage: true,
            content: $content,
            source: ContentAccessSource::Override,
            redundant: $content === $roleDefault,
            role: $role,
            actor: $actor,
        );
    }

    private function notAdmitted(?string $actor): EffectiveAccess
    {
        return new EffectiveAccess(
            usage: false,
            content: false,
            source: ContentAccessSource::NotAdmitted,
            redundant: false,
            role: null,
            actor: $actor,
        );
    }

    private function actorId(ActingPrincipal $principal): ?string
    {
        $identifier = $principal->identifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            return null;
        }

        $actor = (string) $identifier;

        return $actor === '' ? null : $actor;
    }

    private function admittedRole(ActingPrincipal $principal, ?User $user, ?string $actor): ?UserRole
    {
        if ($principal->delegated) {
            return $this->delegatedRole($principal);
        }

        if ($actor === null
            || ! $principal->principal instanceof User
            || ! $user instanceof User
            || ! hash_equals($actor, (string) $user->getAuthIdentifier())
            || $user->status !== 'active'
            || ! RolePolicy::canUseProduct($user->role)) {
            return null;
        }

        return $user->roleValue();
    }

    private function delegatedRole(ActingPrincipal $principal): ?UserRole
    {
        if (! $principal->principal instanceof DelegatedActor
            || ! $principal->principal->isActive()) {
            return null;
        }

        return match ($principal->role) {
            ConsoleRole::Admin => UserRole::Admin,
            ConsoleRole::Member => UserRole::Member,
            default => null,
        };
    }
}
