/**
 * Nombre completo a mostrar para un cliente (User) tal como llega embebido en
 * facturas/pagos: prioriza customer_profile.name + last_name (se mantiene
 * sincronizado en cada edición, ver CustomerProfileController::update), cae a
 * user_name (que en todo el sistema es SOLO el primer nombre) y por último a
 * "Desconocido". Filtra partes null/undefined/vacías para no concatenar
 * "null"/"undefined" ni dejar espacios dobles.
 *
 * @param {{ customer_profile?: { name?: string, last_name?: string }, user_name?: string } | null | undefined} customer
 */
export function customerDisplayName(customer) {
    if (!customer) return 'Desconocido'

    const profile = customer.customer_profile
    if (profile) {
        const fullName = [profile.name, profile.last_name]
            .filter(part => part && String(part).trim() !== '')
            .join(' ')
        if (fullName) return fullName
    }

    return customer.user_name?.trim() || 'Desconocido'
}
