# propiedades-carolina-astro

Sitio público de **Carolina Robles Propiedades** (propiedadescarolina.cl) en Astro 5 + Tailwind 4, estático, alimentado por el WordPress headless que vive en `propiedadescarolina.cl/admin/` (ACF Pro, CPT `propiedad`).

## Desarrollo
```bash
npm install
cp .env.example .env   # WORDPRESS_API_URL=https://propiedadescarolina.cl/admin/wp-json
npm run dev            # http://localhost:4321
npm run build          # genera dist/
```
Los datos se leen **en el build** desde `/wp/v2/propiedades?acf_format=standard` (ver `src/lib/wp.ts`). Cada vez que Carolina publica o edita una propiedad hay que volver a construir (o disparar el workflow).

## Estructura
- `src/lib/wp.ts` — fetch + tipado + helpers (precio, ubicación, WhatsApp).
- `src/layouts/Base.astro` — SEO, fuentes, header/footer, botón WhatsApp.
- `src/components/` — Header, Footer, SearchBar (GET → /propiedades/, filtra en vivo), PropertyCard, WhatsAppFloat.
- `src/pages/` — `index`, `propiedades/index` (filtros y orden en el navegador), `propiedades/[slug]` (galería + lightbox, ficha, mapa, similares, JSON-LD), `nosotros`, `contacto`, `404`.
- `public/contacto.php` — handler del formulario (PHP `mail()` en cPanel), responde JSON. Destinatarios al inicio del archivo.
- `public/img/logo-horizontal.png` — logo (versión crema para fondos oscuros).

## Colores (del logotipo) — `src/styles/global.css`
verde `#4f7a2e` · dorado `#c8a048` · crema `#f8f0e8` · carbón `#161810`. Fuentes: Plus Jakarta Sans + Cormorant Garamond (títulos hero).

## Deploy (GitHub Actions → FTP cPanel)
`.github/workflows/deploy.yml` construye y sube `dist/` a `public_html/` por FTP en cada push a `main` o por `workflow_dispatch`.
Secrets necesarios en el repo: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
**Importante:** el workflow NO borra archivos en el servidor (`dangerous-clean-slate: false`) y excluye `admin/`, así el WordPress headless en `public_html/admin/` queda intacto. Antes del primer deploy hay que vaciar a mano el WordPress viejo de `public_html/` (dejar solo `admin/`).

Para que WordPress dispare el deploy al publicar, reutilizar el plugin "GitHub Auto Deploy" de rematesbipro (workflow_dispatch con PAT).
