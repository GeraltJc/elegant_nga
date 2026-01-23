<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Nga\FixtureNgaLiteClient;
use App\Services\Nga\HttpNgaLiteClient;
use App\Services\Nga\NgaLiteCrawler;
use App\Services\Nga\NgaLiteListParser;
use App\Services\Nga\NgaLitePayloadDecoder;
use App\Services\Nga\NgaPostContentProcessor;
use App\Services\Nga\NgaLiteThreadParser;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('nga:crawl-lite {--source=http} {--fid=7} {--list-page=1} {--max-post-pages=5} {--recent-days=3} {--start-at=} {--end-at=} {--fixtures=}', function () {
    $source = (string) $this->option('source');
    $fid = (int) $this->option('fid');
    $listPage = (int) $this->option('list-page');
    $maxPostPages = (int) $this->option('max-post-pages');
    $recentDays = $this->option('recent-days');
    $recentDays = $recentDays === null ? null : (int) $recentDays;
    $startAt = (string) $this->option('start-at');
    $endAt = (string) $this->option('end-at');
    $windowStart = null;
    $windowEnd = null;

    if ($startAt !== '') {
        try {
            $windowStart = \Carbon\CarbonImmutable::parse($startAt, 'Asia/Shanghai');
        } catch (\Throwable) {
            $this->error('start-at 格式不合法，示例：2026-01-23 17:48:00');
            return;
        }
    }

    if ($endAt !== '') {
        try {
            $windowEnd = \Carbon\CarbonImmutable::parse($endAt, 'Asia/Shanghai');
        } catch (\Throwable) {
            $this->error('end-at 格式不合法，示例：2026-01-23 17:50:00');
            return;
        }
    }

    if ($windowStart !== null && $windowEnd !== null && $windowStart->gt($windowEnd)) {
        $this->error('start-at 不能晚于 end-at');
        return;
    }

    $decoder = new NgaLitePayloadDecoder();
    $listParser = new NgaLiteListParser($decoder);
    $threadParser = new NgaLiteThreadParser($decoder);

    // fixture 用于回放测试，默认走 HTTP 访客抓取
    if ($source === 'fixture') {
        $fixtures = (string) $this->option('fixtures');
        $client = new FixtureNgaLiteClient($fixtures !== '' ? $fixtures : base_path('tests/Fixtures/nga'));
    } else {
        $client = new HttpNgaLiteClient($fid);
    }

    $contentProcessor = NgaPostContentProcessor::makeDefault();
    $crawler = new NgaLiteCrawler($client, $listParser, $threadParser, $contentProcessor);
    $result = $crawler->crawlForum($fid, $maxPostPages, $recentDays, $listPage, $windowStart, $windowEnd);

    $this->info("Threads upserted: {$result['threads']}");
    $this->info("Posts upserted: {$result['posts']}");
})->purpose('Crawl NGA HTML list/detail as guest and persist threads/posts');

Artisan::command('nga:crawl-thread {tid} {--source=http} {--max-post-pages=5} {--force} {--fixtures=}', function () {
    $source = (string) $this->option('source');
    $tid = (int) $this->argument('tid');
    $maxPostPages = (int) $this->option('max-post-pages');
    $force = (bool) $this->option('force');

    if ($tid <= 0) {
        $this->error('tid 必须为正整数');
        return;
    }

    $decoder = new NgaLitePayloadDecoder();
    $listParser = new NgaLiteListParser($decoder);
    $threadParser = new NgaLiteThreadParser($decoder);

    // fixture 用于回放测试，默认走 HTTP 访客抓取
    if ($source === 'fixture') {
        $fixtures = (string) $this->option('fixtures');
        $client = new FixtureNgaLiteClient($fixtures !== '' ? $fixtures : base_path('tests/Fixtures/nga'));
    } else {
        $client = new HttpNgaLiteClient(7);
    }

    $contentProcessor = NgaPostContentProcessor::makeDefault();
    $crawler = new NgaLiteCrawler($client, $listParser, $threadParser, $contentProcessor);
    $result = $crawler->crawlSingleThread($tid, $maxPostPages, $force);

    $this->info("Thread upserted: {$result['thread']}");
    $this->info("Posts upserted: {$result['posts']}");
})->purpose('Crawl single NGA thread by tid and persist');
