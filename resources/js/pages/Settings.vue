<template>
    <div class="flex min-h-screen bg-gray-50 dark:bg-gray-900">
        <!-- Notification Toast -->
        <NotificationToast ref="toast" />

        <main class="flex-1 p-4 md:p-8">
            <!-- Header -->
            <div
                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8"
            >
                <div>
                    <h1
                        class="text-2xl md:text-3xl font-semibold text-gray-800 dark:text-white flex items-center gap-2"
                    >
                        <div
                            class="p-2 bg-indigo-100 dark:bg-indigo-900/30 rounded-xl"
                        >
                            <v-icon
                                name="ri-settings-4-line"
                                class="text-indigo-600 dark:text-indigo-400 w-6 h-6 md:w-7 md:h-7"
                            />
                        </div>
                        Configuración
                    </h1>
                    <p
                        class="text-sm md:text-base text-gray-600 dark:text-gray-300 mt-1"
                    >
                        Gestiona las preferencias del sistema
                    </p>
                </div>

                <button
                    @click="saveAllSettings"
                    :disabled="!hasChanges"
                    class="bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white px-5 py-3 rounded-xl flex items-center justify-center gap-2 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 font-medium w-full sm:w-auto disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <v-icon name="md-save" class="w-5 h-5 fill-current" />
                    <span>Guardar Cambios</span>
                </button>
            </div>

            <!-- Settings Navigation Tabs -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl shadow-md mb-6 overflow-hidden"
            >
                <div class="border-b border-gray-200 dark:border-gray-700">
                    <nav class="flex overflow-x-auto">
                        <button
                            v-for="tab in tabs"
                            :key="tab.id"
                            @click="activeTab = tab.id"
                            :class="[
                                'flex items-center gap-2 px-6 py-4 text-sm font-medium transition-all whitespace-nowrap',
                                activeTab === tab.id
                                    ? 'border-b-2 border-indigo-600 text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/20'
                                    : 'text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700/50',
                            ]"
                        >
                            <v-icon :name="tab.icon" class="w-5 h-5" />
                            {{ tab.label }}
                        </button>
                    </nav>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="space-y-6">
                <!-- General Settings -->
                <div v-if="activeTab === 'general'" class="space-y-6">
                    <SettingsSection
                        title="Información General"
                        description="Configuración básica de la aplicación"
                        icon="md-info"
                    >
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="label"
                                    >Nombre de la Empresa</label
                                >
                                <input
                                    v-model="settings.company_name"
                                    type="text"
                                    placeholder="ISPWatch"
                                    class="input"
                                    :class="{ 'border-red-500': errors.name }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.name"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.name[0] }}
                                </p>
                                <p
                                    v-if="!isAdmin"
                                    class="text-xs text-amber-600 dark:text-amber-400 mt-1"
                                >
                                    ℹ️ Solo los administradores pueden editar
                                    este campo
                                </p>
                            </div>
                            <div>
                                <label class="label">Dominio</label>
                                <input
                                    v-model="settings.domain"
                                    type="text"
                                    placeholder="ispwatch.com"
                                    class="input"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="!isAdmin"
                                    class="text-xs text-amber-600 dark:text-amber-400 mt-1"
                                >
                                    ℹ️ Solo los administradores pueden editar
                                    este campo
                                </p>
                            </div>
                            <div>
                                <label class="label">Email de Contacto</label>
                                <input
                                    v-model="settings.contact_email"
                                    type="email"
                                    placeholder="contacto@ispwatch.com"
                                    class="input"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="!isAdmin"
                                    class="text-xs text-amber-600 dark:text-amber-400 mt-1"
                                >
                                    ℹ️ Solo los administradores pueden editar
                                    este campo
                                </p>
                            </div>
                            <div>
                                <label class="label">Teléfono</label>
                                <input
                                    v-model="settings.phone"
                                    type="tel"
                                    placeholder="+57 300 123 4567"
                                    class="input"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="!isAdmin"
                                    class="text-xs text-amber-600 dark:text-amber-400 mt-1"
                                >
                                    ℹ️ Solo los administradores pueden editar
                                    este campo
                                </p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="label">Dirección</label>
                                <input
                                    v-model="settings.address"
                                    type="text"
                                    placeholder="Calle 123 #45-67"
                                    class="input"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="!isAdmin"
                                    class="text-xs text-amber-600 dark:text-amber-400 mt-1"
                                >
                                    ℹ️ Solo los administradores pueden editar
                                    este campo
                                </p>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Información Legal"
                        description="Datos fiscales de la empresa"
                        icon="ri-bank-card-line"
                    >
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="label">Razón Social</label>
                                <input
                                    v-model="settings.legal_name"
                                    type="text"
                                    placeholder="Empresa S.A.S."
                                    class="input"
                                    :class="{
                                        'border-red-500': errors.legal_name,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.legal_name"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.legal_name[0] }}
                                </p>
                            </div>
                            <div>
                                <label class="label">Nombre Comercial</label>
                                <input
                                    v-model="settings.trade_name"
                                    type="text"
                                    placeholder="Nombre de Fantasía"
                                    class="input"
                                    :class="{
                                        'border-red-500': errors.trade_name,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.trade_name"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.trade_name[0] }}
                                </p>
                            </div>
                            <div class="grid grid-cols-4 gap-2">
                                <div class="col-span-3">
                                    <label class="label">NIT</label>
                                    <input
                                        v-model="settings.nit"
                                        type="text"
                                        placeholder="900123456"
                                        class="input"
                                        :class="{
                                            'border-red-500': errors.nit,
                                        }"
                                        :disabled="!isAdmin"
                                        @input="hasChanges = true"
                                    />
                                </div>
                                <div>
                                    <label class="label">DV</label>
                                    <input
                                        v-model="
                                            settings.nit_verification_digit
                                        "
                                        type="text"
                                        placeholder="7"
                                        class="input text-center"
                                        :class="{
                                            'border-red-500':
                                                errors.nit_verification_digit,
                                        }"
                                        :disabled="!isAdmin"
                                        @input="hasChanges = true"
                                    />
                                </div>
                                <div class="col-span-4">
                                    <p
                                        v-if="errors.nit"
                                        class="text-xs text-red-500 mt-1"
                                    >
                                        {{ errors.nit[0] }}
                                    </p>
                                    <p
                                        v-if="errors.nit_verification_digit"
                                        class="text-xs text-red-500 mt-1"
                                    >
                                        {{ errors.nit_verification_digit[0] }}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <label class="label">Régimen Tributario</label>
                                <input
                                    v-model="settings.tax_regime"
                                    type="text"
                                    placeholder="Responsable de IVA"
                                    class="input"
                                    :class="{
                                        'border-red-500': errors.tax_regime,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.tax_regime"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.tax_regime[0] }}
                                </p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="label">Actividad Económica</label>
                                <input
                                    v-model="settings.economic_activity"
                                    type="text"
                                    placeholder="Código CIIU o descripción"
                                    class="input"
                                    :class="{
                                        'border-red-500':
                                            errors.economic_activity,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.economic_activity"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.economic_activity[0] }}
                                </p>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Información de Facturación"
                        description="Datos para facturación electrónica y contacto de cobro"
                        icon="ri-bill-line"
                    >
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="label"
                                    >Email de Facturación</label
                                >
                                <input
                                    v-model="settings.billing_email"
                                    type="email"
                                    placeholder="facturacion@empresa.com"
                                    class="input"
                                    :class="{
                                        'border-red-500': errors.billing_email,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.billing_email"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.billing_email[0] }}
                                </p>
                            </div>
                            <div>
                                <label class="label"
                                    >Teléfono de Facturación</label
                                >
                                <input
                                    v-model="settings.billing_phone"
                                    type="tel"
                                    placeholder="+57 300 123 4567"
                                    class="input"
                                    :class="{
                                        'border-red-500': errors.billing_phone,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.billing_phone"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.billing_phone[0] }}
                                </p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="label"
                                    >Dirección de Facturación</label
                                >
                                <input
                                    v-model="settings.billing_address"
                                    type="text"
                                    placeholder="Calle 45 # 6-78"
                                    class="input"
                                    :class="{
                                        'border-red-500':
                                            errors.billing_address,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.billing_address"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.billing_address[0] }}
                                </p>
                            </div>
                            <div>
                                <label class="label">Ciudad</label>
                                <input
                                    v-model="settings.city"
                                    type="text"
                                    placeholder="Bogotá"
                                    class="input"
                                    :class="{ 'border-red-500': errors.city }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.city"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.city[0] }}
                                </p>
                            </div>
                            <div>
                                <label class="label">Departamento</label>
                                <input
                                    v-model="settings.department"
                                    type="text"
                                    placeholder="Cundinamarca"
                                    class="input"
                                    :class="{
                                        'border-red-500': errors.department,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.department"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.department[0] }}
                                </p>
                            </div>
                            <div>
                                <label class="label">País</label>
                                <input
                                    v-model="settings.country"
                                    type="text"
                                    placeholder="CO"
                                    maxlength="2"
                                    class="input"
                                    :class="{
                                        'border-red-500': errors.country,
                                    }"
                                    :disabled="!isAdmin"
                                    @input="hasChanges = true"
                                />
                                <p
                                    v-if="errors.country"
                                    class="text-xs text-red-500 mt-1"
                                >
                                    {{ errors.country[0] }}
                                </p>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Configuración Regional"
                        description="Zona horaria y formato de fecha"
                        icon="md-language"
                    >
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="label">Zona Horaria</label>
                                <div class="relative">
                                    <select
                                        v-model="settings.timezone"
                                        class="input appearance-none"
                                        @change="hasChanges = true"
                                    >
                                        <option value="America/Bogota">
                                            Colombia (UTC-5)
                                        </option>
                                        <option value="America/Mexico_City">
                                            México (UTC-6)
                                        </option>
                                        <option value="America/Lima">
                                            Perú (UTC-5)
                                        </option>
                                        <option value="America/Santiago">
                                            Chile (UTC-3)
                                        </option>
                                        <option value="America/Buenos_Aires">
                                            Argentina (UTC-3)
                                        </option>
                                    </select>
                                    <div
                                        class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500"
                                    >
                                        <v-icon name="hi-chevron-down" />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="label">Moneda</label>
                                <div class="relative">
                                    <select
                                        v-model="settings.currency"
                                        class="input appearance-none"
                                        @change="hasChanges = true"
                                    >
                                        <option value="COP">
                                            Peso Colombiano (COP)
                                        </option>
                                        <option value="USD">Dólar (USD)</option>
                                        <option value="MXN">
                                            Peso Mexicano (MXN)
                                        </option>
                                        <option value="EUR">Euro (EUR)</option>
                                    </select>
                                    <div
                                        class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-500"
                                    >
                                        <v-icon name="hi-chevron-down" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Integración de Mapas (Google Maps)"
                        description="Clave de API usada para mostrar el Mapa de Clientes. Es una sola por empresa."
                        icon="ri-map-2-line"
                    >
                        <div class="space-y-4">
                            <div>
                                <label class="label">Clave de API de Google Maps</label>

                                <!-- Admin: write-only, nunca se precarga el valor, sin botón de revelar -->
                                <div v-if="isAdmin" class="relative">
                                    <input
                                        v-model="settings.google_maps_api_key"
                                        type="password"
                                        autocomplete="new-password"
                                        spellcheck="false"
                                        :placeholder="hasGoogleMapsKey ? '••••••••••••••••••••••••••••••••••••••• (escribe para reemplazar)' : 'AIzaSy...'"
                                        class="input font-mono"
                                        :class="{ 'border-red-500': errors.google_maps_api_key }"
                                        @input="hasChanges = true; apiKeyModified = true"
                                    />
                                </div>

                                <!-- No-admin: bloque solo visual, sin valor -->
                                <div v-else class="flex items-center gap-3 bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-4 py-3">
                                    <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                    </svg>
                                    <span class="font-mono text-gray-400 dark:text-gray-500 tracking-widest text-sm select-none">••••••••••••••••••••••••••••••••</span>
                                </div>

                                <!-- Badge "clave configurada" -->
                                <div v-if="isAdmin && hasGoogleMapsKey && !apiKeyModified"
                                    class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 px-2.5 py-1 rounded-lg">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    Clave configurada y cifrada
                                </div>

                                <p v-if="errors.google_maps_api_key" class="text-xs text-red-500 mt-1">
                                    {{ errors.google_maps_api_key[0] }}
                                </p>
                                <p v-if="!isAdmin" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                    🔒 Solo los administradores pueden editar esta clave.
                                </p>
                                <p v-else class="text-xs text-gray-500 dark:text-gray-400 mt-2 leading-relaxed">
                                    Obtén la clave en
                                    <a href="https://console.cloud.google.com/google/maps-apis/credentials"
                                        target="_blank" rel="noopener noreferrer"
                                        class="text-indigo-600 dark:text-indigo-400 underline">Google Cloud Console</a>
                                    (habilita «Maps JavaScript API»). Una vez guardada, el Mapa de Clientes se mostrará con Google Maps.
                                    Por seguridad, restringe la clave por dominio (HTTP referrer) en la consola de Google.
                                </p>
                            </div>
                        </div>
                    </SettingsSection>
                </div>

                <!-- Appearance Settings -->
                <div v-if="activeTab === 'appearance'" class="space-y-6">
                    <SettingsSection
                        title="Tema de la Aplicación"
                        description="Personaliza la apariencia visual"
                        icon="md-palette"
                    >
                        <div class="space-y-4">
                            <div>
                                <label class="label mb-4">Modo de Color</label>
                                <div
                                    class="grid grid-cols-1 md:grid-cols-3 gap-4"
                                >
                                    <button
                                        @click="setTheme('light')"
                                        :class="[
                                            'p-4 rounded-xl border-2 transition-all',
                                            currentTheme === 'light'
                                                ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                                : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300',
                                        ]"
                                    >
                                        <v-icon
                                            name="bi-sun"
                                            class="w-8 h-8 text-yellow-500 mx-auto mb-2"
                                        />
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Modo Claro
                                        </p>
                                    </button>
                                    <button
                                        @click="setTheme('dark')"
                                        :class="[
                                            'p-4 rounded-xl border-2 transition-all',
                                            currentTheme === 'dark'
                                                ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                                : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300',
                                        ]"
                                    >
                                        <v-icon
                                            name="bi-moon"
                                            class="w-8 h-8 text-indigo-500 mx-auto mb-2"
                                        />
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Modo Oscuro
                                        </p>
                                    </button>
                                    <button
                                        @click="setTheme('system')"
                                        :class="[
                                            'p-4 rounded-xl border-2 transition-all',
                                            currentTheme === 'system'
                                                ? 'border-indigo-600 bg-indigo-50 dark:bg-indigo-900/20'
                                                : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300',
                                        ]"
                                    >
                                        <v-icon
                                            name="md-computer"
                                            class="w-8 h-8 text-gray-500 mx-auto mb-2"
                                        />
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Sistema
                                        </p>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Personalización"
                        description="Ajusta la interfaz a tu gusto"
                        icon="md-brush"
                    >
                        <div class="space-y-4">
                            <div
                                class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="md-textfields"
                                        class="w-5 h-5 text-gray-600 dark:text-gray-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Densidad Compacta
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Reduce el espaciado de elementos
                                        </p>
                                    </div>
                                </div>
                                <label
                                    class="relative inline-flex items-center cursor-pointer"
                                >
                                    <input
                                        v-model="settings.compact_mode"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="hasChanges = true"
                                    />
                                    <div
                                        class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"
                                    ></div>
                                </label>
                            </div>

                            <div
                                class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="md-animation"
                                        class="w-5 h-5 text-gray-600 dark:text-gray-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Animaciones
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Habilitar transiciones y efectos
                                        </p>
                                    </div>
                                </div>
                                <label
                                    class="relative inline-flex items-center cursor-pointer"
                                >
                                    <input
                                        v-model="settings.animations_enabled"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="hasChanges = true"
                                    />
                                    <div
                                        class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"
                                    ></div>
                                </label>
                            </div>
                        </div>
                    </SettingsSection>
                </div>

                <!-- Notifications Settings -->
                <div v-if="activeTab === 'notifications'" class="space-y-6">
                    <SettingsSection
                        title="Notificaciones del Sistema"
                        description="Configura cómo y cuándo recibir alertas"
                        icon="md-notifications"
                    >
                        <div class="space-y-4">
                            <div
                                class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="md-email"
                                        class="w-5 h-5 text-blue-600 dark:text-blue-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Notificaciones por Email
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Recibir alertas importantes por
                                            correo
                                        </p>
                                    </div>
                                </div>
                                <label
                                    class="relative inline-flex items-center cursor-pointer"
                                >
                                    <input
                                        v-model="settings.email_notifications"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="hasChanges = true"
                                    />
                                    <div
                                        class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"
                                    ></div>
                                </label>
                            </div>

                            <div
                                class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="bi-bell"
                                        class="w-5 h-5 text-yellow-600 dark:text-yellow-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Notificaciones Push
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Alertas en tiempo real en el
                                            navegador
                                        </p>
                                    </div>
                                </div>
                                <label
                                    class="relative inline-flex items-center cursor-pointer"
                                >
                                    <input
                                        v-model="settings.push_notifications"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="hasChanges = true"
                                    />
                                    <div
                                        class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"
                                    ></div>
                                </label>
                            </div>

                            <div
                                class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="md-warning"
                                        class="w-5 h-5 text-red-600 dark:text-red-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Alertas de Facturas Vencidas
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Notificar cuando una factura está
                                            vencida
                                        </p>
                                    </div>
                                </div>
                                <label
                                    class="relative inline-flex items-center cursor-pointer"
                                >
                                    <input
                                        v-model="settings.overdue_alerts"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="hasChanges = true"
                                    />
                                    <div
                                        class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"
                                    ></div>
                                </label>
                            </div>

                            <div
                                class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="bi-router"
                                        class="w-5 h-5 text-green-600 dark:text-green-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Alertas de Routers Offline
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Notificar cuando un router se
                                            desconecta
                                        </p>
                                    </div>
                                </div>
                                <label
                                    class="relative inline-flex items-center cursor-pointer"
                                >
                                    <input
                                        v-model="settings.router_offline_alerts"
                                        type="checkbox"
                                        class="sr-only peer"
                                        @change="hasChanges = true"
                                    />
                                    <div
                                        class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-indigo-600"
                                    ></div>
                                </label>
                            </div>
                        </div>
                    </SettingsSection>
                </div>

                <!-- System Settings -->
                <div v-if="activeTab === 'system'" class="space-y-6">
                    <SettingsSection
                        title="Información del Sistema"
                        description="Detalles de la aplicación y base de datos"
                        icon="md-info"
                    >
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div
                                class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <p
                                    class="text-xs text-gray-500 dark:text-gray-400 mb-1"
                                >
                                    Versión de la Aplicación
                                </p>
                                <p
                                    class="text-lg font-bold text-gray-800 dark:text-gray-200"
                                >
                                    v1.0.0
                                </p>
                            </div>
                            <div
                                class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <p
                                    class="text-xs text-gray-500 dark:text-gray-400 mb-1"
                                >
                                    Base de Datos
                                </p>
                                <p
                                    class="text-lg font-bold text-gray-800 dark:text-gray-200"
                                >
                                    Supabase
                                </p>
                            </div>
                            <div
                                class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <p
                                    class="text-xs text-gray-500 dark:text-gray-400 mb-1"
                                >
                                    Última Actualización
                                </p>
                                <p
                                    class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                >
                                    {{ new Date().toLocaleDateString("es-CO") }}
                                </p>
                            </div>
                            <div
                                class="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg"
                            >
                                <p
                                    class="text-xs text-gray-500 dark:text-gray-400 mb-1"
                                >
                                    Estado del Sistema
                                </p>
                                <p
                                    class="text-sm font-medium text-green-600 dark:text-green-400 flex items-center gap-2"
                                >
                                    <span
                                        class="w-2 h-2 bg-green-500 rounded-full animate-pulse"
                                    ></span>
                                    Operativo
                                </p>
                            </div>
                        </div>
                    </SettingsSection>

                    <SettingsSection
                        title="Mantenimiento"
                        description="Herramientas de mantenimiento del sistema"
                        icon="md-build"
                    >
                        <div class="space-y-3">
                            <button
                                @click="clearCache"
                                class="w-full p-4 bg-gray-50 dark:bg-gray-700/50 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-all text-left flex items-center justify-between group"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="md-delete"
                                        class="w-5 h-5 text-orange-600 dark:text-orange-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Limpiar Caché
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Eliminar archivos temporales
                                        </p>
                                    </div>
                                </div>
                                <v-icon
                                    name="md-chevronright"
                                    class="w-5 h-5 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300"
                                />
                            </button>

                            <button
                                @click="exportData"
                                class="w-full p-4 bg-gray-50 dark:bg-gray-700/50 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-all text-left flex items-center justify-between group"
                            >
                                <div class="flex items-center gap-3">
                                    <v-icon
                                        name="md-download"
                                        class="w-5 h-5 text-blue-600 dark:text-blue-400"
                                    />
                                    <div>
                                        <p
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200"
                                        >
                                            Exportar Datos
                                        </p>
                                        <p
                                            class="text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            Descargar backup de la base de datos
                                        </p>
                                    </div>
                                </div>
                                <v-icon
                                    name="md-chevronright"
                                    class="w-5 h-5 text-gray-400 group-hover:text-gray-600 dark:group-hover:text-gray-300"
                                />
                            </button>
                        </div>
                    </SettingsSection>
                </div>

                <!-- Import Data Settings -->
                <div v-if="activeTab === 'import'" class="space-y-6">
                    <ImportSection />
                    <CustomersUpdateSection />
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { ref, onMounted, computed } from "vue";
import SettingsSection from "@/components/SettingsSection.vue";
import NotificationToast from "@/components/NotificationToast.vue";
import ImportSection from "@/components/import/ImportSection.vue";
import CustomersUpdateSection from "@/components/import/CustomersUpdateSection.vue";
import { apiClient } from "@/services/api";
import { useAuthStore } from "@/stores/auth";

