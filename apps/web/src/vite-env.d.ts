/// <reference types="vite/client" />

// Project-specific env vars exposed via import.meta.env (must be VITE_ prefixed).
interface ImportMetaEnv {
  readonly VITE_WP_URL?: string;
  readonly VITE_API_BASE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
