<?php

declare(strict_types=1);

namespace Tests\Support;

use League\Flysystem\DecoratedAdapter;

final class CountingFilesystemAdapter extends DecoratedAdapter
{
    public int $listCalls = 0;

    public int $listedItems = 0;

    public int $reads = 0;

    public bool $usedDeepListing = false;

    public function read(string $path): string
    {
        $this->reads++;

        return parent::read($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $this->listCalls++;
        $this->usedDeepListing = $this->usedDeepListing || $deep;

        foreach (parent::listContents($path, $deep) as $item) {
            $this->listedItems++;

            yield $item;
        }
    }

    public function resetCounts(): void
    {
        $this->listCalls = 0;
        $this->listedItems = 0;
        $this->reads = 0;
        $this->usedDeepListing = false;
    }
}
