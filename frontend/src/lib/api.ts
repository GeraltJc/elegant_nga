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
  floor_number: number
  author_name: string
  post_created_at: string | null
  content_html: string
  is_deleted_by_source: boolean
  is_folded_by_source: boolean
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
}): Promise<ApiListResponse<ThreadSummary>> => requestJson('/api/threads', params)

export const fetchThread = (threadId: number): Promise<ApiItemResponse<ThreadDetail>> =>
  requestJson(`/api/threads/${threadId}`)

export const fetchThreadPosts = (
  threadId: number,
  params?: { page?: number; per_page?: number }
): Promise<ApiListResponse<ThreadPost>> => requestJson(`/api/threads/${threadId}/posts`, params)
