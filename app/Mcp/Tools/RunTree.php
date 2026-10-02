<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\UsageDashboard;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RunTree extends AssayTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'run_tree';

    protected string $description = 'Return at most 500 metadata-only nodes in one app-scoped run tree. Never returns bodies, messages, tool arguments/results, notes, or exception messages.';

    public function __construct(private readonly UsageDashboard $dashboard) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Usage;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->min(1)->max(255)->required(),
            'run_id' => $schema->string()->format('uuid')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'app' => ['required', 'string', 'max:255'],
            'run_id' => ['required', 'uuid'],
        ], ['app', 'run_id']);

        return $this->respond(fn () => $this->dashboard->runTreeMetadata(
            (string) $validated['app'],
            (string) $validated['run_id'],
        ));
    }
}
