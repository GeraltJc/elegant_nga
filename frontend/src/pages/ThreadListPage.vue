<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchThreads, type ApiMeta, type ThreadSummary } from '../lib/api'
import { formatDateTime } from '../lib/format'

const route = useRoute()
const router = useRouter()

const threads = ref<ThreadSummary[]>([])
const meta = ref<ApiMeta | null>(null)
const loading = ref(false)
const errorMessage = ref('')

const searchInput = ref('')
const currentSort = ref<'created_at' | 'last_reply_at'>('created_at')
const currentPage = ref(1)
const replyMinInput = ref('')
const replyMaxInput = ref('')
const perPage = 30

const parsePage = (value: unknown): number => {
  const rawValue = Array.isArray(value) ? value[0] : value
  const parsed = Number.parseInt(typeof rawValue === 'string' ? rawValue : '1', 10)
  if (Number.isNaN(parsed) || parsed < 1) {
    return 1
  }
  return parsed
}

const parseNonNegativeInt = (value: unknown): number | null => {
  const rawValue = Array.isArray(value) ? value[0] : value
  if (rawValue === undefined || rawValue === null || rawValue === '') {
    return null
  }
  const parsed = Number.parseInt(String(rawValue), 10)
  if (Number.isNaN(parsed) || parsed < 0) {
    return null
  }
  return parsed
}

const syncFromRoute = (): {
  sort: 'created_at' | 'last_reply_at'
  keyword: string
  page: number
  replyMin: number | null
  replyMax: number | null
} => {
  const sort =
    route.query.sort === 'last_reply_at' ? 'last_reply_at' : 'created_at'
  const keyword = typeof route.query.q === 'string' ? route.query.q : ''
  const page = parsePage(route.query.page)
  const replyMin = parseNonNegativeInt(route.query.reply_min)
  const replyMax = parseNonNegativeInt(route.query.reply_max)
  return { sort, keyword, page, replyMin, replyMax }
}

const loadThreads = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const replyMin = parseNonNegativeInt(replyMinInput.value)
    const replyMax = parseNonNegativeInt(replyMaxInput.value)
    const response = await fetchThreads({
      page: currentPage.value,
      per_page: perPage,
      sort: currentSort.value,
      q: searchInput.value.trim() || undefined,
      reply_min: replyMin ?? undefined,
      reply_max: replyMax ?? undefined,
    })
    threads.value = response.data
    meta.value = response.meta
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '加载失败'
    threads.value = []
    meta.value = null
  } finally {
    loading.value = false
  }
}

const updateRoute = (updates: {
  page?: number
  sort?: 'created_at' | 'last_reply_at'
  keyword?: string
  replyMin?: string
  replyMax?: string
}) => {
  const nextQuery: Record<string, string> = {}
  const nextSort = updates.sort ?? currentSort.value
  const nextPage = updates.page ?? currentPage.value
  const nextQueryValue = updates.keyword ?? searchInput.value
  const nextReplyMin = updates.replyMin ?? replyMinInput.value
  const nextReplyMax = updates.replyMax ?? replyMaxInput.value

  if (nextSort) {
    nextQuery.sort = nextSort
  }
  if (nextPage > 1) {
    nextQuery.page = String(nextPage)
  }
  if (nextQueryValue.trim() !== '') {
    nextQuery.q = nextQueryValue.trim()
  }
  const replyMinValue = parseNonNegativeInt(nextReplyMin)
  if (replyMinValue !== null) {
    nextQuery.reply_min = String(replyMinValue)
  }
  const replyMaxValue = parseNonNegativeInt(nextReplyMax)
  if (replyMaxValue !== null) {
    nextQuery.reply_max = String(replyMaxValue)
  }

  router.push({ path: '/', query: nextQuery })
}

const submitSearch = () => {
  const replyMin = parseNonNegativeInt(replyMinInput.value)
  const replyMax = parseNonNegativeInt(replyMaxInput.value)
  if (replyMin !== null && replyMax !== null && replyMax < replyMin) {
    errorMessage.value = '回复数区间不合法'
    return
  }

  updateRoute({
    page: 1,
    keyword: searchInput.value,
    replyMin: replyMinInput.value,
    replyMax: replyMaxInput.value,
  })
}

