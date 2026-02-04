export type ApiMeta = {
  page: number
  per_page: number
  total: number
  total_pages: number
}

export type ApiListResponse<DataType> = {
  data: DataType[]
  meta: ApiMeta
}

export type ApiItemResponse<DataType> = {
  data: DataType
}

export type ThreadSummary = {
  source_thread_id: number
  title: string
  title_prefix_text: string | null
  author_name: string
  thread_created_at: string | null
  last_reply_at: string | null
  reply_count_display: number
  view_count_display: number | null
  is_pinned: boolean
  is_digest: boolean
  is_truncated_by_page_limit: boolean
  truncated_at_page_number: number | null
  is_skipped_by_page_total_limit: boolean
  last_crawled_at: string | null
}

export type ThreadDetail = ThreadSummary

export type ThreadPost = {
  post_id: number
  floor_number: number
  author_name: string
  post_created_at: string | null
  content_html: string
  is_deleted_by_source: boolean
  is_folded_by_source: boolean
  content_last_changed_at: string | null
  revision_count: number
}

export type PostRevision = {
  revision_created_at: string | null
  source_edited_at: string | null
  content_html: string
  change_detected_reason: string
}

export type CrawlRunSummary = {
  id: number
  forum_id: number
  run_started_at: string | null
  run_finished_at: string | null
  run_trigger_text: string
  date_window_start: string | null
  date_window_end: string | null
  thread_scanned_count: number
  thread_change_detected_count: number
  thread_updated_count: number
  http_request_count: number
}

export type CrawlRunDetail = CrawlRunSummary & {
  new_post_count_total: number
  updated_post_count_total: number
  failed_thread_count: number
  duration_ms: number | null
}

export type CrawlRunThread = {
  id: number
  thread_id: number
  source_thread_id: number | null
  change_detected_by_last_reply_at: boolean
  detected_last_reply_at: string | null
  fetched_page_count: number
  page_limit_applied: boolean
  new_post_count: number
  updated_post_count: number
  http_request_count: number
  http_error_code: number | null
  error_summary: string | null
  started_at: string | null
  finished_at: string | null
}

export type FloorAuditRunSummary = {
  id: number
  run_started_at: string | null
  run_finished_at: string | null
  run_trigger_text: string
  repair_enabled: boolean
  total_thread_count: number
  missing_thread_count: number
  repaired_thread_count: number
  partial_thread_count: number
  failed_thread_count: number
  failed_http_count: number
  failed_parse_count: number
  failed_db_count: number
  failed_unknown_count: number
}

export type FloorAuditRunDetail = FloorAuditRunSummary & {
  duration_ms: number | null
  crawl_run_id: number | null
}

export type FloorAuditThread = {
  id: number
  audit_run_id: number
  thread_id: number
  source_thread_id: number
  max_floor_number: number
  post_count: number
  missing_floor_count: number
  ignored_floor_count: number
  repair_status: string
  repair_crawl_run_id: number | null
  repair_attempted_at: string | null
  repair_finished_at: string | null
  repair_after_max_floor_number: number | null
  repair_after_post_count: number | null
  repair_remaining_floor_count: number | null
  repair_error_category: string | null
  repair_http_error_code: number | null
  repair_error_summary: string | null
}

export type FloorAuditPost = {
  id: number
  floor_number: number
  repair_status: string
  attempt_count_before: number
  attempt_count_after: number | null
  repair_error_category: string | null
  repair_http_error_code: number | null
  repair_error_summary: string | null
}

type QueryValue = string | number | boolean | null | undefined

const buildQuery = (params?: Record<string, QueryValue>): string => {
  if (!params) {
    return ''
  }
  const searchParams = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') {
      return
    }
    searchParams.set(key, String(value))
  })
  const query = searchParams.toString()
  return query ? `?${query}` : ''
}

const requestJson = async <DataType>(
  path: string,
  params?: Record<string, QueryValue>
): Promise<DataType> => {
  const response = await fetch(`${path}${buildQuery(params)}`, {
    headers: {
      Accept: 'application/json',
    },
  })

  if (response.ok) {
    return (await response.json()) as DataType
  }

  const fallback = await response.text()
  throw new Error(fallback || response.statusText)
}

export const fetchThreads = (params?: {
  page?: number
  per_page?: number
  sort?: 'created_at' | 'last_reply_at'
  q?: string
  reply_min?: number
  reply_max?: number
}): Promise<ApiListResponse<ThreadSummary>> => requestJson('/api/threads', params)

export const fetchThread = (threadId: number): Promise<ApiItemResponse<ThreadDetail>> =>
  requestJson(`/api/threads/${threadId}`)

export const fetchThreadPosts = (
  threadId: number,
  params?: { page?: number; per_page?: number }
): Promise<ApiListResponse<ThreadPost>> => requestJson(`/api/threads/${threadId}/posts`, params)

/**
 * 获取指定楼层的历史版本列表（按时间倒序）。
 */
export const fetchPostRevisions = (
  postId: number,
  params?: { page?: number; per_page?: number }
): Promise<ApiListResponse<PostRevision>> => requestJson(`/api/posts/${postId}/revisions`, params)

/**
 * 获取抓取运行列表（分页）。
 */
export const fetchCrawlRuns = (
  params?: { page?: number; per_page?: number }
): Promise<ApiListResponse<CrawlRunSummary>> => requestJson('/api/crawl-runs', params)

/**
 * 获取抓取运行详情。
 */
export const fetchCrawlRun = (
  runId: number
): Promise<ApiItemResponse<CrawlRunDetail>> => requestJson(`/api/crawl-runs/${runId}`)

/**
 * 获取抓取运行的主题明细（分页）。
 */
export const fetchCrawlRunThreads = (
  runId: number,
  params?: {
    page?: number
    per_page?: number
    only_failed?: 0 | 1
    thread_id?: number
    source_thread_id?: number
  }
): Promise<ApiListResponse<CrawlRunThread>> =>
  requestJson(`/api/crawl-runs/${runId}/threads`, params)

/**
 * 获取缺楼层审计运行列表（分页）。
 */
export const fetchFloorAuditRuns = (
  params?: { page?: number; per_page?: number }
): Promise<ApiListResponse<FloorAuditRunSummary>> =>
  requestJson('/api/floor-audit-runs', params)

/**
 * 获取缺楼层审计运行详情。
 */
export const fetchFloorAuditRun = (
  runId: number
): Promise<ApiItemResponse<FloorAuditRunDetail>> =>
  requestJson(`/api/floor-audit-runs/${runId}`)

/**
 * 获取缺楼层审计主题明细（分页）。
 */
export const fetchFloorAuditRunThreads = (
  runId: number,
  params?: { page?: number; per_page?: number; only_failed?: 0 | 1; repair_status?: string }
): Promise<ApiListResponse<FloorAuditThread>> =>
  requestJson(`/api/floor-audit-runs/${runId}/threads`, params)

/**
 * 获取缺楼层审计主题详情。
 */
export const fetchFloorAuditThread = (
  auditThreadId: number
): Promise<ApiItemResponse<FloorAuditThread>> =>
  requestJson(`/api/floor-audit-threads/${auditThreadId}`)

/**
 * 获取缺楼层审计楼层明细（分页）。
 */
export const fetchFloorAuditThreadPosts = (
  auditThreadId: number,
  params?: { page?: number; per_page?: number; repair_status?: string }
): Promise<ApiListResponse<FloorAuditPost>> =>
  requestJson(`/api/floor-audit-threads/${auditThreadId}/posts`, params)
