// Catálogo de antenas y radios de cobertura aproximados (metros).
//
// El radio real depende de muchos factores (potencia, altura, terreno,
// ganancia), así que estos valores son un punto de partida razonable que el
// usuario puede ajustar a mano por cada elemento. Lo usan tanto los formularios
// de sectoriales (para sugerir un radio) como el Mapa de Clientes (para dibujar
// las zonas de cobertura).

export const DEFAULT_COVERAGE_RADIUS = 800; // sectorial sin antena definida
export const NAP_COVERAGE_RADIUS = 200; // caja NAP / nodo de acceso

// Subtipos de elemento de red (para el selector "Subtipo" de las sectoriales).
export const SECTORIAL_SUBTYPES = [
    { value: "Access Point", label: "📡 Access Point" },
    { value: "Station", label: "📶 Station" },
    { value: "NAP", label: "🏢 NAP" },
    { value: "Bridge", label: "🌉 Bridge" },
    { value: "Repeater", label: "🔄 Repeater" },
    { value: "PTP", label: "↔️ PTP (Punto a Punto)" },
    { value: "PTMP", label: "🔀 PTMP (Punto Multipunto)" },
];

export const ANTENNA_GROUPS = [
    {
        label: "Mimosa — Acceso / Sector",
        options: [
            { value: "mimosa-a5c", label: "Mimosa A5c (sector)", radius: 800 },
            { value: "mimosa-a5-14", label: "Mimosa A5-14", radius: 700 },
            { value: "mimosa-a5-360", label: "Mimosa A5-360 (omni)", radius: 450 },
            { value: "mimosa-n5-360", label: "Mimosa N5-360 (omni)", radius: 350 },
        ],
    },
    {
        label: "Mimosa — PTP / Backhaul",
        options: [
            { value: "mimosa-b5c", label: "Mimosa B5c", radius: 700 },
            { value: "mimosa-b5x", label: "Mimosa B5x", radius: 700 },
            { value: "mimosa-b5", label: "Mimosa B5 / B5-Lite", radius: 600 },
        ],
    },
    {
        label: "Mimosa — Cliente (CPE)",
        options: [
            { value: "mimosa-c5c", label: "Mimosa C5c", radius: 250 },
            { value: "mimosa-c5x", label: "Mimosa C5x", radius: 250 },
            { value: "mimosa-c5", label: "Mimosa C5", radius: 250 },
        ],
    },
    {
        label: "Mikrotik",
        options: [
            { value: "mikrotik-qrt5", label: "Mikrotik QRT 5", radius: 800 },
            { value: "mikrotik-basebox", label: "Mikrotik Basebox (+ sectorial)", radius: 800 },
            { value: "mikrotik-netmetal", label: "Mikrotik NetMetal (+ sectorial)", radius: 1000 },
            { value: "mikrotik-mantbox", label: "Mikrotik mANTBox (sector)", radius: 800 },
            { value: "mikrotik-sxt", label: "Mikrotik SXT / SXTsq", radius: 300 },
            { value: "mikrotik-lhg", label: "Mikrotik LHG", radius: 500 },
            { value: "mikrotik-dynadish", label: "Mikrotik DynaDish", radius: 900 },
            { value: "mikrotik-cube", label: "Mikrotik Cube / Wireless Wire", radius: 250 },
        ],
    },
    {
        label: "Ubiquiti",
        options: [
            { value: "ubnt-rocket-sector", label: "Ubiquiti Rocket + Sectorial", radius: 900 },
            { value: "ubnt-ltu-rocket", label: "Ubiquiti LTU Rocket", radius: 900 },
            { value: "ubnt-powerbeam", label: "Ubiquiti PowerBeam", radius: 700 },
            { value: "ubnt-nanobeam", label: "Ubiquiti NanoBeam", radius: 500 },
            { value: "ubnt-litebeam", label: "Ubiquiti LiteBeam", radius: 400 },
            { value: "ubnt-nanostation", label: "Ubiquiti NanoStation", radius: 400 },
            { value: "ubnt-airfiber", label: "Ubiquiti airFiber", radius: 1200 },
        ],
    },
    {
        label: "Cambium",
        options: [
            { value: "cambium-epmp-sector", label: "Cambium ePMP (Sectorial)", radius: 800 },
            { value: "cambium-force300", label: "Cambium ePMP Force 300", radius: 600 },
        ],
    },
    {
        label: "Otros",
        options: [
            { value: "caja-nap", label: "Caja NAP / Omni corto", radius: NAP_COVERAGE_RADIUS },
            { value: "generica", label: "Otra / Genérica", radius: DEFAULT_COVERAGE_RADIUS },
        ],
    },
];

// Lista plana (con el grupo/marca incluido) para selectores con buscador.
export const ANTENNA_OPTIONS = ANTENNA_GROUPS.flatMap((group) =>
    group.options.map((opt) => ({ ...opt, group: group.label }))
);

// value -> { value, label, radius }
export const ANTENNA_INDEX = ANTENNA_GROUPS.reduce((acc, group) => {
    group.options.forEach((opt) => {
        acc[opt.value] = opt;
    });
    return acc;
}, {});

export function antennaLabel(value) {
    return ANTENNA_INDEX[value]?.label || value || null;
}

export function antennaRadius(value) {
    const found = ANTENNA_INDEX[value];
    return found ? found.radius : null;
}

// Radio sugerido a partir de la antena; si no hay antena se usa el subtipo
// (NAP = corto) y como último recurso el default de una sectorial.
export function suggestedRadius(antennaType, subtype) {
    const fromAntenna = antennaRadius(antennaType);
    if (fromAntenna != null) return fromAntenna;
    if (subtype === "NAP") return NAP_COVERAGE_RADIUS;
    return DEFAULT_COVERAGE_RADIUS;
}

// Radio efectivo para dibujar en el mapa: respeta el valor manual si existe,
// si no, lo deriva de la antena / subtipo.
export function effectiveCoverageRadius(node = {}) {
    const manual = Number(node.coverage_radius_meters);
    if (Number.isFinite(manual) && manual > 0) return manual;
    return suggestedRadius(node.antenna_type, node.type);
}
