<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\DatasetExporter;
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
final class DatasetExport extends AssayTool
{
    protected string $name = 'dataset_export';

    protected string $description = 'Mint the existing 15-minute, principal-bound download link for a live JSONL export. Returns a link, never raw JSONL inline; downloading exports raw customer data onward.';

    public function __construct(private readonly DatasetExporter $exports) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Content;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['dataset_id' => $schema->string()->format('uuid')->required()];
    }

    public function handle(Request $request): Response
    {
        $principal = $this->authorize();
        $validated = $this->validate($request, [
            'dataset_id' => ['required', 'uuid'],
        ], ['dataset_id']);

        return $this->respond(fn (): array => [
            'egress' => 'Following this link exports raw customer data to the authenticated MCP client and onward.',
            ...$this->exports->request((string) $validated['dataset_id'], $principal),
        ]);
    }
}
