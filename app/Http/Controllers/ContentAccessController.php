<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\AssayAccessPolicy;
use App\Enums\ContentAccess;
use App\Http\Middleware\EnsureAssayAccess;
use App\Services\ContentAccessOverrideService;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ContentAccessController extends Controller
{
    public function __construct(
        private readonly AssayAccessPolicy $policy,
        private readonly ContentAccessOverrideService $overrides,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(
            $this->policy->mayManageContentAccess(EnsureAssayAccess::decision($request)),
            403,
        );
        $users = User::query()->orderBy('name')->orderBy('id')->get()->map(function (User $user): array {
            $access = $this->policy->project($user);

            return [
                'actor_id' => (string) $user->getAuthIdentifier(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'usage' => $access->usage,
                'content' => $access->content,
                'source' => $access->source->value,
                'redundant' => $access->redundant,
            ];
        });

        return view('access-management', ['users' => $users]);
    }

    public function set(Request $request, string $actor): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'access' => ['required', 'string', Rule::enum(ContentAccess::class)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $changed = $this->overrides->set(
            EnsureAssayAccess::principal($request),
            $actor,
            ContentAccess::from($validated['access']),
            $validated['reason'] ?? null,
        );

        return $this->result($request, $changed);
    }

    public function reset(Request $request, string $actor): JsonResponse|RedirectResponse
    {
        $changed = $this->overrides->reset(
            EnsureAssayAccess::principal($request),
            $actor,
        );

        return $this->result($request, $changed);
    }

    private function result(Request $request, bool $changed): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['changed' => $changed])
            : redirect()->route('assay.access.index');
    }
}
