<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;

final readonly class RecordV1
{
    public function __construct(
        public UuidV7 $recordId,
        public string $source,
        public RecordType $type,
        public Operation $operation,
        public Timestamp $at,
        public CaptureMode $capture,
        public bool $sampled,
        public ?string $invocationId = null,
        public ?int $attempt = null,
        public ?string $parentInvocationId = null,
        public ?string $parentToolInvocationId = null,
        public ?int $step = null,
        public ?string $toolInvocationId = null,
        public ?string $subject = null,
        public ?Usage $usage = null,
        public ?Model $model = null,
        public ?Content $content = null,
    ) {
        if ($this->source === '') {
            throw new InvalidEnvelope('Record source must be a non-empty string.');
        }

        if ($this->attempt !== null && $this->attempt < 1) {
            throw new InvalidEnvelope('Record attempt must be positive.');
        }

        if ($this->step !== null && $this->step < 0) {
            throw new InvalidEnvelope('Record step must be non-negative.');
        }

        if ($this->content !== null && $this->capture !== CaptureMode::Full) {
            throw new InvalidEnvelope('Record content requires full capture.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = RecordType::tryFrom(Shape::string($data, 'type', 'record'));
        $operation = Operation::tryFrom(Shape::string($data, 'operation', 'record'));
        $capture = CaptureMode::tryFrom(Shape::string($data, 'capture', 'record'));

        if ($type === null || $operation === null || $capture === null) {
            throw new InvalidEnvelope('Record type, operation, or capture mode is unsupported.');
        }

        return new self(
            recordId: new UuidV7(Shape::string($data, 'record_id', 'record')),
            source: Shape::string($data, 'source', 'record'),
            type: $type,
            operation: $operation,
            at: new Timestamp(Shape::string($data, 'at', 'record')),
            capture: $capture,
            sampled: Shape::boolean($data, 'sampled', 'record'),
            invocationId: Shape::optionalString($data, 'invocation_id', 'record'),
            attempt: Shape::optionalInteger($data, 'attempt', 'record'),
            parentInvocationId: Shape::optionalString($data, 'parent_invocation_id', 'record'),
            parentToolInvocationId: Shape::optionalString($data, 'parent_tool_invocation_id', 'record'),
            step: Shape::optionalInteger($data, 'step', 'record'),
            toolInvocationId: Shape::optionalString($data, 'tool_invocation_id', 'record'),
            subject: Shape::optionalString($data, 'subject', 'record'),
            usage: self::usage($data),
            model: self::model($data),
            content: self::content($data),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'record_id' => (string) $this->recordId,
            'source' => $this->source,
            'type' => $this->type->value,
            'operation' => $this->operation->value,
            'invocation_id' => $this->invocationId,
            'attempt' => $this->attempt,
            'parent_invocation_id' => $this->parentInvocationId,
            'parent_tool_invocation_id' => $this->parentToolInvocationId,
            'step' => $this->step,
            'tool_invocation_id' => $this->toolInvocationId,
            'at' => (string) $this->at,
            'capture' => $this->capture->value,
            'sampled' => $this->sampled,
            'subject' => $this->subject,
            'usage' => $this->usage?->toArray(),
            'model' => $this->model?->toArray(),
            'content' => $this->content?->jsonSerialize(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $data */
    private static function usage(array $data): ?Usage
    {
        if (! array_key_exists('usage', $data)) {
            return null;
        }

        return Usage::fromArray(Shape::object($data['usage'], 'usage'));
    }

    /** @param array<string, mixed> $data */
    private static function model(array $data): ?Model
    {
        if (! array_key_exists('model', $data)) {
            return null;
        }

        return Model::fromArray(Shape::object($data['model'], 'model'));
    }

    /** @param array<string, mixed> $data */
    private static function content(array $data): ?Content
    {
        if (! array_key_exists('content', $data)) {
            return null;
        }

        return Content::fromValue($data['content']);
    }
}