const authStore = useAuthStore()

// State
const activeTab = ref("general");
const hasChanges = ref(false);
const currentTheme = ref("system");
const userData = ref(null);
const isAdmin = computed(() => authStore.isAdmin);
const loading          = ref(false);
const apiKeyModified   = ref(false);  // solo enviar la clave si el admin la reemplazó
const hasGoogleMapsKey = ref(false);  // indica si ya hay una clave configurada (sin revelarla)
const toast = ref(null);
const errors = ref({});

const tabs = [
    { id: "general", label: "General", icon: "ri-settings-4-line" },
    { id: "appearance", label: "Apariencia", icon: "md-palette" },
    { id: "notifications", label: "Notificaciones", icon: "md-notifications" },
    { id: "import", label: "Importar Datos", icon: "md-cloudupload" },
    { id: "system", label: "Sistema", icon: "md-computer" },
];

const settings = ref({
    // General (from tenant)
    company_name: "",
    domain: "",
    contact_email: "",
    phone: "",
    address: "",
    timezone: "America/Bogota",
    currency: "COP",

    // Legal (Colombian Company Info)
    legal_name: "",
    trade_name: "",
    nit: "",
    nit_verification_digit: "",
    tax_regime: "",
    economic_activity: "",

    // Billing
    billing_email: "",
    billing_phone: "",
    billing_address: "",
    city: "",
    department: "",
    country: "CO",

    // Integrations (from tenant)
    google_maps_api_key: "",

    // Appearance (localStorage only)
    theme: "system",
    compact_mode: false,
    animations_enabled: true,

    // Notifications (localStorage only)
    email_notifications: true,
    push_notifications: true,
    overdue_alerts: true,
    router_offline_alerts: true,
});

