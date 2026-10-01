<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;

final readonly class RecordV1
{
    public ?float $durationMs;

    /** @var list<ReplayInputOmission>|null */
    public ?array $replayInputsOmitted;

    /** @param list<ReplayInputOmission>|null $replayInputsOmitted */
    public function __construct(
        public UuidV7 $recordId,
        public ?string $source,
        public RecordType $type,
        public ?Operation $operation,
        public Timestamp $at,
        public CaptureMode $capture,
        public ?bool $sampled,
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
        public ?string $agent = null,
        public ?string $tool = null,
        int|float|null $durationMs = null,
        public ?FinishReason $finishReason = null,
        public ?Outcome $outcome = null,
        public ?Approval $approval = null,
        public ?string $failureClass = null,
        public ?FailureCapture $failureCapture = null,
        ?array $replayInputsOmitted = null,
        public ?UuidV7 $targetRecordId = null,
    ) {
        if (is_int($durationMs)) {
            throw new InvalidEnvelope('Record duration_ms must be a float.');
        }

        $this->durationMs = $durationMs;
        $this->replayInputsOmitted = self::replayInputsOmitted($replayInputsOmitted);

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

        $this->validate();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = RecordType::tryFrom(Shape::string($data, 'type', 'record'));

        if ($type === null) {
            throw new InvalidEnvelope('Record type or capture mode is unsupported.');
        }

        if ($type === RecordType::ContentAttach) {
            return self::contentAttachFromArray($data);
        }

        $operation = self::operation($data);
        $capture = CaptureMode::tryFrom(Shape::string($data, 'capture', 'record'));

        if ($capture === null) {
            throw new InvalidEnvelope('Record type or capture mode is unsupported.');
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
            agent: Shape::optionalString($data, 'agent', 'record'),
            tool: Shape::optionalString($data, 'tool', 'record'),
            durationMs: Shape::optionalFloat($data, 'duration_ms', 'record'),
            finishReason: self::finishReason($data),
            outcome: self::outcome($data),
            approval: self::approval($data),
            failureClass: Shape::optionalString($data, 'failure_class', 'record'),
            failureCapture: self::failureCapture($data),
            replayInputsOmitted: self::replayInputsOmittedFromArray($data),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $this->validate();

        return array_filter([
            'record_id' => (string) $this->recordId,
            'source' => $this->source,
            'type' => $this->type->value,
            'target_record_id' => $this->targetRecordId === null ? null : (string) $this->targetRecordId,
            'operation' => $this->operation?->value,
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
            'agent' => $this->agent,
            'tool' => $this->tool,
            'duration_ms' => $this->durationMs,
            'finish_reason' => $this->finishReason?->value,
            'outcome' => $this->outcome?->value,
            'approval' => $this->approval?->value,
            'failure_class' => $this->failureClass,
            'failure_capture' => $this->failureCapture?->value,
            'replay_inputs_omitted' => $this->replayInputsOmitted === null
                ? null
                : array_map(static fn (ReplayInputOmission $omission): string => $omission->value, $this->replayInputsOmitted),
            'content' => $this->content?->jsonSerialize(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function validateMetadata(): void
    {
        if ($this->agent !== null) {
            self::metadataString($this->agent, 'agent');

            if ($this->operation !== Operation::Agent) {
                throw new InvalidEnvelope('Record agent is allowed only for the agent operation.');
            }
        }

        if ($this->tool !== null) {
            self::metadataString($this->tool, 'tool');

            if (! in_array($this->type, [RecordType::ToolStart, RecordType::ToolEnd, RecordType::ToolApproval], true)) {
                throw new InvalidEnvelope('Record tool is allowed only on tool records.');
            }
        }

        if ($this->durationMs !== null) {
            if (! is_finite($this->durationMs) || $this->durationMs < 0) {
                throw new InvalidEnvelope('Record duration_ms must be finite and non-negative.');
            }

            $durationAllowed = in_array($this->type, [RecordType::StepEnd, RecordType::StepFail, RecordType::ToolEnd], true)
                || ($this->type === RecordType::RunEnd && $this->operation !== Operation::Agent);

            if (! $durationAllowed) {
                throw new InvalidEnvelope('Record duration_ms is not allowed for this type and operation.');
            }
        }

        if ($this->finishReason !== null && ! in_array($this->type, [RecordType::StepEnd, RecordType::RunEnd], true)) {
            throw new InvalidEnvelope('Record finish_reason is allowed only on step.end and run.end.');
        }

        $requiresOutcome = in_array($this->type, [RecordType::RunEnd, RecordType::ToolEnd], true);

        if ($requiresOutcome !== ($this->outcome !== null)) {
            throw new InvalidEnvelope('Record outcome is required on run.end and tool.end and forbidden elsewhere.');
        }

        if (($this->type === RecordType::ToolApproval) !== ($this->approval !== null)) {
            throw new InvalidEnvelope('Record approval is required on tool.approval and forbidden elsewhere.');
        }

        if ($this->failureClass !== null) {
            self::metadataString($this->failureClass, 'failure_class');

            if (preg_match('~^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$~D', $this->failureClass) !== 1) {
                throw new InvalidEnvelope('Record failure_class must be a PHP class name.');
            }

            $failureAllowed = $this->type === RecordType::StepFail
                || $this->type === RecordType::RunFailover
                || ($this->type === RecordType::RunEnd && $this->outcome === Outcome::Failed)
                || ($this->type === RecordType::ToolEnd && $this->outcome === Outcome::Failed);

            if (! $failureAllowed) {
                throw new InvalidEnvelope('Record failure_class is not allowed for this type and outcome.');
            }
        }

        if ($this->failureCapture !== null
            && ($this->type !== RecordType::RunEnd
                || $this->operation !== Operation::Agent
                || $this->outcome !== Outcome::Failed
                || $this->sampled !== false)) {
            throw new InvalidEnvelope('Record failure_capture requires an unsampled failed agent run.end.');
        }

        if ($this->replayInputsOmitted !== null
            && ($this->type !== RecordType::RunEnd || $this->operation !== Operation::Agent)) {
            throw new InvalidEnvelope('Record replay_inputs_omitted is allowed only on agent run.end.');
        }

        if ($this->usage !== null) {
            if (! in_array($this->type, [RecordType::StepEnd, RecordType::RunEnd], true)) {
                throw new InvalidEnvelope('Record usage is allowed only on step.end and run.end.');
            }

            if ($this->operation === null) {
                throw new InvalidEnvelope('Record usage requires an operation.');
            }

            $this->usage->validateFor($this->operation);
        }
    }

    private function validateShape(): void
    {
        if ($this->type === RecordType::ContentAttach) {
            $this->validateContentAttachShape();

            return;
        }

        if ($this->source === null || $this->sampled === null) {
            throw new InvalidEnvelope('Record source and sampled are required.');
        }

        if ($this->targetRecordId !== null) {
            throw new InvalidEnvelope('Record target_record_id is allowed only on content.attach.');
        }

        $unattributedFailover = $this->type === RecordType::RunFailover && $this->invocationId === null;

        if ($unattributedFailover) {
            if ($this->operation !== null || $this->attempt !== null || $this->parentInvocationId !== null || $this->parentToolInvocationId !== null) {
                throw new InvalidEnvelope('Unattributed run.failover must omit operation and invocation metadata.');
            }

            if ($this->step !== null || $this->toolInvocationId !== null) {
                throw new InvalidEnvelope('Unattributed run.failover must omit step and tool invocation metadata.');
            }

            if ($this->model?->provider === null || $this->model->provider === ''
                || $this->model->requested === null || $this->model->requested === '') {
                throw new InvalidEnvelope('Unattributed run.failover requires model provider and requested.');
            }

            return;
        }

        if ($this->operation === null) {
            throw new InvalidEnvelope('Record operation is required.');
        }

        if ($this->invocationId === null || $this->invocationId === '') {
            throw new InvalidEnvelope('Record invocation_id is required.');
        }

        $agentAttempt = $this->operation === Operation::Agent;

        if ($agentAttempt !== ($this->attempt !== null)) {
            throw new InvalidEnvelope('Record attempt is required exactly for the agent operation.');
        }

        if (in_array($this->type, [RecordType::StepStart, RecordType::StepEnd, RecordType::StepFail], true)) {
            if ($this->operation !== Operation::Agent || $this->step === null || $this->toolInvocationId !== null) {
                throw new InvalidEnvelope('Step records require agent operation, step, and no tool_invocation_id.');
            }

            return;
        }

        if (in_array($this->type, [RecordType::ToolStart, RecordType::ToolEnd, RecordType::ToolApproval], true)) {
            if ($this->operation !== Operation::Agent || $this->toolInvocationId === null || $this->toolInvocationId === '') {
                throw new InvalidEnvelope('Tool records require agent operation and tool_invocation_id.');
            }

            return;
        }

        if ($this->step !== null || $this->toolInvocationId !== null) {
            throw new InvalidEnvelope('Run records must omit step and tool_invocation_id.');
        }
    }

    private function validate(): void
    {
        $this->validateShape();
        $this->validateMetadata();

        if ($this->type !== RecordType::ContentAttach) {
            $this->content?->validateFor($this->type, $this->operation, $this->outcome);
        }
    }

    private function validateContentAttachShape(): void
    {
        if ($this->targetRecordId === null
            || $this->invocationId === null
            || $this->invocationId === ''
            || $this->capture !== CaptureMode::Full
            || $this->content === null) {
            throw new InvalidEnvelope('content.attach requires target_record_id, invocation_id, full capture, and content.');
        }

        if ($this->source !== null
            || $this->operation !== null
            || $this->sampled !== null
            || $this->attempt !== null
            || $this->parentInvocationId !== null
            || $this->parentToolInvocationId !== null
            || $this->step !== null
            || $this->toolInvocationId !== null
            || $this->subject !== null
            || $this->usage !== null
            || $this->model !== null
            || $this->agent !== null
            || $this->tool !== null
            || $this->durationMs !== null
            || $this->finishReason !== null
            || $this->outcome !== null
            || $this->approval !== null
            || $this->failureClass !== null
            || $this->failureCapture !== null
            || $this->replayInputsOmitted !== null) {
            throw new InvalidEnvelope('content.attach contains forbidden record metadata.');
        }
    }

    /** @param array<string, mixed> $data */
    private static function contentAttachFromArray(array $data): self
    {
        $allowed = ['record_id', 'type', 'target_record_id', 'invocation_id', 'at', 'capture', 'content'];

        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new InvalidEnvelope('content.attach contains forbidden record metadata.');
        }

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidEnvelope("content.attach {$field} is required.");
            }
        }

        $capture = CaptureMode::tryFrom(Shape::string($data, 'capture', 'record'));

        if ($capture !== CaptureMode::Full) {
            throw new InvalidEnvelope('content.attach capture must be full.');
        }

        return new self(
            recordId: new UuidV7(Shape::string($data, 'record_id', 'record')),
            source: null,
            type: RecordType::ContentAttach,
            operation: null,
            at: new Timestamp(Shape::string($data, 'at', 'record')),
            capture: $capture,
            sampled: null,
            invocationId: Shape::string($data, 'invocation_id', 'record'),
            content: Content::fromValue($data['content']),
            targetRecordId: new UuidV7(Shape::string($data, 'target_record_id', 'record')),
        );
    }

    private static function metadataString(string $value, string $field): void
    {
        $length = preg_match_all('/./us', $value);

        if ($length === false || $length < 1 || $length > 255 || preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidEnvelope("Record {$field} must contain 1 to 255 characters and no control characters.");
        }
    }

    /** @param array<string, mixed> $data */
    private static function finishReason(array $data): ?FinishReason
    {
        return self::enum($data, 'finish_reason', FinishReason::class);
    }

    /** @param array<string, mixed> $data */
    private static function operation(array $data): ?Operation
    {
        if (! array_key_exists('operation', $data)) {
            return null;
        }

        $operation = Operation::tryFrom(Shape::string($data, 'operation', 'record'));

        if ($operation === null) {
            throw new InvalidEnvelope('Record operation is unsupported.');
        }

        return $operation;
    }

    /** @param array<string, mixed> $data */
    private static function outcome(array $data): ?Outcome
    {
        return self::enum($data, 'outcome', Outcome::class);
    }

    /** @param array<string, mixed> $data */
    private static function approval(array $data): ?Approval
    {
        return self::enum($data, 'approval', Approval::class);
    }

    /** @param array<string, mixed> $data */
    private static function failureCapture(array $data): ?FailureCapture
    {
        return self::enum($data, 'failure_capture', FailureCapture::class);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(array $data, string $field, string $enum): ?\BackedEnum
    {
        if (! array_key_exists($field, $data)) {
            return null;
        }

        $value = Shape::string($data, $field, 'record');
        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw new InvalidEnvelope("Record {$field} is unsupported.");
        }

        return $case;
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

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return list<ReplayInputOmission>|null
     */
    private static function replayInputsOmitted(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        if (! array_is_list($values) || $values === []) {
            throw new InvalidEnvelope('Record replay_inputs_omitted must be a non-empty list.');
        }

        foreach ($values as $value) {
            if (! $value instanceof ReplayInputOmission) {
                throw new InvalidEnvelope('Every replay_inputs_omitted value must be a ReplayInputOmission.');
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<ReplayInputOmission>|null
     */
    private static function replayInputsOmittedFromArray(array $data): ?array
    {
        if (! array_key_exists('replay_inputs_omitted', $data)) {
            return null;
        }

        $values = Shape::list($data['replay_inputs_omitted'], 'record.replay_inputs_omitted');

        return array_map(static function (mixed $value): ReplayInputOmission {
            if (! is_string($value) || ReplayInputOmission::tryFrom($value) === null) {
                throw new InvalidEnvelope('Record replay_inputs_omitted contains an unsupported value.');
            }

            return ReplayInputOmission::from($value);
        }, $values);
    }
}
