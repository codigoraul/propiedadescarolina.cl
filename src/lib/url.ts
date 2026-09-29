/** Antepone el `base` de Astro (p. ej. "/nueva") a rutas absolutas del sitio. */
const BASE = import.meta.env.BASE_URL.replace(/\/$/, '');
export const u = (path: string) => BASE + path;
