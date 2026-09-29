/**
 * Capa de datos: WordPress headless (ACF Pro) → tipos limpios para Astro.
 * Endpoint: {WORDPRESS_API_URL}/wp/v2/propiedades?acf_format=standard
 */

const API = (import.meta.env.WORDPRESS_API_URL || 'https://propiedadescarolina.cl/admin/wp-json').replace(/\/$/, '');

export type TipoOperacion = 'venta' | 'arriendo';
export type TipoPropiedad =
  | 'casa' | 'departamento' | 'terreno' | 'parcela' | 'oficina'
  | 'local' | 'comercial' | 'industrial' | 'bodega';

export interface Imagen {
  id: number;
  url: string;      // original
  large: string;    // ≤1024px
  medium: string;   // ≤300px (para thumbnails)
  alt: string;
  width: number;
  height: number;
}

export interface Propiedad {
  id: number;
  slug: string;
  titulo: string;
  descripcion: string; // HTML
  fecha: string;
  operacion: TipoOperacion;
  tipo: TipoPropiedad;
  codigo: string;
  destacada: boolean;
  antiguedad: 'nueva' | 'usada' | '';
  piso: number | null;
  precioUf: number | null;
  precioPesos: number | null;
  precioArriendoUf: number | null;
  dormitorios: number | null;
  banos: number | null;
  estacionamientos: number | null;
  m2Construidos: number | null;
  m2Terreno: number | null;
  amenidades: string[];
  region: string;
  ciudad: string;
  sector: string;
  direccion: string;
  mapa: { lat: number; lng: number; address: string } | null;
  imagen: Imagen | null;
  galeria: Imagen[];
  videoUrl: string;
  corredor: { nombre: string; telefono: string; email: string; foto: Imagen | null };
}

export const TIPO_LABEL: Record<TipoPropiedad, string> = {
  casa: 'Casa', departamento: 'Departamento', terreno: 'Terreno', parcela: 'Parcela',
  oficina: 'Oficina', local: 'Local comercial', comercial: 'Comercial', industrial: 'Industrial', bodega: 'Bodega',
};
export const OPERACION_LABEL: Record<TipoOperacion, string> = { venta: 'En venta', arriendo: 'En arriendo' };

/* ---------- helpers ---------- */
const num = (v: unknown): number | null => {
  if (v === '' || v === null || v === undefined || v === false) return null;
  const n = typeof v === 'number' ? v : parseFloat(String(v).replace(',', '.'));
  return Number.isFinite(n) ? n : null;
};

const imagen = (raw: any): Imagen | null => {
  if (!raw || typeof raw !== 'object' || !raw.url) return null;
  const s = raw.sizes || {};
  return {
    id: raw.id ?? raw.ID ?? 0,
    url: raw.url,
    large: s.large || raw.url,
    medium: s.medium_large || s.medium || raw.url,
    alt: raw.alt || raw.title || '',
    width: raw.width || 0,
    height: raw.height || 0,
  };
};

