<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\AssayCredentialAbility;
use App\Enums\UsageMetric;
use App\Services\UsageDashboard;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class UsageSummary extends AssayTool
{
    protected string $name = 'usage_summary';

    protected string $description = 'Summarize observed successful usage by one bounded dimension and time window. Reports usage units only, never money.';

    public function __construct(private readonly UsageDashboard $dashboard) {}

    protected function ability(): AssayCredentialAbility
    {
        return AssayCredentialAbility::Usage;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'metric' => $schema->string()->enum(UsageMetric::class)->required(),
            'group_by' => $schema->string()->enum(['app', 'environment', 'operation', 'provider', 'model', 'agent', 'subject'])->required(),
            'from' => $schema->string()->format('date-time')->required(),
            'to' => $schema->string()->format('date-time')->required(),
            'limit' => $schema->integer()->min(1)->max(100)->default(25),
            'offset' => $schema->integer()->min(0)->max(10000)->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'metric' => ['required', 'string', Rule::enum(UsageMetric::class)],
            'group_by' => ['required', Rule::in(['app', 'environment', 'operation', 'provider', 'model', 'agent', 'subject'])],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'offset' => ['sometimes', 'integer', 'between:0,10000'],
        ], ['metric', 'group_by', 'from', 'to', 'limit', 'offset']);
        $from = CarbonImmutable::parse((string) $validated['from']);
        $to = CarbonImmutable::parse((string) $validated['to']);

        if ($from->greaterThan($to) || $from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['from' => 'The usage window must be ordered and no longer than 366 days.']);
        }

        return $this->respond(fn (): array => [
            'metric' => $validated['metric'],
            'unit' => str_ends_with((string) $validated['metric'], '_seconds') ? 'seconds' : (str_ends_with((string) $validated['metric'], '_units') ? 'units' : 'tokens'),
            'observed_successful_usage' => $this->dashboard->usageSummary(
                UsageMetric::from((string) $validated['metric']),
                (string) $validated['group_by'],
                $from,
                $to,
                (int) ($validated['limit'] ?? 25),
                (int) ($validated['offset'] ?? 0),
            ),
        ]);
    }
}