// Methods
const setTheme = (theme) => {
    currentTheme.value = theme;
    hasChanges.value = true;

    // Apply theme immediately
    if (theme === "dark") {
        document.documentElement.classList.add("dark");
    } else if (theme === "light") {
        document.documentElement.classList.remove("dark");
    } else {
        // System preference
        if (window.matchMedia("(prefers-color-scheme: dark)").matches) {
            document.documentElement.classList.add("dark");
        } else {
            document.documentElement.classList.remove("dark");
        }
    }

    localStorage.setItem("theme", theme);
};

const saveAllSettings = async () => {
    loading.value = true;

    try {
        // Save tenant info to database if user is admin
        if (isAdmin.value && userData.value?.tenant_id) {
            errors.value = {};

            const payload = {
                name: settings.value.company_name,
                domain: settings.value.domain,
                email_tenant: settings.value.contact_email,
                tel_tenant: settings.value.phone,
                address_tenant: settings.value.address,
                timezone: settings.value.timezone,
                currency: settings.value.currency,

                // New company fields
                legal_name: settings.value.legal_name,
                trade_name: settings.value.trade_name,
                nit: settings.value.nit,
                nit_verification_digit: settings.value.nit_verification_digit,
                tax_regime: settings.value.tax_regime,
                economic_activity: settings.value.economic_activity,
                billing_email: settings.value.billing_email,
                billing_phone: settings.value.billing_phone,
                billing_address: settings.value.billing_address,
                city: settings.value.city,
                department: settings.value.department,
                country: settings.value.country,
                // Only send API key if the admin explicitly changed it, preventing
                // a masked/stale value from overwriting the real key in the database.
                ...(apiKeyModified.value ? { google_maps_api_key: settings.value.google_maps_api_key } : {}),
            };

            // Use the new /tenant/config endpoint if preferred, or stick to /tenants/id
            const tenantResponse = await apiClient.put(
                `/tenants/${userData.value.tenant_id}`,
                { ...payload, user_id: userData.value.id },
            );

            if (!tenantResponse.data.success) {
                if (tenantResponse.status === 422) {
                    errors.value = tenantResponse.data.errors || {};
                    throw new Error("Datos de validación inválidos");
                }
                throw new Error(
                    tenantResponse.data.message || "Error al guardar tenant",
                );
            }
        }

        // Save only UI preferences to localStorage
        const uiPrefs = {
            compact_mode: settings.value.compact_mode,
            animations_enabled: settings.value.animations_enabled,
            email_notifications: settings.value.email_notifications,
            push_notifications: settings.value.push_notifications,
            overdue_alerts: settings.value.overdue_alerts,
            router_offline_alerts: settings.value.router_offline_alerts,
        };
        localStorage.setItem("uiPreferences", JSON.stringify(uiPrefs));
        localStorage.setItem("theme", currentTheme.value);

        // Dispatch event for global updates
        window.dispatchEvent(
            new CustomEvent("ui-preferences-updated", { detail: uiPrefs }),
        );

        hasChanges.value = false;

        // Show success notification
        toast.value?.success(
            "Configuración guardada",
            "Todos los cambios se guardaron correctamente",
        );
    } catch (error) {
        console.error("Error saving settings:", error);

        // Show error notification
        toast.value?.error(
            "Ups, algo falló",
            "No se pudo guardar la configuración. Intenta de nuevo.",
        );
    } finally {
        loading.value = false;
    }
};

