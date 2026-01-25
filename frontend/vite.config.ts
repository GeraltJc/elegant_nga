import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const proxyTarget = process.env.VITE_PROXY_TARGET ?? 'http://localhost:8080'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        // 边界条件：容器内需指向 nginx 服务，避免 localhost 指向自身导致拒绝连接
        target: proxyTarget,
        changeOrigin: true,
      },
      '/sanctum': {
        // 边界条件：容器内需指向 nginx 服务，避免 localhost 指向自身导致拒绝连接
        target: proxyTarget,
        changeOrigin: true,
      },
    },
  },
})
