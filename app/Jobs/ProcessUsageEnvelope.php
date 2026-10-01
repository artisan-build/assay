<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\RecordV1;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessUsageEnvelope implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public readonly string $appRef,
        public readonly string $receivedAt,
        public readonly array $envelope,
    ) {}

    /** @param list<array{index: int, record_id: string}> $rejectedContentAttaches */
    public static function fromContract(
        string $appRef,
        string $receivedAt,
        EnvelopeV1 $envelope,
        array $rejectedContentAttaches = [],
    ): self {
        $records = array_map(static function (RecordV1 $record): array {
            $data = $record->toArray();

            if ($record->content !== null) {
                $data['content'] = $record->content->toJson();
            }

            return $data;
        }, $envelope->records);

        foreach ($rejectedContentAttaches as $rejected) {
            array_splice($records, $rejected['index'], 0, [[
                'record_id' => $rejected['record_id'],
                'type' => 'content.attach',
                'rejection' => 'metadata_smuggling',
            ]]);
        }

        return new self($appRef, $receivedAt, [
            'envelope_id' => (string) $envelope->envelopeId,
            'sent_at' => (string) $envelope->sentAt,
            'client' => $envelope->client->toArray(),
            'sources' => array_map(static fn ($source): array => $source->toArray(), $envelope->sources),
            'environment' => $envelope->environment,
            'deploy' => $envelope->deploy,
            'dropped_transport_total' => $envelope->droppedTransportTotal,
            'dropped_hook_total' => $envelope->droppedHookTotal,
            'records' => $records,
        ]);
    }

    public function handle(UsageIngestProcessor $processor): void
    {
        $processor->process($this->appRef, $this->receivedAt, $this->envelope);
    }
}
