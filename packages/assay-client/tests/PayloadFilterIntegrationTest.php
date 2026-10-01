<?php

declare(strict_types=1);

use ArtisanBuild\AssayClient\Internal\BufferedRecorder;
use ArtisanBuild\AssayClient\Records\RunInput;
use ArtisanBuild\AssayClient\Records\StepInput;
use ArtisanBuild\AssayClient\SourceInfo;
use ArtisanBuild\AssayClient\Tests\Support\CollectingDispatcher;
use ArtisanBuild\AssayClient\Tests\Support\InMemoryDropCounter;
use ArtisanBuild\AssayContracts\CaptureMode;
use ArtisanBuild\AssayContracts\Client;
use ArtisanBuild\AssayContracts\Content;
use ArtisanBuild\AssayContracts\EnvelopeCodec;
use ArtisanBuild\AssayContracts\Outcome;
use ArtisanBuild\AssayContracts\RecordType;
use ArtisanBuild\BuiltForCloudContracts\OutboundPayload;
use ArtisanBuild\BuiltForCloudContracts\PayloadFilter;
use Illuminate\Container\Container;

function filteredRecorder(Container $container, InMemoryDropCounter $drops, CollectingDispatcher $dispatcher): BufferedRecorder
{
    return new BufferedRecorder(
        driver: 'fake',
        source: new SourceInfo('vendor/source', '1.0.0'),
        client: new Client('artisan-build/assay-client', 'test'),
        environment: 'testing',
        deploy: null,
        batchSize: 100,
        retryForSeconds: 3600,
        drops: $drops,
        dispatcher: $dispatcher,
        app: $container,
    );
}

it('resolves the released filter for every record and restores all protected fields', function (): void {
    $container = new Container;
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $recorder = filteredRecorder($container, $drops, $dispatcher);
    $observed = (object) ['value' => []];
    $container->instance(PayloadFilter::class, new class($observed) implements PayloadFilter
    {
        public function __construct(private readonly stdClass $observed) {}

        public function filter(OutboundPayload $payload): OutboundPayload
        {
            $this->observed->value = [$payload->product, $payload->kind, $payload->schemaVersion, $payload->attributes];
            $data = array_fill_keys(array_keys($payload->data), 'corrupted');
            $data['content'] = ['instructions' => 'masked'];

            return new OutboundPayload('assay', 'changed', 99, $payload->disposition, $data, []);
        }
    });
    $recorder->record(new RunInput(
        type: RecordType::RunStart,
        invocationId: 'run-1',
        attempt: 1,
        at: new DateTimeImmutable('2026-10-01T12:00:00+00:00'),
        capture: CaptureMode::Full,
        sampled: true,
        subject: 'user:1',
        agent: 'App\\Ai\\Agent',
        content: new Content(['instructions' => 'secret']),
    ));
    $recorder->flush();

    $record = EnvelopeCodec::decode($dispatcher->dispatched[0]['json'])->records[0];
    expect($observed->value)->toBe(['assay', 'run.start', 1, [
        'subject' => 'user:1',
        'operation' => 'agent',
        'agent' => 'App\\Ai\\Agent',
        'capture' => 'full',
    ]])->and($record->invocationId)->toBe('run-1')
        ->and($record->attempt)->toBe(1)
        ->and($record->capture)->toBe(CaptureMode::Full)
        ->and($record->content?->toArray())->toBe(['instructions' => 'masked'])
        ->and($drops->hookTotal())->toBe(0);
});

it('silently drops null and throwing hook results exactly once', function (string $behavior): void {
    $container = new Container;
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $container->instance(PayloadFilter::class, new class($behavior) implements PayloadFilter
    {
        public function __construct(private readonly string $behavior) {}

        public function filter(OutboundPayload $payload): ?OutboundPayload
        {
            if ($this->behavior === 'throw') {
                throw new RuntimeException('HOOK-CANARY');
            }

            return null;
        }
    });

    expect(fn () => filteredRecorder($container, $drops, $dispatcher)->record(new RunInput(
        RecordType::RunStart,
        'run-1',
        1,
        new DateTimeImmutable,
    )))->not->toThrow(Throwable::class)
        ->and($dispatcher->dispatched)->toBe([])
        ->and($drops->hookTotal())->toBe(1)
        ->and($drops->transportTotal())->toBe(0);
})->with(['null', 'throw']);

it('hashes post-hook messages, deduplicates bodies, and prunes at run end', function (): void {
    $container = new Container;
    $drops = new InMemoryDropCounter;
    $dispatcher = new CollectingDispatcher;
    $container->instance(PayloadFilter::class, new class implements PayloadFilter
    {
        public function filter(OutboundPayload $payload): OutboundPayload
        {
            $data = $payload->data;

            if (($data['type'] ?? null) === 'step.start') {
                foreach ($data['content']->new_messages as $hash => $message) {
                    $data['content']->new_messages->{$hash}->text = 'masked';
                }
            }

            return new OutboundPayload(
                $payload->product,
                $payload->kind,
                $payload->schemaVersion,
                $payload->disposition,
                $data,
                $payload->attributes,
            );
        }
    });
    $recorder = filteredRecorder($container, $drops, $dispatcher);

    $step = static function (int $number, string $text): StepInput {
        $message = ['role' => 'user', 'text' => $text];
        $hash = hash('sha256', json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new StepInput(
            type: RecordType::StepStart,
            invocationId: 'run-1',
            attempt: 1,
            step: $number,
            at: new DateTimeImmutable,
            capture: CaptureMode::Full,
            sampled: true,
            content: new Content(['message_hashes' => [$hash], 'new_messages' => [$hash => $message]]),
        );
    };

    $recorder->record($step(0, 'first secret'));
    $recorder->record($step(1, 'second secret'));
    $recorder->record(new RunInput(
        type: RecordType::RunEnd,
        invocationId: 'run-1',
        attempt: 1,
        at: new DateTimeImmutable,
        capture: CaptureMode::Full,
        sampled: true,
        outcome: Outcome::Completed,
    ));
    $recorder->record($step(2, 'third secret'));
    $recorder->flush();

    $records = EnvelopeCodec::decode($dispatcher->dispatched[0]['json'])->records;
    $first = $records[0]->content?->toArray();
    $second = $records[1]->content?->toArray();
    $afterPrune = $records[3]->content?->toArray();
    $masked = ['role' => 'user', 'text' => 'masked'];
    $expectedHash = hash('sha256', json_encode(['role' => 'user', 'text' => 'masked'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    expect($first)->toBe(['message_hashes' => [$expectedHash], 'new_messages' => [$expectedHash => $masked]])
        ->and($second)->toBe(['message_hashes' => [$expectedHash], 'new_messages' => []])
        ->and($afterPrune)->toBe(['message_hashes' => [$expectedHash], 'new_messages' => [$expectedHash => $masked]])
        ->and($dispatcher->dispatched[0]['json'])->not->toContain('first secret', 'second secret', 'third secret');
});
