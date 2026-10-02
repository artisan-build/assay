<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\McpRunData;
use App\Services\SubjectErasure;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class DeleteSubject extends AssayTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'delete_subject';

    protected string $description = 'Preview, then erase one app-scoped subject from live content stores while leaving usage totals pseudonymised and unchanged. Subject data is exported to the MCP client and onward.';

    public function __construct(
        private readonly McpRunData $runs,
        private readonly SubjectErasure $erasures,
    ) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Content;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->min(1)->max(255)->required(),
            'subject' => $schema->string()->min(1)->max(1000)->required(),
        ];
    }

    public function preview(Request $request): Response
    {
        $this->authorize();
        $validated = $this->arguments($request);

        return $this->respond(fn () => $this->runs->erasurePreview(
            (string) $validated['app'],
            (string) $validated['subject'],
        ));
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->arguments($request);

        return $this->respond(fn () => $this->erasures->erase(
            $this->runs->appId((string) $validated['app']),
            (string) $validated['subject'],
        ));
    }

    /** @return array<string, mixed> */
    private function arguments(Request $request): array
    {
        return $this->validate($request, [
            'app' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:1000'],
        ], ['app', 'subject']);
    }
}