const clearCache = async () => {
    loading.value = true;

    // 1. Backend Clear (Best effort)
    try {
        await apiClient.post("/settings/cache/clear");
    } catch (error) {
        console.error("Backend cache clear failed:", error);
        // Continue with frontend clear anyway
    }

    try {
        // 2. Frontend Clear (Browser)

        // Define keys to preserve (Preferences only)
        const preservedKeys = ["theme", "uiPreferences"];

        // Clear LocalStorage
        Object.keys(localStorage).forEach((key) => {
            if (!preservedKeys.includes(key)) {
                localStorage.removeItem(key);
            }
        });

        // Clear SessionStorage
        Object.keys(sessionStorage).forEach((key) => {
            if (!preservedKeys.includes(key)) {
                sessionStorage.removeItem(key);
            }
        });

        // Clear Cache API (Service Workers / PWA assets)
        if ("caches" in window) {
            try {
                const cacheNames = await caches.keys();
                await Promise.all(
                    cacheNames.map((name) => caches.delete(name)),
                );
            } catch (e) {
                console.error("Cache API clear failed:", e);
            }
        }

        toast.value?.success(
            "Caché limpiado",
            "La aplicación se recargará para aplicar los cambios...",
        );

        // 3. Force Reload to fetch fresh assets
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    } catch (error) {
        console.error("Error clearing frontend cache:", error);
        toast.value?.error(
            "Error",
            "Ocurrió un error al limpiar el caché del navegador",
        );
    } finally {
        loading.value = false;
    }
};

