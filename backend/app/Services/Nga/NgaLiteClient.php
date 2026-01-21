<?php

namespace App\Services\Nga;

interface NgaLiteClient
{
    public function fetchList(int $fid, int $page = 1): string;

    public function fetchThread(int $tid, int $page = 1): string;
}
