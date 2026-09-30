<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayContracts;

use ArtisanBuild\AssayContracts\Internal\Shape;
use JsonException;

final class EnvelopeCodec
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES;

    public static function encode(EnvelopeV1 $envelope): string
    {
        return json_encode($envelope->toArray(), self::JSON_FLAGS);
    }

    public static function decode(string $json): EnvelopeV1
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidEnvelope('Envelope JSON is malformed.', previous: $exception);
        }

        return EnvelopeV1::fromArray(Shape::object($decoded, 'envelope'));
    }
}
