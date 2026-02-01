<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  fetchThread,
  fetchPostRevisions,
  fetchThreadPosts,
  type ApiMeta,
  type PostRevision,
  type ThreadDetail,
  type ThreadPost,
} from '../lib/api'
import { formatDateTime } from '../lib/format'

const route = useRoute()
const router = useRouter()

const thread = ref<ThreadDetail | null>(null)
const posts = ref<ThreadPost[]>([])
const meta = ref<ApiMeta | null>(null)

const loadingThread = ref(false)
const loadingPosts = ref(false)
const errorMessage = ref('')
const postsError = ref('')

const currentPage = ref(1)
// 业务含义：楼层列表每页拉取数量
const perPage = 30
// 业务含义：历史版本列表每页拉取数量
const revisionPerPage = 5
// 业务含义：NGA 附件图片的基准域名
const imageBaseUrl = 'https://img.nga.178.com/attachments'

type RevisionState = {
  open: boolean
  loading: boolean
  error: string
  page: number
  meta: ApiMeta | null
  data: PostRevision[]
}

const revisionStates = ref<Record<number, RevisionState>>({})

/**
 * 标准化图片地址，补齐附件域名。
 *
 * @param src 原始图片地址
 * @return 规范化后的图片地址
 */
const normalizeImageSrc = (src: string) => {
  const trimmed = src.trim()
  if (trimmed === '') {
    return trimmed
  }
  if (/^https?:\/\//i.test(trimmed)) {
    return trimmed
  }
  const cleaned = trimmed.replace(/^[./]+/, '')
  return `${imageBaseUrl}/${cleaned}`
}

/**
 * 将 UBB 引用标签转换为区块引用，提升阅读可读性。
 *
 * @param html 原始楼层 HTML 字符串
 * @return 处理后的 HTML 字符串
 */
const normalizeQuoteTags = (html: string): string => {
  if (html === '') {
    return html
  }
  const normalized = html
    .replace(/\[quote(?:=[^\]]+)?\]/gi, '<blockquote class="nga-quote">')
    .replace(/\[\/quote\]/gi, '</blockquote>')
  return normalized
}

/**
 * 将常见 UBB 简单排版标签转换为等价 HTML。
 *
 * @param html 原始楼层 HTML 字符串
 * @return 处理后的 HTML 字符串
 */
const normalizeSimpleUbbTags = (html: string): string => {
  if (html === '') {
    return html
  }
  // 业务规则：仅转换基础排版标签，避免引入复杂结构与额外风险
  const tagMap: Record<string, string> = {
    b: 'strong',
    i: 'em',
    u: 'u',
    s: 's',
    del: 'del',
  }
  let output = html
  Object.entries(tagMap).forEach(([ubbTag, htmlTag]) => {
    const openTag = new RegExp(`\\[${ubbTag}\\]`, 'gi')
    const closeTag = new RegExp(`\\[\\/${ubbTag}\\]`, 'gi')
    output = output.replace(openTag, `<${htmlTag}>`).replace(closeTag, `</${htmlTag}>`)
  })
  return output
}

/**
 * 将 [uid]/[pid] 标签降级为可读文本，避免残留 UBB 标记影响阅读。
 *
 * @param html 原始楼层 HTML 字符串
 * @return 处理后的 HTML 字符串
 */