const clearSearch = () => {
  searchInput.value = ''
  replyMinInput.value = ''
  replyMaxInput.value = ''
  updateRoute({ page: 1, keyword: '', replyMin: '', replyMax: '' })
}

const changeSort = (sort: 'created_at' | 'last_reply_at') => {
  if (sort === currentSort.value) {
    return
  }
  updateRoute({ page: 1, sort })
}

const goToPage = (page: number) => {
  updateRoute({ page })
}

watch(
  () => route.query,
  () => {
    const { sort, keyword, page, replyMin, replyMax } = syncFromRoute()
    currentSort.value = sort
    searchInput.value = keyword
    currentPage.value = page
    replyMinInput.value = replyMin === null ? '' : String(replyMin)
    replyMaxInput.value = replyMax === null ? '' : String(replyMax)
    loadThreads()
  },
  { immediate: true }
)
</script>

<template>
  <section class="page-header">
    <div>
      <h2 class="page-title">主题列表</h2>
      <p class="page-subtitle">支持按发帖时间/最后回复排序与关键词搜索</p>
    </div>
    <div class="sort-group">
      <button
        class="button"
        :class="{ active: currentSort === 'created_at' }"
        @click="changeSort('created_at')"
      >
        发帖时间
      </button>
      <button
        class="button"
        :class="{ active: currentSort === 'last_reply_at' }"
        @click="changeSort('last_reply_at')"
      >
        最后回复
      </button>
    </div>
  </section>

  <section class="search-bar">
    <input
      v-model="searchInput"
      class="input"
      type="text"
      placeholder="搜索标题或 1 楼正文"
      @keyup.enter="submitSearch"
    />
    <input
      v-model="replyMinInput"
      class="input"
      type="number"
      min="0"
      placeholder="回复数下限（有效回复数）"
      title="按有效回复数过滤（只增不减）"
      @keyup.enter="submitSearch"
    />
    <input
      v-model="replyMaxInput"
      class="input"
      type="number"
      min="0"
      placeholder="回复数上限（有效回复数）"
      title="按有效回复数过滤（只增不减）"
      @keyup.enter="submitSearch"
    />
    <button class="button primary" @click="submitSearch">搜索</button>
    <button
      v-if="searchInput || replyMinInput || replyMaxInput"
      class="button"
      @click="clearSearch"
    >
      清空
    </button>
  </section>

  <section v-if="loading" class="state">加载中...</section>
  <section v-else-if="errorMessage" class="state error">
    {{ errorMessage }}
    <button class="button" @click="loadThreads">重试</button>
  </section>
  <section v-else-if="threads.length === 0" class="state">
    暂无数据
  </section>

  <section v-else class="thread-list">
    <article
      v-for="thread in threads"
      :key="thread.source_thread_id"
      class="thread-card"
    >
      <div class="thread-title">
        <RouterLink :to="`/threads/${thread.source_thread_id}`">
          {{ thread.title }}
        </RouterLink>
        <span v-if="thread.title_prefix_text" class="badge">
          {{ thread.title_prefix_text }}
        </span>
        <span v-if="thread.is_pinned" class="badge badge-pinned">置顶</span>
        <span v-if="thread.is_digest" class="badge badge-digest">精华</span>
      </div>
      <div class="thread-meta">
        <span>作者：{{ thread.author_name }}</span>
        <span>发帖：{{ formatDateTime(thread.thread_created_at) }}</span>
        <span>
          最后回复：
          {{
            thread.last_reply_at
              ? formatDateTime(thread.last_reply_at)
              : '暂无'
          }}
        </span>
      </div>
      <div class="thread-stats">
        <span>回复 {{ thread.reply_count_display }}</span>
        <span v-if="thread.view_count_display !== null">
          浏览 {{ thread.view_count_display }}
        </span>
        <span v-if="thread.is_truncated_by_page_limit" class="warn">
          分段补齐中
        </span>
        <span v-if="thread.is_skipped_by_page_total_limit" class="warn">
          页数过大未抓取楼层
        </span>
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
