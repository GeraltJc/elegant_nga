<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 业务含义：统一使用固定时间点触发抓取与审计流程（Asia/Shanghai）。
$schedulerCommand = 'nga:crawl-lite-and-audit --fid=7 --recent-days=3';
// 业务含义：三次触发时间分别为凌晨 3 点、上午 10 点、下午 3 点。
$schedulerTimes = ['03:00', '10:00', '15:00'];
// 业务含义：同一套调度任务共享互斥锁，避免不同时间点并发。
$schedulerMutexName = 'nga-crawl-lite-and-audit';

foreach ($schedulerTimes as $time) {
    Schedule::command($schedulerCommand)
        ->dailyAt($time)
        ->timezone('Asia/Shanghai')
        ->withoutOverlapping()
        ->createMutexNameUsing($schedulerMutexName);
}
