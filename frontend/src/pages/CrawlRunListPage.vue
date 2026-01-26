<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchCrawlRuns, type ApiMeta, type CrawlRunSummary } from '../lib/api'
import { formatDateTime } from '../lib/format'

const route = useRoute()
const router = useRouter()

const runs = ref<CrawlRunSummary[]>([])
const meta = ref<ApiMeta | null>(null)
const loading = ref(false)
const errorMessage = ref('')

const currentPage = ref(1)
const perPage = 20

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
 * 从路由同步当前页码。
 */
const syncFromRoute = (): number => parsePage(route.query.page)

/**
 * 获取运行记录列表。
 */
const loadRuns = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await fetchCrawlRuns({
      page: currentPage.value,
      per_page: perPage,
    })
    runs.value = response.data
    meta.value = response.meta
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '加载失败'
    runs.value = []
    meta.value = null
  } finally {
    loading.value = false
  }
}

/**
 * 更新路由参数以触发分页切换。
 */
const updateRoute = (page: number) => {
  const nextQuery: Record<string, string> = {}
  if (page > 1) {
    nextQuery.page = String(page)
  }
  router.push({ path: '/crawl-runs', query: nextQuery })
}

/**
 * 跳转到指定页码。
 */
const goToPage = (page: number) => {
  updateRoute(page)
}

/**
 * 格式化运行窗口展示。
 */
const formatWindow = (run: CrawlRunSummary): string => {
  const start = run.date_window_start
  const end = run.date_window_end
  if (!start && !end) {
    return '未设置'
  }
  return `${start ?? '-'} ~ ${end ?? '-'}`
}

/**
 * 获取运行状态文案。
 */
const resolveStatusText = (run: CrawlRunSummary): string =>
  run.run_finished_at ? '已完成' : '进行中'

watch(
  () => route.query,
  () => {
    currentPage.value = syncFromRoute()
    loadRuns()
  },
  { immediate: true }
)
</script>

<template>
  <section class="page-header">
    <div>
      <h2 class="page-title">运行报表</h2>
      <p class="page-subtitle">查看每次抓取运行的汇总与趋势</p>
    </div>
  </section>

  <section v-if="loading" class="state">加载中...</section>
  <section v-else-if="errorMessage" class="state error">
    {{ errorMessage }}
    <button class="button" @click="loadRuns">重试</button>
  </section>
  <section v-else-if="runs.length === 0" class="state">暂无数据</section>

  <section v-else class="report-list">
    <article v-for="run in runs" :key="run.id" class="report-card">
      <div class="report-title">
        <div class="report-title-main">
          <span class="report-id">Run #{{ run.id }}</span>
          <span class="badge badge-muted">{{ resolveStatusText(run) }}</span>
          <span class="badge">来源 {{ run.run_trigger_text }}</span>
        </div>
        <RouterLink class="link" :to="`/crawl-runs/${run.id}`">
          查看详情
        </RouterLink>
      </div>
      <div class="report-meta">
        <span>窗口：{{ formatWindow(run) }}</span>
        <span>开始：{{ formatDateTime(run.run_started_at) }}</span>
        <span>结束：{{ formatDateTime(run.run_finished_at) }}</span>
      </div>
      <div class="report-stats">
        <span>扫描 {{ run.thread_scanned_count }}</span>
        <span>变化 {{ run.thread_change_detected_count }}</span>
        <span>更新 {{ run.thread_updated_count }}</span>
        <span>请求 {{ run.http_request_count }}</span>
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
</template>
