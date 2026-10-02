<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\RunCuration;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class LabelRun extends AssayTool
{
    protected string $name = 'label_run';

    protected string $description = 'Replace a run label set while preserving its rating and note. Labels are raw customer-authored data exported to the MCP client and onward.';

    public function __construct(private readonly RunCuration $curation) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Content;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->format('uuid')->required(),
            'labels' => $schema->array()->items($schema->string()->max(100))->max(25)->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'run_id' => ['required', 'uuid'],
            'labels' => ['required', 'array', 'max:25'],
            'labels.*' => ['string', 'max:100'],
        ], ['run_id', 'labels']);
        /** @var list<string> $labels */
        $labels = $validated['labels'];

        return $this->respond(fn () => $this->curation->label((string) $validated['run_id'], $labels));
    }
}
