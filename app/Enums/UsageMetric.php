<?php

declare(strict_types=1);

namespace App\Enums;

enum UsageMetric: string
{
    case InputTokens = 'input_tokens';
    case OutputTokens = 'output_tokens';
    case CacheReadInputTokens = 'cache_read_input_tokens';
    case CacheWriteInputTokens = 'cache_write_input_tokens';
    case ReasoningTokens = 'reasoning_tokens';
    case ImageInputTokens = 'image_input_tokens';
    case ImageOutputTokens = 'image_output_tokens';
    case AudioSeconds = 'audio_seconds';
    case SearchUnits = 'search_units';
}
