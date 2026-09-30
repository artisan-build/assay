<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;

final readonly class EnvelopeV1
{
    public const VERSION = 1;

    /** @var list<Source> */
    public array $sources;

    /** @var list<RecordV1> */
    public array $records;

    /**
     * @param  array<array-key, mixed>  $sources
     * @param  array<array-key, mixed>  $records
     */
    public function __construct(
        public UuidV7 $envelopeId,
        public Timestamp $sentAt,
        public Client $client,
        array $sources,
        public string $environment,
        public int $droppedTransportTotal,
        public int $droppedHookTotal,
        array $records,
        public ?string $deploy = null,
    ) {
        if ($this->environment === '') {
            throw new InvalidEnvelope('Environment must be a non-empty string.');
        }

        if ($this->droppedTransportTotal < 0 || $this->droppedHookTotal < 0) {
            throw new InvalidEnvelope('Drop totals must be non-negative.');
        }

        $this->sources = self::sources($sources);
        $this->records = self::records($records);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $version = Shape::integer($data, 'envelope_version', 'envelope');

        if ($version !== self::VERSION) {
            throw new InvalidEnvelope("Unsupported envelope version {$version}.");
        }

        $sources = array_map(
            static fn (mixed $source): Source => Source::fromArray(Shape::object($source, 'source')),
            Shape::list(Shape::required($data, 'sources', 'envelope'), 'sources'),
        );
        $records = array_map(
            static fn (mixed $record): RecordV1 => RecordV1::fromArray(Shape::object($record, 'record')),
            Shape::list(Shape::required($data, 'records', 'envelope'), 'records'),
        );

        return new self(
            envelopeId: new UuidV7(Shape::string($data, 'envelope_id', 'envelope')),
            sentAt: new Timestamp(Shape::string($data, 'sent_at', 'envelope')),
            client: Client::fromArray(Shape::object(Shape::required($data, 'client', 'envelope'), 'client')),
            sources: $sources,
            environment: Shape::string($data, 'environment', 'envelope'),
            droppedTransportTotal: Shape::integer($data, 'dropped_transport_total', 'envelope'),
            droppedHookTotal: Shape::integer($data, 'dropped_hook_total', 'envelope'),
            records: $records,
            deploy: Shape::optionalString($data, 'deploy', 'envelope'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'envelope_version' => self::VERSION,
            'envelope_id' => (string) $this->envelopeId,
            'sent_at' => (string) $this->sentAt,
            'client' => $this->client->toArray(),
            'sources' => array_map(static fn (Source $source): array => $source->toArray(), $this->sources),
            'environment' => $this->environment,
            'deploy' => $this->deploy,
            'dropped_transport_total' => $this->droppedTransportTotal,
            'dropped_hook_total' => $this->droppedHookTotal,
            'records' => array_map(static fn (RecordV1 $record): array => $record->toArray(), $this->records),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<array-key, mixed>  $sources
     * @return list<Source>
     */
    private static function sources(array $sources): array
    {
        if (! array_is_list($sources)) {
            throw new InvalidEnvelope('Sources must be a list.');
        }

        foreach ($sources as $source) {
            if (! $source instanceof Source) {
                throw new InvalidEnvelope('Every source must be a Source DTO.');
            }
        }

        return $sources;
    }

    /**
     * @param  array<array-key, mixed>  $records
     * @return list<RecordV1>
     */
    private static function records(array $records): array
    {
        if (! array_is_list($records)) {
            throw new InvalidEnvelope('Records must be a list.');
        }

        foreach ($records as $record) {
            if (! $record instanceof RecordV1) {
                throw new InvalidEnvelope('Every record must be a RecordV1 DTO.');
            }
        }

        return $records;
    }
}
