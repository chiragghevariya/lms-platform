export const env = {
  appName: import.meta.env.VITE_APP_NAME || 'Brand',
  tagline: import.meta.env.VITE_APP_TAGLINE || 'Learn Skills. Build Income.',
  apiUrl: (import.meta.env.VITE_API_URL || '').replace(/\/$/, ''),
} as const