const exportData = () => {
    toast.value?.info(
        "Próximamente",
        "La función de exportación estará disponible pronto",
    );
};

// Load tenant data from API
const loadTenantData = async () => {
    try {
        if (!userData.value?.tenant_id) {
            console.warn("No tenant_id found in userData");
            return;
        }

        const response = await apiClient.get(
            `/tenants/${userData.value.tenant_id}`,
        );

        if (response.data.success && response.data.data) {
            const tenant = response.data.data;
            settings.value.company_name = tenant.name || "";
            settings.value.domain = tenant.domain || "";
            settings.value.contact_email = tenant.email_tenant || "";
            settings.value.phone = tenant.tel_tenant || "";
            settings.value.address = tenant.address_tenant || "";
            settings.value.timezone = tenant.zone_tenant || "America/Bogota";
            settings.value.currency = tenant.currency_tenant || "COP";

            // New company fields
            settings.value.legal_name = tenant.legal_name || "";
            settings.value.trade_name = tenant.trade_name || "";
            settings.value.nit = tenant.nit || "";
            settings.value.nit_verification_digit =
                tenant.nit_verification_digit || "";
            settings.value.tax_regime = tenant.tax_regime || "";
            settings.value.economic_activity = tenant.economic_activity || "";
            settings.value.billing_email = tenant.billing_email || "";
            settings.value.billing_phone = tenant.billing_phone || "";
            settings.value.billing_address = tenant.billing_address || "";
            settings.value.city = tenant.city || "";
            settings.value.department = tenant.department || "";
            settings.value.country = tenant.country || "CO";
            // Never pre-fill the API key — it's write-only from the frontend.
            // Only track whether one is already configured.
            settings.value.google_maps_api_key = "";
            hasGoogleMapsKey.value = !!tenant.has_google_maps_key;
            apiKeyModified.value   = false;
        }
    } catch (error) {
        console.error("Error loading tenant data:", error);
    }
};

