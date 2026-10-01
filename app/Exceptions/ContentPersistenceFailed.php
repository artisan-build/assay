<?php

declare(strict_types=1);

namespace App\Exceptions;

use PDOException;
use RuntimeException;

final class ContentPersistenceFailed extends RuntimeException
{
    public function __construct(
        public readonly string $sqlState,
        public readonly string $recordId,
    ) {
        parent::__construct("Content persistence failed for record {$recordId} (SQLSTATE {$sqlState}).");
    }

    public static function fromDatabase(PDOException $exception, string $recordId): self
    {
        $errorInfo = $exception->errorInfo;
        $candidate = is_array($errorInfo) && isset($errorInfo[0])
            ? (string) $errorInfo[0]
            : (string) $exception->getCode();
        $sqlState = preg_match('/^[A-Z0-9]{5}$/D', $candidate) === 1 ? $candidate : 'unknown';

        return new self($sqlState, $recordId);
    }
}
