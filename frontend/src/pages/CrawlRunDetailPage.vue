<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchCrawlRun,
  fetchCrawlRunThreads,
  type ApiMeta,
  type CrawlRunDetail,
  type CrawlRunThread,
} from '../lib/api'
import { formatDateTime } from '../lib/format'

const route = useRoute()
const router = useRouter()

const run = ref<CrawlRunDetail | null>(null)
const threads = ref<CrawlRunThread[]>([])
const meta = ref<ApiMeta | null>(null)

const loadingRun = ref(false)
const loadingThreads = ref(false)
const errorMessage = ref('')
const threadsError = ref('')

const currentPage = ref(1)
const onlyFailed = ref(false)
const perPage = 20

/**
 * 解析运行编号参数。
 */
const runId = computed(() => {
  const rawValue = route.params.runId
  const parsed = Number.parseInt(typeof rawValue === 'string' ? rawValue : '', 10)
  return Number.isNaN(parsed) ? null : parsed
})

/**
 * 解析分页参数，保证页码合法。
 */
const parsePage = (value: unknown): number => {
  const rawValue = Array.isArray(value) ? value[0] : value
  const parsed = Number.parseInt(typeof rawValue === 'string' ? rawValue : '1', 10)
  if (Number.isNaN(parsed) || parsed < 1) {
    return 1
  }
  return parsed
}

/**
 * 解析失败过滤参数，统一转换为布尔值。
 */
const parseOnlyFailed = (value: unknown): boolean => {
  const rawValue = Array.isArray(value) ? value[0] : value
  if (typeof rawValue === 'boolean') {
    return rawValue
  }
  if (typeof rawValue === 'string') {
    return rawValue === '1' || rawValue.toLowerCase() === 'true'
  }
  return false
}

/**
 * 从路由同步当前页码与过滤状态。
 */
const syncFromRoute = (): { page: number; onlyFailed: boolean } => ({
  page: parsePage(route.query.page),
  onlyFailed: parseOnlyFailed(route.query.only_failed),
})

/**
 * 获取运行详情数据。
 */
const loadRun = async (id: number) => {
  loadingRun.value = true
  errorMessage.value = ''
  try {
    const response = await fetchCrawlRun(id)
    run.value = response.data
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '加载失败'
    run.value = null
  } finally {
    loadingRun.value = false
  }
}

/**
 * 获取主题明细列表。
 */
const loadThreads = async (id: number, page: number, failedOnly: boolean) => {
  loadingThreads.value = true
  threadsError.value = ''
  try {
    const response = await fetchCrawlRunThreads(id, {
      page,
      per_page: perPage,
      // 规则：后端仅接受 1/0，避免传 true/false 触发校验失败
      only_failed: failedOnly ? 1 : undefined,
    })
    threads.value = response.data
    meta.value = response.meta
  } catch (error) {
    threadsError.value = error instanceof Error ? error.message : '加载失败'
    threads.value = []
    meta.value = null
  } finally {
    loadingThreads.value = false
  }
}

/**
 * 切换失败过滤并同步路由。
 */
const toggleOnlyFailed = () => {
  updateRoute({ page: 1, onlyFailed: !onlyFailed.value })
}

/**
 * 跳转到指定页码。
 */
const goToPage = (page: number) => {
  updateRoute({ page })
}

/**
 * 更新路由参数以触发列表刷新。
 */
const updateRoute = (updates: { page?: number; onlyFailed?: boolean }) => {
  if (!runId.value) {
    return
  }
  const page = updates.page ?? currentPage.value
  const failedOnly = updates.onlyFailed ?? onlyFailed.value
  const nextQuery: Record<string, string> = {}
  if (page > 1) {
    nextQuery.page = String(page)
  }
  if (failedOnly) {
    nextQuery.only_failed = '1'
  }
  router.push({ path: `/crawl-runs/${runId.value}`, query: nextQuery })
}

/**
 * 格式化耗时显示。
 */
const formatDuration = (durationMs: number | null | undefined): string => {
  if (durationMs === null || durationMs === undefined) {
    return '-'
  }
  return `${durationMs} ms`
}

/**
 * 获取主题展示标题。
 */
const resolveThreadTitle = (thread: CrawlRunThread): string =>
  thread.source_thread_id ? `主题 ${thread.source_thread_id}` : `主题 ${thread.thread_id}`

/**
 * 获取主题详情跳转链接。
 */
const resolveThreadLink = (thread: CrawlRunThread): string | null =>
  thread.source_thread_id ? `/threads/${thread.source_thread_id}` : null

