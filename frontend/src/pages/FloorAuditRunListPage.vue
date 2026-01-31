<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchFloorAuditRuns, type ApiMeta, type FloorAuditRunSummary } from '../lib/api'
import { formatDateTime } from '../lib/format'

const route = useRoute()
const router = useRouter()

const runs = ref<FloorAuditRunSummary[]>([])
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
 * 获取审计运行记录列表。
 */
const loadRuns = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await fetchFloorAuditRuns({
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
  router.push({ path: '/floor-audit-runs', query: nextQuery })
}

/**
 * 跳转到指定页码。
 */
const goToPage = (page: number) => {
  updateRoute(page)
}

/**
 * 获取运行状态文案。
 */
const resolveStatusText = (run: FloorAuditRunSummary): string =>
  run.run_finished_at ? '已完成' : '进行中'

/**
 * 获取修补开关文案。
 */
const resolveRepairText = (run: FloorAuditRunSummary): string =>
  run.repair_enabled ? '包含修补' : '仅审计'

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
      <h2 class="page-title">缺楼层审计</h2>
      <p class="page-subtitle">查看缺楼层审计与修补的运行记录</p>
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
          <span class="badge">{{ resolveRepairText(run) }}</span>
        </div>
        <RouterLink class="link" :to="`/floor-audit-runs/${run.id}`">
          查看详情
        </RouterLink>
      </div>
      <div class="report-meta">
        <span>开始：{{ formatDateTime(run.run_started_at) }}</span>
        <span>结束：{{ formatDateTime(run.run_finished_at) }}</span>
        <span>触发：{{ run.run_trigger_text }}</span>
      </div>
      <div class="report-stats">
        <span>扫描 {{ run.total_thread_count }}</span>
        <span>缺口 {{ run.missing_thread_count }}</span>
        <span>修补 {{ run.repaired_thread_count }}</span>
        <span>部分 {{ run.partial_thread_count }}</span>
        <span>失败 {{ run.failed_thread_count }}</span>
      </div>
      <div class="report-meta">
        <span>HTTP 失败 {{ run.failed_http_count }}</span>
        <span>解析失败 {{ run.failed_parse_count }}</span>
        <span>DB 失败 {{ run.failed_db_count }}</span>
        <span>未知失败 {{ run.failed_unknown_count }}</span>
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
