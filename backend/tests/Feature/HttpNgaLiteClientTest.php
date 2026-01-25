<?php

namespace Tests\Feature;

use App\Services\Nga\HttpNgaLiteClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpNgaLiteClientTest extends TestCase
{
    public function test_refresh_guest_cookie_retries_and_sends_cookie(): void
    {
        // 测试时关闭 curl 日志落盘，避免污染本地文件
        $_ENV['NGA_CURL_LOG_PATH'] = '';
        $_SERVER['NGA_CURL_LOG_PATH'] = '';
        putenv('NGA_CURL_LOG_PATH=');

        Http::fakeSequence()
            ->push('ERROR:15', 403)
            ->push('guestJs=1769274224_12ai5sc', 200, [
                'Set-Cookie' => 'lastvisit=1769274229; path=/; domain=nga.178.com; secure',
            ])
            ->push('ok', 200);

        $client = new HttpNgaLiteClient(7);
        $response = $client->fetchList(7, 1);

        $this->assertSame('ok', $response);

        Http::assertSentCount(3);
        Http::assertSentInOrder([
            function (Request $request) {
                return str_contains($request->url(), 'thread.php')
                    && ! $request->hasHeader('Cookie');
            },
            function (Request $request) {
                return str_contains($request->url(), 'thread.php')
                    && ! $request->hasHeader('Cookie');
            },
            function (Request $request) {
                $cookies = $request->header('Cookie');
                $cookie = $cookies[0] ?? '';

                return str_contains($request->url(), 'thread.php')
                    && str_contains($cookie, 'lastvisit=1769274229')
                    && str_contains($cookie, 'guestJs=1769274224_12ai5sc');
            },
        ]);
    }
}
