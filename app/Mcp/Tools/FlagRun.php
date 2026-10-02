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
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class FlagRun extends AssayTool
{
    protected string $name = 'flag_run';

    protected string $description = 'Set bounded labels, rating, and note on one run. These raw customer-authored values are exported to the MCP client and onward to its model provider, transcript, and logs.';

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
            'rating' => $schema->string()->enum(['pass', 'fail', '1', '2', '3', '4', '5'])->nullable(),
            'note' => $schema->string()->max(10000)->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'run_id' => ['required', 'uuid'],
            'labels' => ['required', 'array', 'max:25'],
            'labels.*' => ['string', 'max:100'],
            'rating' => ['nullable', Rule::in(['pass', 'fail', '1', '2', '3', '4', '5'])],
            'note' => ['nullable', 'string', 'max:10000'],
        ], ['run_id', 'labels', 'rating', 'note']);
        /** @var list<string> $labels */
        $labels = $validated['labels'];

        return $this->respond(fn () => $this->curation->flag(
            (string) $validated['run_id'],
            $labels,
            isset($validated['rating']) ? (string) $validated['rating'] : null,
            isset($validated['note']) ? (string) $validated['note'] : null,
        ));
    }
}
