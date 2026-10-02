<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\UsageDashboard;
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
final class ReliabilitySummary extends AssayTool
{
    protected string $name = 'reliability_summary';

    protected string $description = 'Return bounded reliability metadata only. Never returns customer bodies, messages, tool arguments/results, notes, or exception messages.';

    public function __construct(private readonly UsageDashboard $dashboard) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Usage;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(100)->default(100),
            'offset' => $schema->integer()->min(0)->max(10000)->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'offset' => ['sometimes', 'integer', 'between:0,10000'],
        ], ['limit', 'offset']);

        return $this->respond(fn () => $this->dashboard->reliability(
            (int) ($validated['limit'] ?? 100),
            (int) ($validated['offset'] ?? 0),
        ));
    }
}
