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
final class RunContent extends AssayTool
{
    protected string $name = 'run_content';

    protected string $description = 'Export raw customer bodies and messages for one app-scoped run to the MCP client and onward to its model provider, transcript, and logs.';

    public function __construct(private readonly McpRunData $runs) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Content;
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

        return $this->respond(fn () => $this->runs->content(
            (string) $validated['app'],
            (string) $validated['run_id'],
        ));
    }
}
