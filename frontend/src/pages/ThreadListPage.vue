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
const perPage = 30

const parsePage = (value: unknown): number => {
  const rawValue = Array.isArray(value) ? value[0] : value
  const parsed = Number.parseInt(typeof rawValue === 'string' ? rawValue : '1', 10)
  if (Number.isNaN(parsed) || parsed < 1) {
    return 1
  }
  return parsed
}

const syncFromRoute = (): {
  sort: 'created_at' | 'last_reply_at'
  keyword: string
  page: number
} => {
  const sort =
    route.query.sort === 'last_reply_at' ? 'last_reply_at' : 'created_at'
  const keyword = typeof route.query.q === 'string' ? route.query.q : ''
  const page = parsePage(route.query.page)
  return { sort, keyword, page }
}

const loadThreads = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await fetchThreads({
      page: currentPage.value,
      per_page: perPage,
      sort: currentSort.value,
      q: searchInput.value.trim() || undefined,
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
}) => {
  const nextQuery: Record<string, string> = {}
  const nextSort = updates.sort ?? currentSort.value
  const nextPage = updates.page ?? currentPage.value
  const nextQueryValue = updates.keyword ?? searchInput.value

  if (nextSort) {
    nextQuery.sort = nextSort
  }
  if (nextPage > 1) {
    nextQuery.page = String(nextPage)
  }
  if (nextQueryValue.trim() !== '') {
    nextQuery.q = nextQueryValue.trim()
  }

  router.push({ path: '/', query: nextQuery })
}

const submitSearch = () => {
  updateRoute({ page: 1, keyword: searchInput.value })
}

const clearSearch = () => {
  searchInput.value = ''
  updateRoute({ page: 1, keyword: '' })
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
    const { sort, keyword, page } = syncFromRoute()
    currentSort.value = sort
    searchInput.value = keyword
    currentPage.value = page
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
    <button class="button primary" @click="submitSearch">搜索</button>
    <button v-if="searchInput" class="button" @click="clearSearch">清空</button>
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
