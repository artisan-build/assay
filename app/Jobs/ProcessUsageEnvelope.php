<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\UsageIngestProcessor;
use ArtisanBuild\AssayContracts\EnvelopeV1;
use ArtisanBuild\AssayContracts\RecordV1;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessUsageEnvelope implements ShouldQueue
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

    public static function fromContract(string $appRef, string $receivedAt, EnvelopeV1 $envelope): self
    {
        return new self($appRef, $receivedAt, [
            'envelope_id' => (string) $envelope->envelopeId,
            'sent_at' => (string) $envelope->sentAt,
            'client' => $envelope->client->toArray(),
            'sources' => array_map(static fn ($source): array => $source->toArray(), $envelope->sources),
            'environment' => $envelope->environment,
            'deploy' => $envelope->deploy,
            'dropped_transport_total' => $envelope->droppedTransportTotal,
            'dropped_hook_total' => $envelope->droppedHookTotal,
            'records' => array_map(static function (RecordV1 $record): array {
                $data = $record->toArray();

                if ($record->content !== null) {
                    $data['content'] = $record->content->toArray();
                }

                return $data;
            }, $envelope->records),
        ]);
    }

    public function handle(UsageIngestProcessor $processor): void
    {
        $processor->process($this->appRef, $this->receivedAt, $this->envelope);
    }
}
