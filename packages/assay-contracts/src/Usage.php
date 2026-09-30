<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

final readonly class Usage
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $cacheReadInputTokens = null,
        public ?int $cacheWriteInputTokens = null,
        public ?int $reasoningTokens = null,
        public ?int $imageInputTokens = null,
        public ?int $imageOutputTokens = null,
        public int|float|null $audioSeconds = null,
        public int|float|null $searchUnits = null,
    ) {
        $metrics = $this->toArray();

        if ($metrics === []) {
            throw new InvalidEnvelope('Usage must contain at least one reported metric.');
        }

        foreach ($metrics as $metric => $value) {
            if ($value < 0 || (is_float($value) && ! is_finite($value))) {
                throw new InvalidEnvelope("Usage metric {$metric} must be finite and non-negative.");
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: self::tokenMetric($data, 'input_tokens'),
            outputTokens: self::tokenMetric($data, 'output_tokens'),
            cacheReadInputTokens: self::tokenMetric($data, 'cache_read_input_tokens'),
            cacheWriteInputTokens: self::tokenMetric($data, 'cache_write_input_tokens'),
            reasoningTokens: self::tokenMetric($data, 'reasoning_tokens'),
            imageInputTokens: self::tokenMetric($data, 'image_input_tokens'),
            imageOutputTokens: self::tokenMetric($data, 'image_output_tokens'),
            audioSeconds: self::numberMetric($data, 'audio_seconds'),
            searchUnits: self::numberMetric($data, 'search_units'),
        );
    }

    public function validateFor(Operation $operation): void
    {
        $allowed = match ($operation) {
            Operation::Reranking => ['input_tokens', 'search_units'],
            Operation::Transcription => [...self::textMetrics(), 'audio_seconds'],
            Operation::Image => [...self::textMetrics(), 'image_input_tokens', 'image_output_tokens'],
            Operation::Embeddings, Operation::Audio => ['input_tokens', 'output_tokens'],
            Operation::Agent, Operation::Classification => self::textMetrics(),
        };

        foreach (array_keys($this->toArray()) as $metric) {
            if (! in_array($metric, $allowed, true)) {
                throw new InvalidEnvelope("Usage metric {$metric} is not applicable to {$operation->value}.");
            }
        }
    }

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return array_filter([
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'image_input_tokens' => $this->imageInputTokens,
            'image_output_tokens' => $this->imageOutputTokens,
            'audio_seconds' => $this->audioSeconds,
            'search_units' => $this->searchUnits,
        ], static fn (int|float|null $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $data */
    private static function tokenMetric(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if (! is_int($value) || $value < 0) {
            throw new InvalidEnvelope("usage.{$key} must be a non-negative integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function numberMetric(array $data, string $key): int|float|null
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if ((! is_int($value) && ! is_float($value)) || $value < 0 || (is_float($value) && ! is_finite($value))) {
            throw new InvalidEnvelope("usage.{$key} must be a finite non-negative number.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function textMetrics(): array
    {
        return [
            'input_tokens',
            'output_tokens',
            'cache_read_input_tokens',
            'cache_write_input_tokens',
            'reasoning_tokens',
        ];
    }
}
