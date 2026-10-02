<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\McpRunData;
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
final class SearchRuns extends AssayTool
{
    protected string $name = 'search_runs';

    protected string $description = 'Search raw customer content within one app and export matching run metadata to the MCP client and onward to its model provider, transcript, and logs.';

    public function __construct(private readonly McpRunData $runs) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Content;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->min(1)->max(255)->required(),
            'query' => $schema->string()->min(1)->max(500)->required(),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
            'offset' => $schema->integer()->min(0)->max(10000)->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'app' => ['required', 'string', 'max:255'],
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'offset' => ['sometimes', 'integer', 'between:0,10000'],
        ], ['app', 'query', 'limit', 'offset']);

        return $this->respond(fn (): array => [
            'egress' => 'The search inspected raw customer data and exports matching metadata to the MCP client and onward to its model provider, transcript, and logs.',
            'runs' => $this->runs->search(
                (string) $validated['app'],
                (string) $validated['query'],
                (int) ($validated['limit'] ?? 25),
                (int) ($validated['offset'] ?? 0),
            ),
        ]);
    }
}
