<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchFloorAuditRun,
  fetchFloorAuditRunThreads,
  type ApiMeta,
  type FloorAuditRunDetail,
  type FloorAuditThread,
} from '../lib/api'
import { formatDateTime } from '../lib/format'

const route = useRoute()
const router = useRouter()

const run = ref<FloorAuditRunDetail | null>(null)
const threads = ref<FloorAuditThread[]>([])
const meta = ref<ApiMeta | null>(null)

const loadingRun = ref(false)
const loadingThreads = ref(false)
const errorMessage = ref('')
const threadsError = ref('')

const currentPage = ref(1)
const repairStatus = ref('')
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
 * 解析修补状态过滤参数。
 */
const parseRepairStatus = (value: unknown): string => {
  const rawValue = Array.isArray(value) ? value[0] : value
  if (typeof rawValue !== 'string') {
    return ''
  }
  return rawValue
}

/**
 * 从路由同步当前页码与过滤状态。
 */
const syncFromRoute = (): { page: number; repairStatus: string } => ({
  page: parsePage(route.query.page),
  repairStatus: parseRepairStatus(route.query.repair_status),
})

/**
 * 获取运行详情数据。
 */
const loadRun = async (id: number) => {
  loadingRun.value = true
  errorMessage.value = ''
  try {
    const response = await fetchFloorAuditRun(id)
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
const loadThreads = async (id: number, page: number, status: string) => {
  loadingThreads.value = true
  threadsError.value = ''
  try {
    const response = await fetchFloorAuditRunThreads(id, {
      page,
      per_page: perPage,
      repair_status: status || undefined,
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
 * 跳转到指定页码。
 */
const goToPage = (page: number) => {
  updateRoute({ page })
}

/**
 * 更新路由参数以触发列表刷新。
 */
const updateRoute = (updates: { page?: number; repairStatus?: string }) => {
  if (!runId.value) {
    return
  }
  const page = updates.page ?? currentPage.value
  const status = updates.repairStatus ?? repairStatus.value
  const nextQuery: Record<string, string> = {}
  if (page > 1) {
    nextQuery.page = String(page)
  }
  if (status) {
    nextQuery.repair_status = status
  }
  router.push({ path: `/floor-audit-runs/${runId.value}`, query: nextQuery })
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
 * 获取修补状态文案。
 */
const resolveRepairStatus = (status: string): string => {
  switch (status) {
    case 'missing':
      return '待修补'
    case 'repaired':
      return '已修补'
    case 'partial':
      return '部分成功'
    case 'failed':
      return '失败'
    case 'skipped':
      return '跳过'
    default:
      return status
  }
}

/**
 * 触发修补状态筛选。
 */
const applyRepairStatus = (value: string) => {
  updateRoute({ page: 1, repairStatus: value })
}

watch(
  [() => route.params.runId, () => route.query.page, () => route.query.repair_status],
  () => {
    const id = runId.value
    if (!id) {
      errorMessage.value = '运行编号无效'
      run.value = null
      threads.value = []
      meta.value = null
      return
    }
    const { page, repairStatus: status } = syncFromRoute()
    currentPage.value = page
    repairStatus.value = status
    loadRun(id)
    loadThreads(id, page, status)
  },
  { immediate: true }
)
</script>

<template>
  <section class="detail-header">
    <RouterLink class="back-link" to="/floor-audit-runs">← 返回审计列表</RouterLink>
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
        <span>开始：{{ formatDateTime(run.run_started_at) }}</span>
        <span>结束：{{ formatDateTime(run.run_finished_at) }}</span>
        <span>耗时：{{ formatDuration(run.duration_ms) }}</span>
        <span>修补：{{ run.repair_enabled ? '包含修补' : '仅审计' }}</span>
      </div>
    </section>

    <section class="metric-grid">
      <div class="metric-card">
        <div class="metric-label">扫描主题</div>
        <div class="metric-value">{{ run.total_thread_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">缺口主题</div>
        <div class="metric-value">{{ run.missing_thread_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">修补成功</div>
        <div class="metric-value">{{ run.repaired_thread_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">部分成功</div>
        <div class="metric-value">{{ run.partial_thread_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">修补失败</div>
        <div class="metric-value">{{ run.failed_thread_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">HTTP 失败</div>
        <div class="metric-value">{{ run.failed_http_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">解析失败</div>
        <div class="metric-value">{{ run.failed_parse_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">DB 失败</div>
        <div class="metric-value">{{ run.failed_db_count }}</div>
      </div>
      <div class="metric-card">
        <div class="metric-label">未知失败</div>
        <div class="metric-value">{{ run.failed_unknown_count }}</div>
      </div>
    </section>

    <section class="report-section">
      <div class="filter-bar">
        <h3 class="section-title">主题明细</h3>
        <label class="filter-label">
          修补状态：
          <select
            class="filter-select"
            :value="repairStatus"
            @change="applyRepairStatus(($event.target as HTMLSelectElement).value)"
          >
            <option value="">全部</option>
            <option value="missing">待修补</option>
            <option value="repaired">已修补</option>
            <option value="partial">部分成功</option>
            <option value="failed">失败</option>
            <option value="skipped">跳过</option>
          </select>
        </label>
      </div>

      <section v-if="loadingThreads" class="state">加载中...</section>
      <section v-else-if="threadsError" class="state error">
        {{ threadsError }}
        <button class="button" @click="runId && loadThreads(runId, currentPage, repairStatus)">重试</button>
      </section>
      <section v-else-if="threads.length === 0" class="state">暂无数据</section>

      <section v-else class="report-list">
        <article v-for="thread in threads" :key="thread.id" class="report-card">
          <div class="report-title">
            <div class="report-title-main">
              <span class="report-id">主题 {{ thread.source_thread_id }}</span>
              <span class="badge">{{ resolveRepairStatus(thread.repair_status) }}</span>
            </div>
            <RouterLink class="link" :to="`/floor-audit-threads/${thread.id}`">
              查看楼层
            </RouterLink>
          </div>
          <div class="report-meta">
            <span>修补前最大楼层：{{ thread.max_floor_number }}</span>
            <span>修补前楼层数：{{ thread.post_count }}</span>
            <span>缺口数：{{ thread.missing_floor_count }}</span>
            <span>跳过数：{{ thread.ignored_floor_count }}</span>
          </div>
          <div class="report-stats">
            <span>修补后最大楼层：{{ thread.repair_after_max_floor_number ?? '-' }}</span>
            <span>修补后楼层数：{{ thread.repair_after_post_count ?? '-' }}</span>
            <span>剩余缺口：{{ thread.repair_remaining_floor_count ?? '-' }}</span>
          </div>
          <div class="report-meta">
            <span>开始：{{ formatDateTime(thread.repair_attempted_at) }}</span>
            <span>结束：{{ formatDateTime(thread.repair_finished_at) }}</span>
            <span>触发 run：{{ thread.repair_crawl_run_id ?? '-' }}</span>
          </div>
          <div v-if="thread.repair_error_summary" class="report-error">
            失败原因：{{ thread.repair_error_category ?? '-' }}
            ({{ thread.repair_http_error_code ?? '-' }})
            {{ thread.repair_error_summary }}
          </div>
        </article>
      </section>
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
</template>
