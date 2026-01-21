<?php

namespace App\Services\Nga;

use RuntimeException;

class FixtureNgaLiteClient implements NgaLiteClient
{
    public function __construct(private readonly string $fixtureBasePath)
    {
    }

    public function fetchList(int $fid, int $page = 1): string
    {
        $path = $this->fixturePath("list-fid-{$fid}-page-{$page}.json");

        return $this->readFixture($path);
    }

    public function fetchThread(int $tid, int $page = 1): string
    {
        $path = $this->fixturePath("thread-{$tid}-page-{$page}.json");

        return $this->readFixture($path);
    }

    private function fixturePath(string $filename): string
    {
        return rtrim($this->fixtureBasePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
    }

    private function readFixture(string $path): string
    {
        if (!is_file($path)) {
            throw new RuntimeException("Fixture not found: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read fixture: {$path}");
        }

        return $contents;
    }
}
