<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

final readonly class Usage
{
    public function __construct(
        public int|float|null $inputTokens = null,
        public int|float|null $outputTokens = null,
        public int|float|null $cacheReadInputTokens = null,
        public int|float|null $cacheWriteInputTokens = null,
        public int|float|null $reasoningTokens = null,
        public int|float|null $imageInputTokens = null,
        public int|float|null $imageOutputTokens = null,
        public int|float|null $audioSeconds = null,
        public int|float|null $searchUnits = null,
    ) {
        foreach ($this->toArray() as $metric => $value) {
            if ($value < 0 || (is_float($value) && ! is_finite($value))) {
                throw new InvalidEnvelope("Usage metric {$metric} must be finite and non-negative.");
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: self::metric($data, 'input_tokens'),
            outputTokens: self::metric($data, 'output_tokens'),
            cacheReadInputTokens: self::metric($data, 'cache_read_input_tokens'),
            cacheWriteInputTokens: self::metric($data, 'cache_write_input_tokens'),
            reasoningTokens: self::metric($data, 'reasoning_tokens'),
            imageInputTokens: self::metric($data, 'image_input_tokens'),
            imageOutputTokens: self::metric($data, 'image_output_tokens'),
            audioSeconds: self::metric($data, 'audio_seconds'),
            searchUnits: self::metric($data, 'search_units'),
        );
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
    private static function metric(array $data, string $key): int|float|null
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
}
