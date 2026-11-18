/// <reference types="vitest/config" />
import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backend = env.VITE_DEV_BACKEND_URL || 'http://localhost:8010'

  return {
    plugins: [
      react(),
      tailwindcss(),
      {
        // robots.txt needs an absolute sitemap URL, so it's generated per environment at build time.
        name: 'robots-txt',
        apply: 'build',
        generateBundle() {
          const site = (env.VITE_SITE_URL || '').replace(/\/$/, '')
          const lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /app',
            'Disallow: /admin',
            'Disallow: /instructor',
            'Disallow: /learn',
          ]
          if (site) lines.push('', `Sitemap: ${site}/sitemap.xml`)
          this.emitFile({ type: 'asset', fileName: 'robots.txt', source: lines.join('\n') + '\n' })
        },
      },
    ],
    resolve: { alias: { '@': path.resolve(import.meta.dirname, './src') } },
    // Lazy-loaded heavy deps: pre-bundle at dev-server start so the first visit doesn't trigger a
    // re-optimise + full reload (which mixed two copies of shared deps and blanked the page).
    optimizeDeps: { include: ['@zoom/meetingsdk/embedded', 'hls.js', 'plyr', 'recharts'] },
    server: {
      port: 5180,
      strictPort: true,
      // Same-origin in dev: Sanctum session + XSRF cookies "just work".
      proxy: {
        '/api': { target: backend, changeOrigin: true },
        '/sanctum': { target: backend, changeOrigin: true },
        // Google OAuth round-trip + sitemap are served by Laravel's web routes.
        '/auth/google': { target: backend, changeOrigin: true },
        '/sitemap.xml': { target: backend, changeOrigin: true },
      },
    },
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./src/test/setup.ts'],
      css: false,
    },
  }
})