// Lifecycle
onMounted(async () => {
    // Load user data from localStorage
    const localUserData =
        localStorage.getItem("userData") || sessionStorage.getItem("userData");
    if (localUserData) {
        try {
            userData.value = JSON.parse(localUserData);
        } catch (e) {
            console.error("Error parsing user data:", e);
        }
    }

    // Load tenant data from API
    await loadTenantData();

    // Load ONLY UI preferences from localStorage (not tenant data)
    const savedUIPrefs = localStorage.getItem("uiPreferences");
    if (savedUIPrefs) {
        try {
            const prefs = JSON.parse(savedUIPrefs);
            settings.value.compact_mode = prefs.compact_mode ?? false;
            settings.value.animations_enabled =
                prefs.animations_enabled ?? true;
            settings.value.email_notifications =
                prefs.email_notifications ?? true;
            settings.value.push_notifications =
                prefs.push_notifications ?? true;
            settings.value.overdue_alerts = prefs.overdue_alerts ?? true;
            settings.value.router_offline_alerts =
                prefs.router_offline_alerts ?? true;
        } catch (e) {
            console.error("Error loading UI preferences:", e);
        }
    }

    // Load theme preference
    const savedTheme = localStorage.getItem("theme") || "system";
    currentTheme.value = savedTheme;
});
</script>

<style scoped>
.label {
    @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2;
}
.input {
    @apply w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl
         bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100
         focus:ring-2 focus:ring-indigo-500 focus:border-transparent
         disabled:opacity-50 disabled:cursor-not-allowed transition-all
         placeholder:text-gray-400 dark:placeholder:text-gray-500;
}
</style>
