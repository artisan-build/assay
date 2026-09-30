<?php

declare(strict_types=1);

namespace ArtisanBuild\AssayClient\Testing;

use ArtisanBuild\AssayClient\CaptureDriver;
use ArtisanBuild\AssayClient\Recorder;
use ArtisanBuild\AssayClient\RecordInput;
use ArtisanBuild\AssayClient\SourceInfo;

final readonly class FakeDriver implements CaptureDriver
{
    /** @var list<RecordInput> */
    private array $records;

    /** @param list<RecordInput> $records */
    public function __construct(
        private string $driverName,
        private SourceInfo $sourceInfo,
        array $records,
    ) {
        $this->records = $records;
    }

    public function name(): string
    {
        return $this->driverName;
    }

    public function source(): SourceInfo
    {
        return $this->sourceInfo;
    }

    public function register(Recorder $recorder): void
    {
        foreach ($this->records as $record) {
            $recorder->record($record);
        }

        $recorder->flush();
    }
}