const normalizeUbbMetaTags = (html: string): string => {
  if (html === '') {
    return html
  }
  let output = html
  output = output.replace(/\[uid=(\d+)\]([\s\S]*?)\[\/uid\]/gi, (_, uid: string, name: string) => {
    const safeUid = uid.replace(/"/g, '&quot;')
    return `<span class="nga-ubb-uid" data-uid="${safeUid}">${name}</span>`
  })
  output = output.replace(
    /\[pid=(\d+)(?:,(\d+))?(?:,(\d+))?\]([\s\S]*?)\[\/pid\]/gi,
    (_, pid: string, tid: string | undefined, page: string | undefined, text: string) => {
      const safePid = pid.replace(/"/g, '&quot;')
      const safeTid = tid ? tid.replace(/"/g, '&quot;') : ''
      const safePage = page ? page.replace(/"/g, '&quot;') : ''
      // 业务规则：仅保留文本与数据标记，避免误导为可直接跳转的链接
      // 业务含义：保留 pid/tid/page 便于后续扩展跳转能力
      return `<span class="nga-ubb-pid" data-pid="${safePid}" data-tid="${safeTid}" data-page="${safePage}">${text}</span>`
    }
  )
  return output
}

/**
 * 构造“点击展开”的图片结构，避免长图直接撑高楼层。
 *
 * @param imgHtml 已生成的图片 HTML
 * @return 包装后的 HTML 字符串
 */
const buildImageToggleHtml = (imgHtml: string): string => {
  // 业务含义：图片默认折叠时的提示文案
  const summaryText = '点击查看图片'
  return `<details class="image-toggle"><summary class="image-toggle-summary">${summaryText}</summary>${imgHtml}</details>`
}

/**
 * 仅在引用块中折叠图片，避免正文图片被强制折叠。
 *
 * @param html 原始楼层 HTML 字符串
 * @return 处理后的 HTML 字符串
 */
const wrapQuotedImages = (html: string): string => {
  if (html === '') {
    return html
  }
  return html.replace(/<blockquote\b[^>]*>[\s\S]*?<\/blockquote>/gi, (block) => {
    return block.replace(/<img\b[^>]*>/gi, (imgTag) => buildImageToggleHtml(imgTag))
  })
}

/**
 * 标准化楼层 HTML 内容，补齐图片地址并兼容基础 UBB 标签。
 *
 * @param html 原始楼层 HTML 字符串
 * @return 处理后的 HTML 字符串
 */
const normalizePostHtml = (html: string) => {
  let output = html
  output = normalizeQuoteTags(output)
  output = normalizeSimpleUbbTags(output)
  output = normalizeUbbMetaTags(output)
  output = output.replace(/\[img\]([\s\S]*?)\[\/img\]/gi, (_, rawSrc: string) => {
    const normalized = normalizeImageSrc(rawSrc)
    const safeSrc = normalized.replace(/"/g, '&quot;')
    return `<img src="${safeSrc}" alt="" loading="lazy" referrerpolicy="no-referrer">`
  })
  output = output.replace(
    /<img\b([^>]*?)\bsrc=(['"])([^'"]+)\2([^>]*)>/gi,
    (match, before, quote, src, after) => {
      const normalized = normalizeImageSrc(src)
      if (normalized === src) {
        return match
      }
      const safeSrc = normalized.replace(/"/g, '&quot;')
      // 业务规则：仅当图片地址需要补齐时才重写标签，避免丢失原标签的额外属性
      return `<img${before}src=${quote}${safeSrc}${quote}${after}>`
    }
  )
  output = wrapQuotedImages(output)
  return output
}

/**
 * 解析分页参数，保证页码合法。
 *
 * @param value 路由中可能出现的页码值
 * @return 合法的页码数字
 */
const parsePage = (value: unknown): number => {
  const rawValue = Array.isArray(value) ? value[0] : value
  const parsed = Number.parseInt(typeof rawValue === 'string' ? rawValue : '1', 10)
  if (Number.isNaN(parsed) || parsed < 1) {
    return 1
  }
  return parsed
}

const threadId = computed(() => {
  const rawValue = route.params.tid
  const parsed = Number.parseInt(typeof rawValue === 'string' ? rawValue : '', 10)
  return Number.isNaN(parsed) ? null : parsed
})

/**
 * 获取主题详情数据。
 *
 * @param id 主题 ID
 * @return Promise<void>
 * 副作用：更新主题详情状态与错误提示。
 */
const loadThread = async (id: number) => {
  loadingThread.value = true
  errorMessage.value = ''
  try {
    const response = await fetchThread(id)
    thread.value = response.data
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : '加载失败'
    thread.value = null
  } finally {
    loadingThread.value = false
  }
}

/**
 * 获取楼层列表，并重置历史版本状态。
 *
 * @param id 主题 ID
 * @param page 页码
 * @return Promise<void>
 * 副作用：更新楼层列表、分页信息与错误提示。
 */
const loadPosts = async (id: number, page: number) => {
  loadingPosts.value = true
  postsError.value = ''
  revisionStates.value = {}
  try {
    const response = await fetchThreadPosts(id, {
      page,
      per_page: perPage,
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
 * 获取或初始化楼层历史版本状态。
 *
 * @param postId 楼层 ID
 * @return 楼层历史版本状态
 * 副作用：必要时会写入本地状态缓存。
 */
const getRevisionState = (postId: number): RevisionState => {
  const existing = revisionStates.value[postId]
  if (existing) {
    return existing
  }
  const nextState: RevisionState = {
    open: false,
    loading: false,
    error: '',
    page: 1,
    meta: null,
    data: [],
  }
  revisionStates.value = {
    ...revisionStates.value,
    [postId]: nextState,
  }
  return nextState
}

/**
 * 加载楼层历史版本列表（按时间倒序）。
 *
 * @param postId 楼层 ID
 * @param page 页码
 * @return Promise<void>
 * 副作用：更新历史版本状态与错误提示。
 */
const loadPostRevisions = async (postId: number, page: number) => {
  const state = getRevisionState(postId)
  state.loading = true
  state.error = ''
  try {
    const response = await fetchPostRevisions(postId, {
      page,
      per_page: revisionPerPage,
    })
    state.data = page === 1 ? response.data : [...state.data, ...response.data]
    state.meta = response.meta
    state.page = page
  } catch (error) {
    state.error = error instanceof Error ? error.message : '加载失败'
  } finally {
    state.loading = false
  }
}

/**
 * 切换历史版本面板显示状态。
 *
 * @param postId 楼层 ID
 * @return Promise<void>
 * 副作用：更新历史版本开关，并可能触发首次加载。
 */
const togglePostRevisions = async (postId: number) => {
  const state = getRevisionState(postId)
  state.open = !state.open
  const shouldLoad = state.open && state.data.length === 0 && !state.loading
  // 业务规则：首次展开时自动加载历史版本
  if (shouldLoad) {
    await loadPostRevisions(postId, 1)
  }
}

/**
 * 判断是否还能加载更多历史版本。
 *
 * @param state 历史版本状态
 * @return 是否还能加载更多
 */
const canLoadMoreRevisions = (state: RevisionState): boolean => {
  if (!state.meta) {
    return false
  }
  return state.meta.page < state.meta.total_pages
}

/**
 * 将变更原因 token 转换为中文说明。
 *
 * @param reason 后端返回的变更原因串
 * @return 中文可读说明
 */
const formatChangeReason = (reason: string): string => {
  // 业务含义：变更原因 token 与中文说明的映射关系
  const mapping: Record<string, string> = {
    content_fingerprint_changed: '内容变化',
    marked_deleted_by_source: '标记删除',
    marked_folded_by_source: '标记折叠',
  }
  return reason
    .split(';')
    .map((token) => mapping[token] || token)
    .filter((token) => token !== '')
    .join('、')
}

/**
 * 跳转至指定页码。
 *
 * @param page 页码
 * @return void
 * 副作用：更新路由参数并触发数据刷新。
 */
const goToPage = (page: number) => {
  router.push({
    path: `/threads/${threadId.value}`,
    query: page > 1 ? { page: String(page) } : {},
  })
}

watch(
  [() => route.params.tid, () => route.query.page],
  () => {
    const id = threadId.value
    if (!id) {
      errorMessage.value = '主题编号无效'
      thread.value = null
      posts.value = []
      meta.value = null
      return
    }
    currentPage.value = parsePage(route.query.page)
    loadThread(id)
    loadPosts(id, currentPage.value)
  },
  { immediate: true }
)
</script>

<template>
  <section class="detail-header">
    <RouterLink class="back-link" to="/">← 返回列表</RouterLink>
  </section>

  <section v-if="loadingThread" class="state">加载主题信息中...</section>
  <section v-else-if="errorMessage" class="state error">
    {{ errorMessage }}
  </section>

  <section v-else-if="thread" class="thread-detail">
    <h2 class="detail-title">{{ thread.title }}</h2>
    <div class="detail-badges">
      <span v-if="thread.title_prefix_text" class="badge">
        {{ thread.title_prefix_text }}
      </span>
      <span v-if="thread.is_pinned" class="badge badge-pinned">置顶</span>
      <span v-if="thread.is_digest" class="badge badge-digest">精华</span>
    </div>
    <div class="detail-meta">
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
      <span>回复 {{ thread.reply_count_display }}</span>
    </div>
    <div class="detail-notice" v-if="thread.is_truncated_by_page_limit">
      该主题尚未抓全（分段补齐中）
    </div>
    <div class="detail-notice" v-if="thread.is_skipped_by_page_total_limit">
      主题页数过大，暂不抓取楼层
    </div>
  </section>

  <section v-if="loadingPosts" class="state">加载楼层中...</section>
  <section v-else-if="postsError" class="state error">
    {{ postsError }}
    <button class="button" @click="threadId && loadPosts(threadId, currentPage)">
      重试
    </button>
  </section>
  <section v-else-if="posts.length === 0" class="state">暂无楼层</section>

  <section v-else class="post-list">
    <article v-for="post in posts" :key="post.floor_number" class="post-card">
      <header class="post-header">
        <span class="post-floor">#{{ post.floor_number }}</span>
        <span class="post-author">{{ post.author_name }}</span>
        <span class="post-time">{{ formatDateTime(post.post_created_at) }}</span>
        <span v-if="post.is_deleted_by_source" class="badge badge-muted">
          已删除
        </span>
        <span v-if="post.is_folded_by_source" class="badge badge-muted">
          已折叠
        </span>
      </header>
      <div class="post-content" v-html="normalizePostHtml(post.content_html)"></div>
      <div class="post-history">
        <span v-if="post.content_last_changed_at" class="post-history-time">
          最近变更：{{ formatDateTime(post.content_last_changed_at) }}
        </span>
        <button
          v-if="post.revision_count > 0"
          class="link post-history-toggle"
          type="button"
          @click="togglePostRevisions(post.post_id)"
        >
          {{ getRevisionState(post.post_id).open ? '收起历史' : '历史版本' }}
          ({{ post.revision_count }})
        </button>
      </div>
      <div v-if="post.revision_count > 0 && getRevisionState(post.post_id).open" class="revision-panel">
        <div v-if="getRevisionState(post.post_id).loading" class="revision-state">
          加载历史版本中...
        </div>
        <div v-else-if="getRevisionState(post.post_id).error" class="revision-state error">
          {{ getRevisionState(post.post_id).error }}
          <button
            class="button"
            type="button"
            @click="loadPostRevisions(post.post_id, getRevisionState(post.post_id).page)"
          >
            重试
          </button>
        </div>
        <div v-else-if="getRevisionState(post.post_id).data.length === 0" class="revision-state">
          暂无历史版本
        </div>
        <div v-else class="revision-list">
          <article
            v-for="revision in getRevisionState(post.post_id).data"
            :key="`${revision.revision_created_at}-${revision.change_detected_reason}`"
            class="revision-item"
          >
            <header class="revision-meta">
              <span>变更：{{ formatDateTime(revision.revision_created_at) }}</span>
              <span v-if="revision.source_edited_at">
                源站编辑：{{ formatDateTime(revision.source_edited_at) }}
              </span>
              <span class="revision-reason">
                {{ formatChangeReason(revision.change_detected_reason) }}
              </span>
            </header>
            <div class="revision-content" v-html="normalizePostHtml(revision.content_html)"></div>
          </article>
          <button
            v-if="canLoadMoreRevisions(getRevisionState(post.post_id))"
            class="button revision-more"
            type="button"
            @click="loadPostRevisions(post.post_id, getRevisionState(post.post_id).page + 1)"
          >
            展开更多
          </button>
        </div>
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
