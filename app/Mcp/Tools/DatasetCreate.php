<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Services\DatasetManager;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent(false)]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Write)]
final class DatasetCreate extends AssayTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'dataset_create';

    protected string $description = 'Create a curated dataset. Its customer-defined name is exported to the MCP client and onward to its model provider, transcript, and logs.';

    public function __construct(private readonly DatasetManager $datasets) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Content;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->min(1)->max(255)->required(),
            'retention_days' => $schema->integer()->min(1)->max(36500)->nullable(),
            'no_expiry' => $schema->boolean()->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'name' => ['required', 'string', 'max:255'],
            'retention_days' => ['nullable', 'integer', 'between:1,36500'],
            'no_expiry' => ['sometimes', 'boolean'],
        ], ['name', 'retention_days', 'no_expiry']);

        if (($validated['no_expiry'] ?? false) === true && array_key_exists('retention_days', $validated)) {
            throw ValidationException::withMessages(['retention_days' => 'Retention days and no expiry are mutually exclusive.']);
        }

        $retention = ($validated['no_expiry'] ?? false) === true
            ? null
            : (array_key_exists('retention_days', $validated) ? (int) $validated['retention_days'] : false);

        return $this->respond(fn () => $this->datasets->create((string) $validated['name'], $retention));
    }
}