const decode = (s: string) =>
  s.replace(/&#8211;/g, '–').replace(/&#8217;/g, '’').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;/g, "'");

function mapear(p: any): Propiedad {
  const a = p.acf || {};
  const galeria = Array.isArray(a.galeria) ? a.galeria.map(imagen).filter(Boolean) as Imagen[] : [];
  const principal = imagen(a.imagen_principal) || galeria[0] || null;
  const mapa = a.mapa && typeof a.mapa === 'object' && a.mapa.lat ? { lat: +a.mapa.lat, lng: +a.mapa.lng, address: a.mapa.address || '' } : null;
  return {
    id: p.id,
    slug: p.slug,
    titulo: decode(p.title?.rendered || ''),
    descripcion: a.descripcion_larga || p.content?.rendered || '',
    fecha: p.date,
    operacion: a.tipo_operacion === 'arriendo' ? 'arriendo' : 'venta',
    tipo: (a.tipo_propiedad || 'casa') as TipoPropiedad,
    codigo: a.codigo_interno || '',
    destacada: !!a.destacada,
    antiguedad: a.antiguedad || '',
    piso: num(a.piso),
    precioUf: num(a.precio_uf),
    precioPesos: num(a.precio_pesos),
    precioArriendoUf: num(a.precio_arriendo_uf),
    dormitorios: num(a.dormitorios),
    banos: num(a.banos),
    estacionamientos: num(a.estacionamientos),
    m2Construidos: num(a.metros_construidos),
    m2Terreno: num(a.metros_terreno),
    amenidades: Array.isArray(a.amenidades) ? a.amenidades : [],
    region: a.region || '',
    ciudad: (a.ciudad || '').trim(),
    sector: (a.sector || '').trim(),
    direccion: (a.direccion || '').trim(),
    mapa,
    imagen: principal,
    galeria: galeria.length ? galeria : principal ? [principal] : [],
    videoUrl: a.video_url || '',
    corredor: {
      nombre: a.corredor_nombre || 'Carolina Robles',
      telefono: a.corredor_telefono || '+56 9 9315 1165',
      email: a.corredor_email || 'contacto@propiedadescarolina.cl',
      foto: imagen(a.corredor_foto),
    },
  };
}

/* ---------- fetch (cache por build) ---------- */
let cache: Propiedad[] | null = null;

export async function getPropiedades(): Promise<Propiedad[]> {
  if (cache) return cache;
  const out: any[] = [];
  for (let page = 1; page <= 10; page++) {
    const res = await fetch(`${API}/wp/v2/propiedades?per_page=100&page=${page}&acf_format=standard&_embed=0`);
    if (!res.ok) {
      if (page > 1) break;
      throw new Error(`WordPress API ${res.status}: ${API}`);
    }
    const data = await res.json();
    out.push(...data);
    if (data.length < 100) break;
  }
  cache = out.map(mapear).sort((a, b) => Number(b.destacada) - Number(a.destacada) || b.fecha.localeCompare(a.fecha));
  return cache;
}

export async function getPropiedad(slug: string) {
  return (await getPropiedades()).find((p) => p.slug === slug) || null;
}

/* ---------- formato ---------- */
export function precio(p: Propiedad): { valor: string; unidad: string; sufijo: string } {
  const suf = p.operacion === 'arriendo' ? '/mes' : '';
  if (p.precioUf) return { valor: `UF ${p.precioUf.toLocaleString('es-CL', { maximumFractionDigits: 2 })}`, unidad: 'UF', sufijo: suf };
  if (p.precioArriendoUf) return { valor: `UF ${p.precioArriendoUf.toLocaleString('es-CL', { maximumFractionDigits: 2 })}`, unidad: 'UF', sufijo: '/mes' };
  if (p.precioPesos) return { valor: `$ ${p.precioPesos.toLocaleString('es-CL')}`, unidad: 'CLP', sufijo: suf };
  return { valor: 'Consultar', unidad: '', sufijo: '' };
}

export const ubicacion = (p: Propiedad) => [p.sector && p.sector !== p.ciudad ? p.sector : '', p.ciudad].filter(Boolean).join(', ');

/** Versión liviana para el buscador del navegador (JSON embebido). */
export function resumen(p: Propiedad) {
  return {
    id: p.id, slug: p.slug, titulo: p.titulo, operacion: p.operacion, tipo: p.tipo,
    ciudad: p.ciudad, sector: p.sector, destacada: p.destacada,
    precioUf: p.precioUf, precioPesos: p.precioPesos, precioArriendoUf: p.precioArriendoUf,
    dormitorios: p.dormitorios, banos: p.banos, estacionamientos: p.estacionamientos,
    m2: p.m2Construidos || p.m2Terreno, imagen: p.imagen?.medium || '', fecha: p.fecha,
  };
}
export type PropiedadResumen = ReturnType<typeof resumen>;

export const WHATSAPP = '56993151165';
export const waLink = (texto: string) => `https://wa.me/${WHATSAPP}?text=${encodeURIComponent(texto)}`;
