import { createRouter, createWebHistory } from 'vue-router'
import CrawlRunDetailPage from './pages/CrawlRunDetailPage.vue'
import CrawlRunListPage from './pages/CrawlRunListPage.vue'
import FloorAuditRunDetailPage from './pages/FloorAuditRunDetailPage.vue'
import FloorAuditRunListPage from './pages/FloorAuditRunListPage.vue'
import FloorAuditThreadDetailPage from './pages/FloorAuditThreadDetailPage.vue'
import ThreadDetailPage from './pages/ThreadDetailPage.vue'
import ThreadListPage from './pages/ThreadListPage.vue'
import NotFoundPage from './pages/NotFoundPage.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'threads', component: ThreadListPage },
    { path: '/crawl-runs', name: 'crawl-runs', component: CrawlRunListPage },
    {
      path: '/crawl-runs/:runId(\\d+)',
      name: 'crawl-run-detail',
      component: CrawlRunDetailPage,
    },
    { path: '/floor-audit-runs', name: 'floor-audit-runs', component: FloorAuditRunListPage },
    {
      path: '/floor-audit-runs/:runId(\\d+)',
      name: 'floor-audit-run-detail',
      component: FloorAuditRunDetailPage,
    },
    {
      path: '/floor-audit-threads/:auditThreadId(\\d+)',
      name: 'floor-audit-thread-detail',
      component: FloorAuditThreadDetailPage,
    },
    {
      path: '/threads/:tid(\\d+)',
      name: 'thread-detail',
      component: ThreadDetailPage,
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
  ],
  scrollBehavior() {
    return { top: 0 }
  },
})

export default router
