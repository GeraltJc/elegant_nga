## 验收目标

确认调度触发（scheduler）后的数据一致性：`crawl_runs`、`crawl_run_threads`、`thread_floor_audit_runs`、`thread_floor_audit_threads` 统计口径一致。

## 验收环境

- 运行方式：Docker Compose + scheduler 容器
- 时区：Asia/Shanghai
- 触发来源：`run_trigger_text = scheduler`

## 验收 SQL（已执行）

```sql
-- 1) 最新 scheduler 的 crawl_runs 汇总
select id, run_trigger_text, run_started_at,
       thread_scanned_count, thread_change_detected_count,
       thread_updated_count, http_request_count
from crawl_runs
where run_trigger_text='scheduler'
order by id desc limit 1;

-- 2) crawl_run_threads 与 crawl_runs 口径对比
select crawl_run_id,
       count(*) as thread_count,
       sum(change_detected_by_last_reply_at) as change_count,
       sum(case when error_summary is null and fetched_page_count > 0 then 1 else 0 end) as updated_success_count,
       sum(case when error_summary is null then 1 else 0 end) as success_count,
       sum(case when error_summary is not null then 1 else 0 end) as failed_count
from crawl_run_threads
where crawl_run_id=(select id from crawl_runs where run_trigger_text='scheduler' order by id desc limit 1)
group by crawl_run_id;

-- 3) 最新 scheduler 的 thread_floor_audit_runs 汇总
select id, run_trigger_text, run_started_at,
       total_thread_count, missing_thread_count,
       repaired_thread_count, partial_thread_count, failed_thread_count,
       failed_http_count, failed_parse_count, failed_db_count, failed_unknown_count
from thread_floor_audit_runs
where run_trigger_text='scheduler'
order by id desc limit 1;

-- 4) thread_floor_audit_threads 与 thread_floor_audit_runs 口径对比
select audit_run_id,
       count(*) as missing_threads,
       sum(repair_status='repaired') as repaired,
       sum(repair_status='partial') as partial,
       sum(repair_status='failed') as failed,
       sum(repair_status='skipped') as skipped,
       sum(repair_status='missing') as missing_status,
       sum(repair_error_category='http') as failed_http,
       sum(repair_error_category='parse') as failed_parse,
       sum(repair_error_category='db') as failed_db,
       sum(repair_error_category='unknown') as failed_unknown
from thread_floor_audit_threads
where audit_run_id=(select id from thread_floor_audit_runs where run_trigger_text='scheduler' order by id desc limit 1)
group by audit_run_id;
```

## 关键结果（摘要）

- `crawl_runs.id=75`：`thread_scanned_count=314`、`thread_change_detected_count=1`、`thread_updated_count=1`、失败数为 0
- `crawl_run_threads` 对应 `crawl_run_id=75`：`thread_count=314`、`change_count=1`、`updated_success_count=1`、失败数为 0
- `thread_floor_audit_runs.id=4`：`missing_thread_count=16`、`partial_thread_count=8`、`failed_thread_count=0`
- `thread_floor_audit_threads` 对应 `audit_run_id=4`：`missing_threads=16`、`partial=8`、`failed=0`，其余 8 条为 `skipped`（口径允许）

## 验收结论

`crawl_runs`/`crawl_run_threads` 与 `thread_floor_audit_runs`/`thread_floor_audit_threads` 统计口径一致，验收通过。
