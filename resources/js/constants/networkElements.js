// Catálogo de tipos de "Elemento de Red".
//
// Dos familias:
//   - infra : infraestructura inalámbrica / genérica (sectorial, switch, nodo).
//   - fiber : planta externa de fibra FTTH/GPON (olt, splitter, nap, mufa),
//             que se organiza en árbol vía `parent_id` (OLT → splitter → NAP).
//
// Lo usan el formulario de alta/edición, la lista de Elementos de Red y la
// vista de Topología FTTH. La ocupación de puertos (ports_used / ports_capacity
// / ports_free) la calcula el backend y viene en la respuesta de la API.

// OJO: las clases de color van como string literal completo (no interpolado)
// para que el JIT de Tailwind las detecte y no las purgue del build.
export const ELEMENT_TYPES = [
    // Infraestructura inalámbrica / genérica
    { value: "sectorial", label: "Sectorial", icon: "md-router",      hint: "Punto de acceso wireless",     group: "infra", color: "bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800" },
    { value: "switch",    label: "Switch",    icon: "bi-hdd-network", hint: "Switch / equipo de capa 2",    group: "infra", color: "bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-300 dark:border-purple-800" },
    { value: "nodo",      label: "Nodo",      icon: "bi-diagram-3",   hint: "Nodo / sitio / torre",         group: "infra", color: "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800" },
    // Planta externa de fibra (FTTH/GPON)
    { value: "olt",       label: "OLT",       icon: "bi-server",      hint: "Cabecera de fibra (GPON)",     group: "fiber", color: "bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-900/30 dark:text-rose-300 dark:border-rose-800" },
    { value: "splitter",  label: "Splitter",  icon: "bi-diagram-2",   hint: "Divisor óptico 1:N",           group: "fiber", color: "bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800" },
    { value: "nap",       label: "Caja NAP",  icon: "bi-box-seam",    hint: "Caja de distribución / acceso", group: "fiber", color: "bg-cyan-50 text-cyan-700 border-cyan-200 dark:bg-cyan-900/30 dark:text-cyan-300 dark:border-cyan-800" },
    { value: "mufa",      label: "Mufa",      icon: "bi-link-45deg",  hint: "Cierre / empalme de fibra",    group: "fiber", color: "bg-slate-50 text-slate-700 border-slate-200 dark:bg-slate-900/30 dark:text-slate-300 dark:border-slate-800" },
];

// value -> meta
export const ELEMENT_META = ELEMENT_TYPES.reduce((acc, t) => {
    acc[t.value] = t;
    return acc;
}, {});

const FALLBACK = ELEMENT_META.sectorial;

export const FIBER_TYPES = ELEMENT_TYPES.filter((t) => t.group === "fiber").map((t) => t.value);

export const elementMeta  = (t) => ELEMENT_META[t] || FALLBACK;
export const elementLabel = (t) => elementMeta(t).label;
export const elementIcon  = (t) => elementMeta(t).icon;
export const elementBadge = (t) => elementMeta(t).color;
export const isFiber      = (t) => FIBER_TYPES.includes(t);

// Filtros para la lista (incluye "Todos").
export const ELEMENT_FILTERS = [
    { value: "all", label: "Todos", icon: "" },
    ...ELEMENT_TYPES.map((t) => ({ value: t.value, label: t.label, icon: t.icon })),
];

// Ratios típicos de splitter óptico.
export const SPLIT_RATIOS = ["1:2", "1:4", "1:8", "1:16", "1:32", "1:64"];

// "1:8" -> 8 (número de puertos derivado del ratio).
export function splitRatioPorts(ratio) {
    if (!ratio) return null;
    const m = String(ratio).match(/(\d+)\s*$/);
    return m ? Number(m[1]) : null;
}
