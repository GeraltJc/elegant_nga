<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Nga\FixtureNgaLiteClient;
use App\Services\Nga\HttpNgaLiteClient;
use App\Services\Nga\NgaLiteCrawler;
use App\Services\Nga\NgaLiteListParser;
use App\Services\Nga\NgaLitePayloadDecoder;
use App\Services\Nga\NgaLiteThreadParser;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('nga:crawl-lite {--source=http} {--fid=7} {--list-page=1} {--max-post-pages=5} {--recent-days=3} {--fixtures=}', function () {
    $source = (string) $this->option('source');
    $fid = (int) $this->option('fid');
    $listPage = (int) $this->option('list-page');
    $maxPostPages = (int) $this->option('max-post-pages');
    $recentDays = $this->option('recent-days');
    $recentDays = $recentDays === null ? null : (int) $recentDays;

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

    $crawler = new NgaLiteCrawler($client, $listParser, $threadParser);
    $result = $crawler->crawlForum($fid, $maxPostPages, $recentDays, $listPage);

    $this->info("Threads upserted: {$result['threads']}");
    $this->info("Posts upserted: {$result['posts']}");
})->purpose('Crawl NGA HTML list/detail as guest and persist threads/posts');