watch(
  [() => route.params.runId, () => route.query.page, () => route.query.only_failed],
  () => {
    const id = runId.value
    if (!id) {
      errorMessage.value = '运行编号无效'
      run.value = null
      threads.value = []
      meta.value = null
      return
    }
    const { page, onlyFailed: failedOnly } = syncFromRoute()
    currentPage.value = page
    onlyFailed.value = failedOnly
    loadRun(id)
    loadThreads(id, page, failedOnly)
  },
  { immediate: true }
)
</script>

<template>
  <section class="detail-header">
    <RouterLink class="back-link" to="/crawl-runs">← 返回运行列表</RouterLink>
  </section>

  <section v-if="loadingRun" class="state">加载中...</section>
  <section v-else-if="errorMessage" class="state error">
    {{ errorMessage }}
    <button class="button" @click="runId && loadRun(runId)">重试</button>
  </section>

  <template v-else-if="run">
    <section class="report-summary">
      <div class="report-title">
        <div class="report-title-main">
          <span class="report-id">Run #{{ run.id }}</span>
          <span class="badge">来源 {{ run.run_trigger_text }}</span>
          <span class="badge badge-muted">
            {{ run.run_finished_at ? '已完成' : '进行中' }}
          </span>
        </div>
      </div>
      <div class="report-meta">
        <span>
          窗口：{{ run.date_window_start ?? '-' }} ~
          {{ run.date_window_end ?? '-' }}
        </span>
        <span>开始：{{ formatDateTime(run.run_started_at) }}</span>
        <span>结束：{{ formatDateTime(run.run_finished_at) }}</span>
        <span>耗时：{{ formatDuration(run.duration_ms) }}</span>
      </div>
    </section>

    <section class="metric-grid">
      <div class="metric-card">
        <div class="metric-label">扫描主题</div>
        <div class="metric-value">{{ run.thread_scanned_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">变化主题</div>
        <div class="metric-value">{{ run.thread_change_detected_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">更新主题</div>
        <div class="metric-value">{{ run.thread_updated_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">失败主题</div>
        <div class="metric-value">{{ run.failed_thread_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">新增楼层</div>
        <div class="metric-value">{{ run.new_post_count_total }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">更新楼层</div>
        <div class="metric-value">{{ run.updated_post_count_total }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">HTTP 请求</div>
        <div class="metric-value">{{ run.http_request_count }}</div>
      </div>
    </section>

    <section class="report-section">
      <div class="filter-bar">
        <h3 class="section-title">主题明细</h3>
        <button class="button" :class="{ active: onlyFailed }" @click="toggleOnlyFailed">
          {{ onlyFailed ? '仅失败' : '全部主题' }}
        </button>
      </div>

      <section v-if="loadingThreads" class="state">加载中...</section>
      <section v-else-if="threadsError" class="state error">
        {{ threadsError }}
        <button class="button" @click="runId && loadThreads(runId, currentPage, onlyFailed)">
          重试
        </button>
      </section>
      <section v-else-if="threads.length === 0" class="state">暂无数据</section>

      <section v-else class="report-list">
        <article v-for="thread in threads" :key="thread.id" class="report-card">
          <div class="report-title">
            <div class="report-title-main">
              <span class="report-id">{{ resolveThreadTitle(thread) }}</span>
              <span v-if="thread.error_summary" class="badge badge-pinned">失败</span>
              <span v-else class="badge badge-digest">成功</span>
            </div>
            <RouterLink
              v-if="resolveThreadLink(thread)"
              class="link"
              :to="resolveThreadLink(thread) ?? ''"
            >
              查看主题
            </RouterLink>
          </div>
          <div class="report-meta">
            <span>检测变化：{{ thread.change_detected_by_last_reply_at ? '是' : '否' }}</span>
            <span>抓取页数：{{ thread.fetched_page_count }}</span>
            <span>页上限：{{ thread.page_limit_applied ? '是' : '否' }}</span>
          </div>
          <div class="report-stats">
            <span>新增 {{ thread.new_post_count }}</span>
            <span>更新 {{ thread.updated_post_count }}</span>
            <span>HTTP {{ thread.http_error_code ?? '-' }}</span>
          </div>
          <div class="report-meta">
            <span>开始：{{ formatDateTime(thread.started_at) }}</span>
            <span>结束：{{ formatDateTime(thread.finished_at) }}</span>
          </div>
          <div v-if="thread.error_summary" class="report-error">
            失败原因：{{ thread.error_summary }}
          </div>
        </article>
      </section>

      <section v-if="meta && meta.total_pages > 1" class="pagination">
        <button
          class="button"
          :disabled="meta.page <= 1"
          @click="goToPage(meta.page - 1)"
        >
          上一页
        </button>
        <span>第 {{ meta.page }} / {{ meta.total_pages }} 页</span>
        <button
          class="button"
          :disabled="meta.page >= meta.total_pages"
          @click="goToPage(meta.page + 1)"
        >
          下一页
        </button>
      </section>
    </section>
  </template>
</template>
