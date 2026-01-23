import { createRouter, createWebHistory } from 'vue-router'
import ThreadDetailPage from './pages/ThreadDetailPage.vue'
import ThreadListPage from './pages/ThreadListPage.vue'
import NotFoundPage from './pages/NotFoundPage.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'threads', component: ThreadListPage },
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
