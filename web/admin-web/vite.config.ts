import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// 开发时唯一代理规则：/api → 本机 webman (php windows.php, 端口 8787)
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': { target: 'http://127.0.0.1:8787', changeOrigin: false },
    },
  },
  build: {
    outDir: 'dist',
  },
});
