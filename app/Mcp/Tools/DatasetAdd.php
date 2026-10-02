<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\DatasetManager;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent(false)]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class DatasetAdd extends AssayTool
{
    protected string $name = 'dataset_add';

    protected string $description = 'Add an immutable run snapshot to a dataset. This exports raw customer data to the MCP client and onward to its model provider, transcript, and logs.';

    public function __construct(private readonly DatasetManager $datasets) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Content;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()->format('uuid')->required(),
            'run_id' => $schema->string()->format('uuid')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'dataset_id' => ['required', 'uuid'],
            'run_id' => ['required', 'uuid'],
        ], ['dataset_id', 'run_id']);

        return $this->respond(fn () => $this->datasets->add(
            (string) $validated['dataset_id'],
            (string) $validated['run_id'],
        ));
    }
}
