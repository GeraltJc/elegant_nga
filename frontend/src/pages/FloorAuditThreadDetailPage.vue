<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchFloorAuditThread,
  fetchFloorAuditThreadPosts,
  type ApiMeta,
  type FloorAuditPost,
  type FloorAuditThread,
} from '../lib/api'
import { formatDateTime } from '../lib/format'

const route = useRoute()
const router = useRouter()

const auditThread = ref<FloorAuditThread | null>(null)
const posts = ref<FloorAuditPost[]>([])
const meta = ref<ApiMeta | null>(null)

const loadingThread = ref(false)
const loadingPosts = ref(false)
const errorMessage = ref('')
const postsError = ref('')

const currentPage = ref(1)
const repairStatus = ref('')
const perPage = 20

/**
 * 解析审计主题编号参数。
 */
const auditThreadId = computed(() => {
  const rawValue = route.params.auditThreadId
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
 * 获取审计主题详情。
 */
const loadAuditThread = async (id: number) => {
  loadingThread.value = true
  errorMessage.value = ''
  try {
    const response = await fetchFloorAuditThread(id)
    auditThread.value = response.data
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '加载失败'
    auditThread.value = null
  } finally {
    loadingThread.value = false
  }
}

/**
 * 获取楼层明细列表。
 */
const loadPosts = async (id: number, page: number, status: string) => {
  loadingPosts.value = true
  postsError.value = ''
  try {
    const response = await fetchFloorAuditThreadPosts(id, {
      page,
      per_page: perPage,
      repair_status: status || undefined,
    })
    posts.value = response.data
    meta.value = response.meta
  } catch (error) {
    postsError.value = error instanceof Error ? error.message : '加载失败'
    posts.value = []
    meta.value = null
  } finally {
    loadingPosts.value = false
  }
}

/**
 * 跳转到指定页码。
 */
const goToPage = (page: number) => {
  updateRoute({ page })
}

/**
 * 更新路由参数以触发分页切换。
 */
const updateRoute = (updates: { page?: number; repairStatus?: string }) => {
  if (!auditThreadId.value) {
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
  router.push({ path: `/floor-audit-threads/${auditThreadId.value}`, query: nextQuery })
}

/**
 * 获取修补状态文案。
 */
const resolveRepairStatus = (status: string): string => {
  switch (status) {
    case 'missing':
      return '待修补'
    case 'partial':
      return '部分成功'
    case 'skipped':
      return '跳过'
    case 'ignored':
      return '超次数跳过'
    case 'repaired':
      return '已修补'
    case 'still_missing':
      return '仍缺失'
    case 'failed':
      return '失败'
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
  [() => route.params.auditThreadId, () => route.query.page, () => route.query.repair_status],
  () => {
    const id = auditThreadId.value
    if (!id) {
      errorMessage.value = '审计主题编号无效'
      auditThread.value = null
      posts.value = []
      meta.value = null
      return
    }
    const { page, repairStatus: status } = syncFromRoute()
    currentPage.value = page
    repairStatus.value = status
    loadAuditThread(id)
    loadPosts(id, page, status)
  },
  { immediate: true }
)
</script>

<template>
  <section class="detail-header">
    <RouterLink
      v-if="auditThread"
      class="back-link"
      :to="`/floor-audit-runs/${auditThread.audit_run_id}`"
    >
      ← 返回审计运行
    </RouterLink>
  </section>

  <section v-if="loadingThread" class="state">加载中...</section>
  <section v-else-if="errorMessage" class="state error">
    {{ errorMessage }}
    <button class="button" @click="auditThreadId && loadAuditThread(auditThreadId)">重试</button>
  </section>

  <template v-else-if="auditThread">
    <section class="report-summary">
      <div class="report-title">
        <div class="report-title-main">
          <span class="report-id">主题 {{ auditThread.source_thread_id }}</span>
          <span class="badge">{{ resolveRepairStatus(auditThread.repair_status) }}</span>
        </div>
      </div>
      <div class="report-meta">
        <span>修补前最大楼层：{{ auditThread.max_floor_number }}</span>
        <span>修补前楼层数：{{ auditThread.post_count }}</span>
        <span>缺口数：{{ auditThread.missing_floor_count }}</span>
        <span>跳过数：{{ auditThread.ignored_floor_count }}</span>
      </div>
      <div class="report-stats">
        <span>修补后最大楼层：{{ auditThread.repair_after_max_floor_number ?? '-' }}</span>
        <span>修补后楼层数：{{ auditThread.repair_after_post_count ?? '-' }}</span>
        <span>剩余缺口：{{ auditThread.repair_remaining_floor_count ?? '-' }}</span>
      </div>
      <div class="report-meta">
        <span>开始：{{ formatDateTime(auditThread.repair_attempted_at) }}</span>
        <span>结束：{{ formatDateTime(auditThread.repair_finished_at) }}</span>
      </div>
      <div v-if="auditThread.repair_error_summary" class="report-error">
        失败原因：{{ auditThread.repair_error_category ?? '-' }}
        ({{ auditThread.repair_http_error_code ?? '-' }})
        {{ auditThread.repair_error_summary }}
      </div>
    </section>

    <section class="report-section">
      <div class="filter-bar">
        <h3 class="section-title">缺口楼层明细</h3>
        <label class="filter-label">
          修补状态：
          <select
            class="filter-select"
            :value="repairStatus"
            @change="applyRepairStatus(($event.target as HTMLSelectElement).value)"
          >
            <option value="">全部</option>
            <option value="missing">待修补</option>
            <option value="ignored">超次数跳过</option>
            <option value="repaired">已修补</option>
            <option value="still_missing">仍缺失</option>
            <option value="failed">失败</option>
          </select>
        </label>
      </div>

      <section v-if="loadingPosts" class="state">加载中...</section>
      <section v-else-if="postsError" class="state error">
        {{ postsError }}
        <button
          class="button"
          @click="auditThreadId && loadPosts(auditThreadId, currentPage, repairStatus)"
        >
          重试
        </button>
      </section>
      <section v-else-if="posts.length === 0" class="state">暂无数据</section>

      <section v-else class="report-list">
        <article v-for="post in posts" :key="post.id" class="report-card">
          <div class="report-title">
            <div class="report-title-main">
              <span class="report-id">楼层 {{ post.floor_number }}</span>
              <span class="badge">{{ resolveRepairStatus(post.repair_status) }}</span>
            </div>
          </div>
          <div class="report-meta">
            <span>修补前尝试：{{ post.attempt_count_before }}</span>
            <span>修补后尝试：{{ post.attempt_count_after ?? '-' }}</span>
          </div>
          <div v-if="post.repair_error_summary" class="report-error">
            失败原因：{{ post.repair_error_category ?? '-' }}
            ({{ post.repair_http_error_code ?? '-' }})
            {{ post.repair_error_summary }}
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
