/**
 * Nombre completo a mostrar para un cliente (User) tal como llega embebido en
 * facturas/pagos: prioriza customer_profile.name + last_name (se mantiene
 * sincronizado en cada edición, ver CustomerProfileController::update), cae a
 * user_name (que en todo el sistema es SOLO el primer nombre), luego al nombre
 * CONGELADO en la propia fila y por último a "Desconocido". Filtra partes
 * null/undefined/vacías para no concatenar "null"/"undefined" ni dejar espacios
 * dobles.
 *
 * Sobre `congelado` (P-43): desde que borrar un cliente ya no destruye su
 * histórico, `invoice.customer` / `payment.customer` pueden venir en `null` y
 * la fila trae `customer_name` con el titular tal como se emitió. Sin este
 * respaldo, toda la contabilidad de un cliente dado de baja se veía como
 * "Desconocido" — que es exactamente igual de inservible que haberla borrado.
 *
 * El cliente vivo va PRIMERO a propósito: en una pantalla lo útil es el nombre
 * de hoy, y el congelado puede ser de hace años. El criterio se invierte en el
 * PDF, que sí prefiere el congelado (ver PlaceholderResolver): un documento
 * emitido debe seguir diciendo lo que decía.
 *
 * @param {{ customer_profile?: { name?: string, last_name?: string }, user_name?: string } | null | undefined} customer
 * @param {string | null | undefined} [congelado] `customer_name` de la factura o el pago.
 */
export function customerDisplayName(customer, congelado) {
    const respaldo = congelado?.trim() || 'Desconocido'

    if (!customer) return respaldo

    const profile = customer.customer_profile
    if (profile) {
        const fullName = [profile.name, profile.last_name]
            .filter(part => part && String(part).trim() !== '')
            .join(' ')
        if (fullName) return fullName
    }

    return customer.user_name?.trim() || respaldo
}
