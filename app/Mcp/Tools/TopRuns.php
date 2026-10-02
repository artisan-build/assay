<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Enums\UsageMetric;
use App\Services\UsageDashboard;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class TopRuns extends AssayTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'top_runs';

    protected string $description = 'List bounded run metadata ordered by observed usage. Never returns messages, arguments, results, bodies, notes, or exception messages.';

    public function __construct(private readonly UsageDashboard $dashboard) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Usage;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()->enum(UsageMetric::class)->required(),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
            'offset' => $schema->integer()->min(0)->max(10000)->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'metric' => ['required', 'string', Rule::enum(UsageMetric::class)],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'offset' => ['sometimes', 'integer', 'between:0,10000'],
        ], ['metric', 'limit', 'offset']);

        return $this->respond(fn () => $this->dashboard->topRuns(
            UsageMetric::from((string) $validated['metric']),
            false,
            (int) ($validated['limit'] ?? 25),
            (int) ($validated['offset'] ?? 0),
        ));
    }
}
