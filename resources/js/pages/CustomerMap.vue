<template>
    <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
        <!-- Header -->
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100">
                    Mapa de Clientes
                </h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1">
                    Visualización geográfica, zonas de cobertura y trazabilidad
                    de red
                </p>
            </div>
            <button
                @click="router.push('/customers')"
                class="bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white px-6 py-3 rounded-lg flex items-center gap-2 transition"
            >
                <icon-mdi-arrow-left class="w-5 h-5" />
                Volver a Clientes
            </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-12">
            <div
                class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"
            ></div>
            <p class="text-gray-500 dark:text-gray-400 mt-4">
                Cargando mapa...
            </p>
        </div>

        <!-- Google Maps API key not configured -->
        <div
            v-else-if="apiKeyMissing"
            class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-100 dark:border-gray-700 p-10 text-center max-w-2xl mx-auto"
        >
            <div
                class="mx-auto w-16 h-16 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center mb-5"
            >
                <v-icon
                    name="ri-map-2-line"
                    class="w-8 h-8 text-blue-600 dark:text-blue-400"
                />
            </div>
            <h2
                class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-2"
            >
                Configura Google Maps para tu empresa
            </h2>
            <p class="text-gray-500 dark:text-gray-400 mb-6 leading-relaxed">
                Para usar el Mapa de Clientes debes ingresar la clave de API de
                Google Maps de tu empresa. Es una sola clave por empresa: una
                vez guardada, el mapa se mostrará automáticamente aquí.
            </p>
            <button
                v-if="isAdmin"
                @click="router.push('/settings')"
                class="inline-flex items-center gap-2 bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-700 hover:to-indigo-800 text-white px-6 py-3 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl"
            >
                <v-icon name="ri-settings-4-line" class="w-5 h-5" />
                Ir a Configuración
            </button>
            <p v-else class="text-sm text-amber-600 dark:text-amber-400">
                Solicita a un administrador que registre la clave de API de
                Google Maps en <strong>Configuración</strong>.
            </p>
        </div>

        <!-- Error -->
        <div
            v-else-if="error"
            class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-500 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg"
        >
            {{ error }}
        </div>

        <!-- Map Container -->
        <div v-else>
            <!-- Stats Bar -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div class="p-3 rounded-full bg-blue-50 dark:bg-blue-900">
                            <v-icon
                                name="ri-map-pin-line"
                                class="w-6 h-6 text-blue-600 dark:text-blue-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                En el Mapa
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ filteredCustomers.length }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-3 rounded-full bg-green-50 dark:bg-green-900"
                        >
                            <v-icon
                                name="ri-map-pin-user-line"
                                class="w-6 h-6 text-green-600 dark:text-green-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Ciudades
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ uniqueCities }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-3 rounded-full bg-purple-50 dark:bg-purple-900"
                        >
                            <v-icon
                                name="oi-package"
                                class="w-6 h-6 text-purple-600 dark:text-purple-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Departamentos
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ uniqueDepartments }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-3 rounded-full bg-amber-50 dark:bg-amber-900"
                        >
                            <v-icon
                                name="bi-broadcast-pin"
                                class="w-6 h-6 text-amber-600 dark:text-amber-400"
                            />
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Nodos / Sectoriales
                            </p>
                            <p
                                class="text-2xl font-bold text-gray-800 dark:text-gray-100"
                            >
                                {{ routers.length }} / {{ sectorials.length }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controls: filters + layers -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-100 dark:border-gray-700 p-4 mb-4 flex flex-col lg:flex-row lg:items-end gap-4"
            >
                <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1"
                            >Filtrar por nodo</label
                        >
                        <select
                            v-model="selectedRouterId"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100"
                        >
                            <option value="all">Todos los nodos</option>
                            <option
                                v-for="r in routers"
                                :key="r.id"
                                :value="r.id"
                            >
                                {{ r.name }}
                            </option>
                            <option value="none">Sin nodo asignado</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1"
                            >Estado del servicio</label
                        >
                        <select
                            v-model="selectedStatus"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100"
                        >
                            <option value="all">Todos</option>
                            <option value="activo">Activo</option>
                            <option value="suspendido">Suspendido</option>
                            <option value="cancelado">Cancelado</option>
                            <option value="gratis">Gratis / Cortesía</option>
                        </select>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="layer in layerToggles"
                        :key="layer.key"
                        type="button"
                        :title="layer.desc"
                        :aria-pressed="layers[layer.key]"
                        @click="layers[layer.key] = !layers[layer.key]"
                        :class="[
                            'inline-flex items-center gap-2 px-3.5 py-2 rounded-lg border-2 text-sm font-medium transition-all select-none',
                            layers[layer.key]
                                ? layer.activeClass + ' shadow-sm'
                                : 'border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/40 text-gray-500 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500',
                        ]"
                    >
                        <v-icon :name="layer.icon" class="w-4 h-4 flex-shrink-0" />
                        <span class="flex flex-col items-start leading-tight">
                            <span>{{ layer.label }}</span>
                            <span class="text-[10px] font-normal opacity-70">{{
                                layer.desc
                            }}</span>
                        </span>
                    </button>
                </div>
            </div>

            <!-- Panel de trazabilidad (elige sectorial → cliente) -->
            <div
                v-if="layers.traces"
                class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-100 dark:border-gray-700 p-4 mb-4"
            >
                <div class="flex items-start gap-2 mb-3">
                    <v-icon
                        name="bi-diagram-3"
                        class="w-5 h-5 text-emerald-600 dark:text-emerald-400 mt-0.5 flex-shrink-0"
                    />
                    <p
                        class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed"
                    >
                        Elige una <strong class="text-gray-700 dark:text-gray-200">sectorial</strong>.
                        <strong class="text-gray-700 dark:text-gray-200">Fibra (NAP):</strong>
                        pulsa «Dibujar ruta» y marca el trazado punto por punto haciendo
                        clic en el mapa; verás la distancia acumulada (solo visual, no se guarda).
                        <strong class="text-gray-700 dark:text-gray-200">Radioenlace:</strong>
                        el perfil de elevación está temporalmente deshabilitado.
                    </p>
                </div>

                <!-- Selección: sectorial (buscador) + coordenadas de destino -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <SearchableSelect
                        v-model="traceSectorialId"
                        :items="traceableSectorials"
                        item-key="id"
                        :item-label="sectorialOptionLabel"
                        item-icon="bi-broadcast-pin"
                        label="Sectorial / AP"
                        placeholder="— Selecciona una sectorial —"
                        search-placeholder="Buscar sectorial..."
                        clearable
                        clear-label="Ninguna"
                    />
                    <div v-if="traceIsFiber">
                        <label
                            class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1"
                            >Coordenadas destino (cliente nuevo) — opcional</label
                        >
                        <div class="flex gap-2">
                            <input
                                v-model="destCoordsInput"
                                @keyup.enter="applyDestCoords"
                                @blur="applyDestCoords"
                                type="text"
                                placeholder="4.458429 -74.636633"
                                class="flex-1 min-w-0 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100"
                            />
                            <button
                                type="button"
                                @click="applyDestCoords"
                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-indigo-600 hover:bg-indigo-700 text-white flex-shrink-0"
                            >
                                <v-icon name="ri-map-pin-line" class="w-4 h-4" />
                                Marcar
                            </button>
                        </div>
                        <p
                            v-if="destError"
                            class="text-[11px] text-rose-500 mt-1"
                        >
                            {{ destError }}
                        </p>
                        <p
                            v-else-if="destPoint"
                            class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-1 flex items-center gap-1.5"
                        >
                            Destino marcado: {{ destPoint.lat.toFixed(6) }},
                            {{ destPoint.lng.toFixed(6) }}
                            <button
                                type="button"
                                @click="clearDest"
                                class="underline hover:no-underline"
                            >
                                quitar
                            </button>
                        </p>
                        <p
                            v-else
                            class="text-[11px] text-gray-400 dark:text-gray-500 mt-1"
                        >
                            Pega «lat lng» para marcar dónde irá el cliente y
                            terminar la ruta ahí.
                        </p>
                    </div>
                </div>

                <!-- Acciones de fibra: dibujo manual + distancia -->
                <div v-if="traceIsFiber" class="mt-3">
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            @click="
                                fiberDrawing
                                    ? finishFiberDrawing()
                                    : startFiberDrawing()
                            "
                            :class="[
                                'inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition-all',
                                fiberDrawing
                                    ? 'bg-emerald-600 hover:bg-emerald-700 text-white'
                                    : 'bg-indigo-600 hover:bg-indigo-700 text-white',
                            ]"
                        >
                            <v-icon
                                :name="
                                    fiberDrawing
                                        ? 'ri-check-line'
                                        : 'ri-pencil-line'
                                "
                                class="w-4 h-4"
                            />
                            {{ fiberDrawing ? "Terminar" : "Dibujar ruta" }}
                        </button>
                        <button
                            type="button"
                            :disabled="!fiberPoints.length"
                            @click="undoFiberPoint"
                            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <v-icon name="ri-arrow-go-back-line" class="w-4 h-4" />
                            Deshacer
                        </button>
                        <button
                            type="button"
                            :disabled="!fiberPoints.length"
                            @click="resetFiberTrace"
                            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium bg-rose-100 hover:bg-rose-200 dark:bg-rose-900/40 dark:hover:bg-rose-900/60 text-rose-700 dark:text-rose-300 disabled:opacity-40 disabled:cursor-not-allowed"
                        >
                            <v-icon name="ri-restart-line" class="w-4 h-4" />
                            Reiniciar
                        </button>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-xs"
                    >
                        <span class="text-gray-500 dark:text-gray-400">
                            Distancia de la ruta:
                            <strong
                                class="text-emerald-700 dark:text-emerald-300 text-sm"
                                >{{ fiberDistanceLabel }}</strong
                            >
                        </span>
                        <span class="text-gray-500 dark:text-gray-400">
                            Puntos:
                            <strong class="text-gray-800 dark:text-gray-100">{{
                                fiberPoints.length
                            }}</strong>
                        </span>
                        <span
                            v-if="fiberDrawing"
                            class="text-emerald-600 dark:text-emerald-400"
                        >
                            Modo dibujo activo — haz clic en el mapa para añadir
                            puntos.
                        </span>
                    </div>
                </div>

                <!-- Radio: perfil de elevación (deshabilitado por ahora) -->
                <div
                    v-else-if="traceSectorial && !ELEVATION_ENABLED"
                    class="mt-3 flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/40 border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2.5"
                >
                    <v-icon
                        name="bi-graph-up"
                        class="w-4 h-4 flex-shrink-0 text-gray-400"
                    />
                    El perfil de elevación para radioenlaces está temporalmente
                    deshabilitado.
                </div>

                <!-- Radio: controles de perfil de elevación (al reactivar) -->
                <div
                    v-else-if="traceSectorial"
                    class="flex flex-wrap items-end gap-3 mt-3"
                >
                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1"
                            >Altura AP (m)</label
                        >
                        <input
                            v-model.number="apHeight"
                            type="number"
                            min="0"
                            step="1"
                            class="w-24 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1"
                            >Altura cliente (m)</label
                        >
                        <input
                            v-model.number="cpeHeight"
                            type="number"
                            min="0"
                            step="1"
                            class="w-28 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-gray-100"
                        />
                    </div>
                    <button
                        type="button"
                        :disabled="!destPoint || elevation.loading"
                        @click="computeElevation"
                        class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium bg-indigo-600 hover:bg-indigo-700 text-white disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        <v-icon
                            :name="
                                elevation.loading
                                    ? 'ri-loader-4-line'
                                    : 'bi-graph-up'
                            "
                            :class="[
                                'w-4 h-4',
                                elevation.loading ? 'animate-spin' : '',
                            ]"
                        />
                        {{
                            elevation.loading
                                ? "Calculando…"
                                : "Perfil de elevación"
                        }}
                    </button>
                </div>

                <!-- Perfil de elevación (radioenlace) -->
                <div
                    v-if="ELEVATION_ENABLED && !traceIsFiber && traceSectorial && (elevation.error || elevationChart)"
                    class="mt-4 border-t border-gray-100 dark:border-gray-700 pt-4"
                >
                    <p
                        v-if="elevation.error"
                        class="text-sm text-rose-600 dark:text-rose-400"
                    >
                        {{ elevation.error }}
                    </p>

                    <div v-else-if="elevationChart">
                        <!-- Resumen del enlace -->
                        <div
                            class="flex flex-wrap items-center gap-x-5 gap-y-1 mb-3 text-xs"
                        >
                            <span class="text-gray-500 dark:text-gray-400">
                                <strong class="text-gray-800 dark:text-gray-100">{{
                                    traceSectorial.name
                                }}</strong>
                                →
                                <strong class="text-gray-800 dark:text-gray-100"
                                    >Destino</strong
                                >
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                Distancia:
                                <strong class="text-gray-800 dark:text-gray-100">{{
                                    elevationDistanceLabel
                                }}</strong>
                            </span>
                            <span
                                :class="[
                                    'inline-flex items-center gap-1 font-semibold px-2 py-0.5 rounded-full',
                                    elevationChart.clear
                                        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                                        : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300',
                                ]"
                            >
                                <v-icon
                                    :name="
                                        elevationChart.clear
                                            ? 'ri-checkbox-circle-line'
                                            : 'ri-error-warning-line'
                                    "
                                    class="w-3.5 h-3.5"
                                />
                                {{
                                    elevationChart.clear
                                        ? "Línea de vista despejada"
                                        : "Posible obstrucción"
                                }}
                                ({{ elevationChart.worst }} m)
                            </span>
                        </div>

                        <!-- Gráfico SVG: terreno + línea de vista -->
                        <div
                            class="relative rounded-lg overflow-hidden border border-gray-100 dark:border-gray-700 bg-gradient-to-b from-sky-50 to-white dark:from-sky-950/40 dark:to-gray-800"
                        >
                            <svg
                                :viewBox="`0 0 ${elevationChart.W} ${elevationChart.H}`"
                                preserveAspectRatio="none"
                                class="w-full h-[200px] block"
                            >
                                <path
                                    :d="elevationChart.terrain"
                                    fill="#86efac"
                                    fill-opacity="0.55"
                                    stroke="#16a34a"
                                    stroke-width="1.5"
                                />
                                <path
                                    :d="elevationChart.los"
                                    fill="none"
                                    :stroke="
                                        elevationChart.clear
                                            ? '#6366F1'
                                            : '#ef4444'
                                    "
                                    stroke-width="2"
                                    stroke-dasharray="6 4"
                                />
                                <circle
                                    :cx="elevationChart.ap.x"
                                    :cy="elevationChart.ap.y"
                                    r="5"
                                    fill="#6366F1"
                                    stroke="#fff"
                                    stroke-width="2"
                                />
                                <circle
                                    :cx="elevationChart.cpe.x"
                                    :cy="elevationChart.cpe.y"
                                    r="5"
                                    fill="#6366F1"
                                    stroke="#fff"
                                    stroke-width="2"
                                />
                            </svg>
                            <!-- Etiquetas de extremos -->
                            <span
                                class="absolute top-1 left-2 text-[11px] font-semibold text-indigo-700 dark:text-indigo-300"
                                >AP · {{ elevationChart.apGround }} m</span
                            >
                            <span
                                class="absolute top-1 right-2 text-[11px] font-semibold text-indigo-700 dark:text-indigo-300"
                                >Cliente · {{ elevationChart.cpeGround }} m</span
                            >
                            <span
                                class="absolute bottom-1 left-2 text-[10px] text-gray-500 dark:text-gray-400"
                                >Elev. {{ elevationChart.minEl }}–{{
                                    elevationChart.maxEl
                                }}
                                m</span
                            >
                        </div>
                        <p
                            class="text-[11px] text-gray-400 dark:text-gray-500 mt-1"
                        >
                            Perfil del terreno entre AP y cliente (Google
                            Elevation API). La línea punteada es la línea de
                            vista según las alturas de antena indicadas.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Map -->
            <div
                class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden border border-gray-100 dark:border-gray-700"
            >
                <div ref="mapEl" class="w-full h-[600px]"></div>
            </div>

            <!-- Legend -->
            <div
                class="mt-4 bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 border border-gray-100 dark:border-gray-700"
            >
                <h3
                    class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3"
                >
                    Leyenda
                </h3>
                <div
                    class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3"
                >
                    <div
                        v-for="(color, dept) in departmentColors"
                        :key="dept"
                        class="flex items-center gap-2"
                    >
                        <div
                            class="w-4 h-4 rounded-full"
                            :style="{ backgroundColor: color }"
                        ></div>
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{
                            dept || "Sin departamento"
                        }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div
                            class="w-4 h-4 rotate-45 bg-[#2563EB] border border-white"
                        ></div>
                        <span class="text-xs text-gray-600 dark:text-gray-400"
                            >Nodo / Router</span
                        >
                    </div>
                    <div
                        v-for="t in presentElementTypes"
                        :key="t"
                        class="flex items-center gap-2"
                    >
                        <img
                            :src="sectorialIconUrl(t)"
                            class="w-5 h-5 flex-shrink-0"
                            alt=""
                        />
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{
                            elementLabel(t)
                        }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import {
    ref,
    computed,
    onMounted,
    onBeforeUnmount,
    nextTick,
    watch,
} from "vue";
import { useRouter } from "vue-router";
import api from "../services/api";
import tenantApi from "../services/api/tenant";
import { useAuthStore } from "../stores/auth";
import { effectiveCoverageRadius, antennaLabel } from "../constants/antennas";
import { elementLabel, isFiber } from "../constants/networkElements";
import SearchableSelect from "../components/SearchableSelect.vue";

// El perfil de elevación de radioenlaces queda deshabilitado por ahora; cuando
// se reactive basta con poner esto en true (el resto de la lógica ya existe).
const ELEVATION_ENABLED = false;

const router = useRouter();
const authStore = useAuthStore();

const allCustomers = ref([]);
const routers = ref([]);
const sectorials = ref([]);
const loading = ref(true);
const error = ref("");
const apiKeyMissing = ref(false);
const mapEl = ref(null);

// Filters
const selectedRouterId = ref("all"); // 'all' | 'none' | router id
const selectedStatus = ref("all"); // 'all' | service_status value

// Layer toggles
const layers = ref({
    customers: true,
    coverage: true,
    heatmap: false,
    traces: false,
    nodes: true,
});
// Cada capa muestra algo distinto. El subtítulo deja claro qué hace cada una
// para que «Zonas de cobertura» (círculos por antena) y «Mapa de calor»
// (densidad de clientes) no se confundan entre sí.
const layerToggles = [
    {
        key: "customers",
        label: "Clientes",
        desc: "Pines de clientes",
        icon: "ri-map-pin-line",
        activeClass:
            "border-blue-500 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300",
    },
    {
        key: "coverage",
        label: "Zonas de cobertura",
        desc: "Radio por antena",
        icon: "bi-broadcast-pin",
        activeClass:
            "border-amber-500 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300",
    },
    {
        key: "heatmap",
        label: "Mapa de calor",
        desc: "Densidad de clientes",
        icon: "bi-activity",
        activeClass:
            "border-rose-500 bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300",
    },
    {
        key: "traces",
        label: "Trazabilidad",
        desc: "Radio ↔ sectorial · Fibra manual",
        icon: "bi-diagram-3",
        activeClass:
            "border-emerald-500 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300",
    },
    {
        key: "nodes",
        label: "Nodos",
        desc: "Antenas / routers",
        icon: "bi-router",
        activeClass:
            "border-indigo-500 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300",
    },
];

// Plain (non-reactive) Google Maps objects
let map = null;
let infoWindow = null;
let clusterer = null;
let customerMarkers = [];
let heatmapOverlay = null;
let coverageCircles = [];
let traceLines = [];
let nodeMarkers = [];
let fiberLine = null;
let fiberMarkers = [];
let mapClickListener = null;
let mapReady = false;

const isAdmin = computed(() => authStore.isAdmin);

const departmentColors = ref({
    Ventas: "#3B82F6",
    Marketing: "#10B981",
    Técnico: "#8B5CF6",
    Soporte: "#F59E0B",
    Administración: "#EF4444",
    "Sin departamento": "#6B7280",
});

const filteredCustomers = computed(() =>
    allCustomers.value.filter((c) => {
        const lat = Number(c.latitude);
        const lng = Number(c.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return false;

        if (selectedRouterId.value === "none") {
            if (c.router_id) return false;
        } else if (selectedRouterId.value !== "all") {
            if (String(c.router_id) !== String(selectedRouterId.value))
                return false;
        }

        if (
            selectedStatus.value !== "all" &&
            (c.service_status || "activo") !== selectedStatus.value
        )
            return false;

        return true;
    })
);

const uniqueCities = computed(
    () =>
        new Set(filteredCustomers.value.map((c) => c.city).filter(Boolean)).size
);

const uniqueDepartments = computed(
    () =>
        new Set(
            filteredCustomers.value.map((c) => c.department).filter(Boolean)
        ).size
);

const getMarkerColor = (department) =>
    departmentColors.value[department] ||
    departmentColors.value["Sin departamento"];

// Tipos de elemento de red presentes en el mapa, en orden de catálogo, para
// mostrar en la leyenda solo los iconos que realmente aparecen.
const ELEMENT_ORDER = [
    "sectorial",
    "switch",
    "nodo",
    "olt",
    "splitter",
    "nap",
    "mufa",
];
const presentElementTypes = computed(() => {
    const set = new Set(
        sectorials.value.map((s) => s.element_type || "sectorial")
    );
    return ELEMENT_ORDER.filter((t) => set.has(t));
});

const createPinUrl = (color) => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="42" viewBox="0 0 32 42"><path d="M16 0C7.163 0 0 7.163 0 16c0 12 16 26 16 26s16-14 16-26C32 7.163 24.837 0 16 0z" fill="${color}" stroke="#fff" stroke-width="2"/><circle cx="16" cy="16" r="6" fill="#fff"/></svg>`;
    return "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);
};

const svgUrl = (svg) =>
    "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);

const routerIconUrl = () => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 28 28"><rect x="4" y="4" width="20" height="20" rx="3" transform="rotate(45 14 14)" fill="#2563EB" stroke="#fff" stroke-width="2"/></svg>`;
    return svgUrl(svg);
};

// Cada tipo de elemento de red tiene su propio color y glifo (dibujo blanco
// sobre la pastilla de color) para que en el mapa se distingan de un vistazo:
// una caja NAP, una mufa y un switch ya no comparten el mismo triángulo.
// Las coordenadas de los glifos están en un lienzo de 32×32.
const ELEMENT_STYLES = {
    sectorial: {
        color: "#2563EB",
        glyph: `<circle cx="16" cy="21" r="2.4" fill="#fff"/><path d="M11.2 16.2a7 7 0 0 1 9.6 0" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/><path d="M8.6 13a11 11 0 0 1 14.8 0" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/>`,
    },
    switch: {
        color: "#7C3AED",
        glyph: `<rect x="8.5" y="12" width="15" height="8" rx="2" fill="none" stroke="#fff" stroke-width="2"/><path d="M12 20v2.4M16 20v2.4M20 20v2.4M12 9.6V12M16 9.6V12M20 9.6V12" stroke="#fff" stroke-width="2" stroke-linecap="round"/>`,
    },
    nodo: {
        color: "#059669",
        glyph: `<circle cx="16" cy="8.5" r="1.8" fill="#fff"/><path d="M12.4 23 16 10l3.6 13" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round"/><path d="M13.7 18.3h4.6M14.4 14.4h3.2" stroke="#fff" stroke-width="2" stroke-linecap="round"/>`,
    },
    olt: {
        color: "#E11D48",
        glyph: `<rect x="9" y="10" width="14" height="5.4" rx="1.6" fill="none" stroke="#fff" stroke-width="1.8"/><rect x="9" y="17" width="14" height="5.4" rx="1.6" fill="none" stroke="#fff" stroke-width="1.8"/><circle cx="12" cy="12.7" r="1.1" fill="#fff"/><circle cx="12" cy="19.7" r="1.1" fill="#fff"/>`,
    },
    splitter: {
        color: "#D97706",
        glyph: `<path d="M9 16h4.5M13.5 16 21 11M13.5 16h7.5M13.5 16 21 21" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="21" cy="11" r="1.5" fill="#fff"/><circle cx="21" cy="16" r="1.5" fill="#fff"/><circle cx="21" cy="21" r="1.5" fill="#fff"/>`,
    },
    nap: {
        color: "#0891B2",
        glyph: `<rect x="9.5" y="11" width="13" height="11" rx="1.6" fill="none" stroke="#fff" stroke-width="2"/><path d="M9.5 14.8h13" stroke="#fff" stroke-width="2"/><path d="M14.4 11v3.8M17.6 11v3.8" stroke="#fff" stroke-width="1.6"/>`,
    },
    mufa: {
        color: "#475569",
        glyph: `<rect x="9.5" y="12" width="13" height="9" rx="4.5" fill="none" stroke="#fff" stroke-width="2"/><path d="M16 12v9" stroke="#fff" stroke-width="1.6"/><path d="M13 9.6v2.4M19 9.6v2.4" stroke="#fff" stroke-width="2" stroke-linecap="round"/>`,
    },
};

const elementStyle = (type) =>
    ELEMENT_STYLES[type] || ELEMENT_STYLES.sectorial;

// Pastilla de color con el glifo blanco del tipo, usada como marcador del mapa.
const sectorialIconUrl = (type) => {
    const { color, glyph } = elementStyle(type);
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 32 32"><rect x="3" y="3" width="26" height="26" rx="8" fill="${color}" stroke="#fff" stroke-width="2.5"/>${glyph}</svg>`;
    return svgUrl(svg);
};

// Mismo glifo, en pequeño y sobre fondo transparente, para incrustarlo dentro
// de la cabecera del popup (sobre una caja del color del tipo).
const elementGlyphSvg = (type, size = 22) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">${
        elementStyle(type).glyph
    }</svg>`;

const escapeHtml = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (ch) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            }[ch])
    );

// ── Popups (tarjetas de InfoWindow) ─────────────────────────────────────────
// Fila etiqueta/valor: se omite por completo si el valor está vacío, para no
// llenar la tarjeta de "N/A".
const popupRow = (label, value) => {
    const v = value == null ? "" : String(value).trim();
    if (!v || v.toUpperCase() === "N/A") return "";
    return `<div style="display:flex;justify-content:space-between;gap:14px;padding:6px 0;border-top:1px solid #F3F4F6;font-size:12.5px;line-height:1.35;">
        <span style="color:#9CA3AF;font-weight:500;white-space:nowrap;">${escapeHtml(
            label
        )}</span>
        <span style="color:#1F2937;font-weight:600;text-align:right;">${escapeHtml(
            v
        )}</span>
    </div>`;
};

// Colores de la pastilla de estado del servicio del cliente.
const STATUS_STYLES = {
    activo: { bg: "#ECFDF5", fg: "#047857", label: "Activo" },
    suspendido: { bg: "#FFF7ED", fg: "#C2410C", label: "Suspendido" },
    cancelado: { bg: "#FEF2F2", fg: "#B91C1C", label: "Cancelado" },
    gratis: { bg: "#EEF2FF", fg: "#4338CA", label: "Gratis / Cortesía" },
};
const statusStyle = (status) =>
    STATUS_STYLES[status] || {
        bg: "#F3F4F6",
        fg: "#374151",
        label: status || "—",
    };

const customerPopup = (c) => {
    const st = statusStyle(c.service_status || "activo");
    const initial = escapeHtml(
        (c.name || c.last_name || "?").trim().charAt(0).toUpperCase()
    );
    // Enlace directo a Google Maps con las coordenadas del cliente (abre el pin
    // en una pestaña nueva; útil para que el técnico navegue hasta el domicilio).
    const lat = Number(c.latitude);
    const lng = Number(c.longitude);
    const mapsUrl =
        Number.isFinite(lat) && Number.isFinite(lng)
            ? `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`
            : "";
    return `
    <div style="font-family:inherit;min-width:240px;max-width:290px;">
        <div style="display:flex;align-items:flex-start;gap:10px;padding-bottom:10px;">
            <div style="width:42px;height:42px;border-radius:9999px;background:#DBEAFE;color:#1D4ED8;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;flex-shrink:0;">${initial}</div>
            <div style="min-width:0;flex:1;">
                <h3 style="font-weight:700;color:#111827;margin:0;font-size:15px;line-height:1.25;">${escapeHtml(
                    c.name
                )} ${escapeHtml(c.last_name)}</h3>
                ${
                    c.email
                        ? `<p style="font-size:12px;color:#6B7280;margin:2px 0 0;word-break:break-all;">${escapeHtml(
                              c.email
                          )}</p>`
                        : ""
                }
                <span style="display:inline-block;margin-top:6px;font-size:11px;font-weight:600;padding:2px 9px;border-radius:9999px;background:${
                    st.bg
                };color:${st.fg};">${escapeHtml(st.label)}</span>
            </div>
        </div>
        <div>
            ${popupRow("Departamento", c.department)}
            ${popupRow("Ciudad", c.city)}
            ${popupRow("Dirección", c.address)}
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;">
            <a href="/customers/${encodeURIComponent(c.user_id)}/edit"
               style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;background:#2563EB;color:#fff;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">
                Editar
            </a>
            ${
                mapsUrl
                    ? `<a href="${mapsUrl}" target="_blank" rel="noopener noreferrer"
                   style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;background:#fff;color:#047857;border:1px solid #A7F3D0;padding:9px 12px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z" stroke="#047857" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="10" r="2.4" stroke="#047857" stroke-width="2"/></svg>
                    Google Maps
                </a>`
                    : ""
            }
        </div>
    </div>`;
};

const nodePopup = (node, kind) => {
    // Router: tarjeta sencilla (no es un elemento de red con tipo/cobertura).
    if (kind === "router") {
        return `
        <div style="font-family:inherit;min-width:210px;max-width:280px;">
            <div style="display:flex;align-items:center;gap:10px;padding-bottom:8px;">
                <div style="width:40px;height:40px;border-radius:10px;background:#2563EB;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 1px 3px rgba(0,0,0,.18);">
                    <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="13" width="18" height="7" rx="2" fill="none" stroke="#fff" stroke-width="2"/><circle cx="7" cy="16.5" r="1.1" fill="#fff"/><path d="M12 13V8M12 8a4 4 0 0 1 4 4M12 8a4 4 0 0 0-4 4" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
                </div>
                <div style="min-width:0;">
                    <h3 style="font-weight:700;color:#111827;margin:0;font-size:15px;line-height:1.2;">${escapeHtml(
                        node.name
                    )}</h3>
                    <span style="display:inline-block;margin-top:4px;font-size:11px;font-weight:600;padding:2px 9px;border-radius:9999px;background:#EFF6FF;color:#1D4ED8;">Nodo / Router</span>
                </div>
            </div>
            ${popupRow("IP", node.ip)}
        </div>`;
    }

    // Sectorial / elemento de red: cabecera con su color y glifo, filas según tipo.
    const type = node.element_type || "sectorial";
    const { color } = elementStyle(type);
    const fiber = isFiber(type);
    return `
    <div style="font-family:inherit;min-width:220px;max-width:285px;">
        <div style="display:flex;align-items:center;gap:10px;padding-bottom:8px;">
            <div style="width:40px;height:40px;border-radius:10px;background:${color};display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 1px 3px rgba(0,0,0,.18);">
                ${elementGlyphSvg(type, 22)}
            </div>
            <div style="min-width:0;">
                <h3 style="font-weight:700;color:#111827;margin:0;font-size:15px;line-height:1.2;">${escapeHtml(
                    node.name
                )}</h3>
                <span style="display:inline-block;margin-top:4px;font-size:11px;font-weight:600;padding:2px 9px;border-radius:9999px;background:${color}1A;color:${color};">${escapeHtml(
        elementLabel(type)
    )}</span>
            </div>
        </div>
        <div>
            ${popupRow("Subtipo", node.type)}
            ${popupRow("IP", node.ip)}
            ${fiber ? "" : popupRow("Antena", antennaLabel(node.antenna_type))}
            ${fiber ? "" : popupRow("Frecuencia", node.frequency)}
            ${popupRow("Torre / Nodo", node.node_tower)}
            ${popupRow("SSID", node.ssid)}
            ${popupRow("Cobertura", effectiveCoverageRadius(node) + " m")}
        </div>
    </div>`;
};

// ── Google Maps + MarkerClusterer loaders ──────────────────────────────────
let googleMapsPromise = null;
const loadGoogleMaps = (apiKey) => {
    if (window.google && window.google.maps) {
        return Promise.resolve(window.google);
    }
    if (googleMapsPromise) return googleMapsPromise;

    googleMapsPromise = new Promise((resolve, reject) => {
        const cb = "__ispwatchInitGmaps__";
        // If neither onload nor the callback fires (e.g. the request is blocked
        // by an ad-blocker / browser shield), fail loudly instead of hanging.
        const timeout = setTimeout(() => {
            googleMapsPromise = null;
            reject(
                new Error(
                    "El script de Google Maps no respondió (¿bloqueado por el navegador, un ad-blocker/Brave Shields, o sin conexión?)"
                )
            );
        }, 15000);

        window[cb] = () => {
            clearTimeout(timeout);
            resolve(window.google);
            delete window[cb];
        };
        const script = document.createElement("script");
        script.src =
            `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(
                apiKey
            )}` +
            `&callback=${cb}&loading=async&v=weekly`;
        script.async = true;
        script.defer = true;
        script.onerror = () => {
            clearTimeout(timeout);
            googleMapsPromise = null;
            reject(
                new Error(
                    "No se pudo descargar el script de Google Maps (red bloqueada o sin conexión)"
                )
            );
        };
        document.head.appendChild(script);
    });
    return googleMapsPromise;
};

let clustererPromise = null;
const loadMarkerClusterer = () => {
    if (window.markerClusterer) return Promise.resolve(window.markerClusterer);
    if (clustererPromise) return clustererPromise;

    clustererPromise = new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src =
            "https://unpkg.com/@googlemaps/markerclusterer@2.5.3/dist/index.min.js";
        script.async = true;
        script.onload = () => resolve(window.markerClusterer);
        script.onerror = () => {
            clustererPromise = null;
            reject(new Error("CLUSTERER_LOAD_ERROR"));
        };
        document.head.appendChild(script);
    });
    return clustererPromise;
};

// ── Layer rendering ────────────────────────────────────────────────────────
const clearLayers = () => {
    if (clusterer) {
        clusterer.clearMarkers();
        clusterer.setMap(null);
        clusterer = null;
    }
    customerMarkers.forEach((m) => m.setMap(null));
    customerMarkers = [];
    if (heatmapOverlay) {
        heatmapOverlay.setMap(null);
        heatmapOverlay = null;
    }
    coverageCircles.forEach((c) => c.setMap(null));
    coverageCircles = [];
    traceLines.forEach((l) => l.setMap(null));
    traceLines = [];
    nodeMarkers.forEach((m) => m.setMap(null));
    nodeMarkers = [];
    clearFiberOverlay();
    if (infoWindow) infoWindow.close();
};

// Mapa de calor de densidad de clientes.
//
// Google retiró HeatmapLayer (visualization), así que lo dibujamos nosotros con
// un OverlayView sobre <canvas>: por cada cliente se pinta un degradado radial
// en escala de grises (se acumulan al solaparse → más densidad = más opaco) y al
// final se recolorea ese acumulado con una paleta azul→verde→amarillo→rojo. El
// resultado es una mancha de calor continua, no círculos sueltos.
let HeatmapOverlayClass = null;

const buildHeatPalette = () => {
    const c = document.createElement("canvas");
    c.width = 1;
    c.height = 256;
    const ctx = c.getContext("2d");
    const grad = ctx.createLinearGradient(0, 0, 0, 256);
    grad.addColorStop(0.45, "#2563EB"); // azul   – poca densidad
    grad.addColorStop(0.6, "#22C55E"); // verde
    grad.addColorStop(0.75, "#EAB308"); // amarillo
    grad.addColorStop(0.88, "#F97316"); // naranja
    grad.addColorStop(1.0, "#EF4444"); // rojo   – máxima densidad
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 1, 256);
    return ctx.getImageData(0, 0, 1, 256).data; // 256 colores * RGBA
};

const createHeatmapOverlay = (g, points, opts = {}) => {
    if (!HeatmapOverlayClass) {
        HeatmapOverlayClass = class extends g.maps.OverlayView {
            constructor(points, opts) {
                super();
                this.points = points;
                this.radius = opts.radius || 34; // px del degradado por cliente
                this.maxOpacity = opts.opacity != null ? opts.opacity : 0.6;
                this.pointAlpha = opts.pointAlpha || 0.2; // aporte por cliente
                this.canvas = null;
                this.palette = null;
            }
            onAdd() {
                const canvas = document.createElement("canvas");
                canvas.style.position = "absolute";
                canvas.style.pointerEvents = "none";
                this.canvas = canvas;
                this.palette = buildHeatPalette();
                this.getPanes().overlayLayer.appendChild(canvas);
            }
            onRemove() {
                if (this.canvas && this.canvas.parentNode) {
                    this.canvas.parentNode.removeChild(this.canvas);
                }
                this.canvas = null;
            }
            draw() {
                const proj = this.getProjection();
                const map = this.getMap();
                if (!proj || !map || !this.canvas) return;
                const bounds = map.getBounds();
                if (!bounds) return;

                const div = map.getDiv();
                const w = div.offsetWidth;
                const h = div.offsetHeight;
                if (!w || !h) return;

                // Esquina superior-izquierda del viewport en píxeles del pane.
                const topLeft = proj.fromLatLngToDivPixel(
                    new g.maps.LatLng(
                        bounds.getNorthEast().lat(),
                        bounds.getSouthWest().lng()
                    )
                );
                const canvas = this.canvas;
                canvas.style.left = `${topLeft.x}px`;
                canvas.style.top = `${topLeft.y}px`;
                if (canvas.width !== w) canvas.width = w;
                if (canvas.height !== h) canvas.height = h;

                const ctx = canvas.getContext("2d");
                ctx.clearRect(0, 0, w, h);

                // 1) Acumular densidad en escala de grises (alfa) por solapamiento.
                const r = this.radius;
                for (const p of this.points) {
                    const px = proj.fromLatLngToDivPixel(
                        new g.maps.LatLng(p.lat, p.lng)
                    );
                    const x = px.x - topLeft.x;
                    const y = px.y - topLeft.y;
                    if (x < -r || y < -r || x > w + r || y > h + r) continue;
                    const grad = ctx.createRadialGradient(x, y, 0, x, y, r);
                    grad.addColorStop(0, `rgba(0,0,0,${this.pointAlpha})`);
                    grad.addColorStop(1, "rgba(0,0,0,0)");
                    ctx.fillStyle = grad;
                    ctx.fillRect(x - r, y - r, r * 2, r * 2);
                }

                // 2) Recolorear el alfa acumulado con la paleta de calor.
                const img = ctx.getImageData(0, 0, w, h);
                const data = img.data;
                const pal = this.palette;
                const maxOp = this.maxOpacity;
                for (let i = 0; i < data.length; i += 4) {
                    const a = data[i + 3];
                    if (a === 0) continue;
                    const off = a * 4;
                    data[i] = pal[off];
                    data[i + 1] = pal[off + 1];
                    data[i + 2] = pal[off + 2];
                    data[i + 3] = Math.round(a * maxOp);
                }
                ctx.putImageData(img, 0, 0);
            }
        };
    }
    return new HeatmapOverlayClass(points, opts);
};

const renderHeatmap = (list, g) => {
    const points = list
        .map((c) => ({ lat: Number(c.latitude), lng: Number(c.longitude) }))
        .filter((p) => Number.isFinite(p.lat) && Number.isFinite(p.lng));
    if (!points.length) return;

    heatmapOverlay = createHeatmapOverlay(g, points, {
        radius: 34,
        opacity: 0.6,
        pointAlpha: 0.22,
    });
    heatmapOverlay.setMap(map);
};

const applyLayers = () => {
    if (!mapReady || !map || !window.google?.maps) return;
    const g = window.google;
    clearLayers();

    const list = filteredCustomers.value;
    const bounds = new g.maps.LatLngBounds();
    let hasBounds = false;

    // Customers (clustered markers)
    if (layers.value.customers) {
        list.forEach((c) => {
            const pos = {
                lat: Number(c.latitude),
                lng: Number(c.longitude),
            };
            const marker = new g.maps.Marker({
                position: pos,
                title: `${c.name} ${c.last_name}`,
                icon: {
                    url: createPinUrl(getMarkerColor(c.department)),
                    scaledSize: new g.maps.Size(32, 42),
                    anchor: new g.maps.Point(16, 42),
                },
            });
            marker.addListener("click", () => {
                infoWindow.setContent(customerPopup(c));
                infoWindow.open({ anchor: marker, map });
            });
            customerMarkers.push(marker);
            bounds.extend(pos);
            hasBounds = true;
        });

        if (window.markerClusterer && customerMarkers.length) {
            clusterer = new window.markerClusterer.MarkerClusterer({
                map,
                markers: customerMarkers,
            });
        } else {
            customerMarkers.forEach((m) => m.setMap(map));
        }
    }

    // Heatmap (densidad de clientes). Aislado en try/catch para que un fallo
    // del canvas nunca aborte applyLayers y haga desaparecer cobertura, nodos y
    // sectoriales.
    if (layers.value.heatmap) {
        try {
            renderHeatmap(list, g);
            list.forEach((c) => {
                bounds.extend({
                    lat: Number(c.latitude),
                    lng: Number(c.longitude),
                });
                hasBounds = true;
            });
        } catch (e) {
            console.error("No se pudo dibujar el mapa de calor:", e);
        }
    }

    // Coverage zones (círculos de cobertura de cada sectorial). Cada zona es
    // clicable: muestra a qué elemento pertenece y su radio, para identificar
    // de un vistazo cualquier círculo desproporcionado y corregir ese elemento.
    if (layers.value.coverage) {
        sectorials.value.forEach((s) => {
            const center = {
                lat: Number(s.latitude),
                lng: Number(s.longitude),
            };
            const radius = effectiveCoverageRadius(s);
            const circle = new g.maps.Circle({
                map,
                center,
                radius,
                strokeColor: "#F59E0B",
                strokeOpacity: 0.8,
                strokeWeight: 1,
                fillColor: "#F59E0B",
                fillOpacity: 0.12,
            });
            circle.addListener("click", (ev) => {
                infoWindow.setContent(
                    `<div style="font-family:inherit;padding:2px 4px;">
                        <strong style="color:#111827;">${escapeHtml(
                            s.name
                        )}</strong>
                        <div style="font-size:12px;color:#6B7280;margin-top:2px;">
                            Zona de cobertura · ${Math.round(radius)} m
                        </div>
                    </div>`
                );
                infoWindow.setPosition(ev.latLng);
                infoWindow.open(map);
            });
            coverageCircles.push(circle);
            const cb = circle.getBounds();
            if (cb) {
                bounds.union(cb);
                hasBounds = true;
            }
        });
    }

    // Network nodes (routers + sectorials)
    if (layers.value.nodes) {
        routers.value.forEach((r) => {
            const pos = {
                lat: Number(r.latitude),
                lng: Number(r.longitude),
            };
            const marker = new g.maps.Marker({
                position: pos,
                title: r.name,
                zIndex: 999,
                icon: {
                    url: routerIconUrl(),
                    scaledSize: new g.maps.Size(28, 28),
                    anchor: new g.maps.Point(14, 14),
                },
            });
            marker.addListener("click", () => {
                infoWindow.setContent(nodePopup(r, "router"));
                infoWindow.open({ anchor: marker, map });
            });
            marker.setMap(map);
            nodeMarkers.push(marker);
            bounds.extend(pos);
            hasBounds = true;
        });

        sectorials.value.forEach((s) => {
            const pos = {
                lat: Number(s.latitude),
                lng: Number(s.longitude),
            };
            const marker = new g.maps.Marker({
                position: pos,
                title: s.name,
                zIndex: 999,
                icon: {
                    url: sectorialIconUrl(s.element_type),
                    scaledSize: new g.maps.Size(34, 34),
                    anchor: new g.maps.Point(17, 17),
                },
            });
            marker.addListener("click", () => {
                infoWindow.setContent(nodePopup(s, "sectorial"));
                infoWindow.open({ anchor: marker, map });
            });
            marker.setMap(map);
            nodeMarkers.push(marker);
            bounds.extend(pos);
            hasBounds = true;
        });
    }

    // Trazabilidad. Cada cliente se enlaza con SU sectorial (sectorial_id), no
    // con el nodo principal. Según el tipo de sectorial:
    //  • Radioenlace → línea recta automática cliente ↔ sectorial.
    //  • Fibra (NAP) → ruta manual punto por punto (drawSelectedTrace); aquí
    //    solo se trazan las de radioenlace.
    if (layers.value.traces) {
        list.forEach((c) => {
            if (!c.sectorial_id) return;
            const sec = sectorialById.value.get(String(c.sectorial_id));
            if (!sec || isFiberSectorial(sec)) return;
            const line = new g.maps.Polyline({
                map,
                path: [
                    { lat: Number(c.latitude), lng: Number(c.longitude) },
                    { lat: Number(sec.latitude), lng: Number(sec.longitude) },
                ],
                geodesic: true,
                strokeColor:
                    (c.service_status || "activo") === "activo"
                        ? "#10B981"
                        : "#EF4444",
                strokeOpacity: 0.6,
                strokeWeight: 1.5,
            });
            traceLines.push(line);
        });

        drawSelectedTrace(g);
    }

    if (hasBounds) {
        map.fitBounds(bounds);
        g.maps.event.addListenerOnce(map, "idle", () => {
            if (map.getZoom() > 16) map.setZoom(16);
        });
    }
};

// ── Trazabilidad ───────────────────────────────────────────────────────────
// Índice de sectoriales por id para enlazar cada cliente con SU sectorial.
const sectorialById = computed(
    () => new Map(sectorials.value.map((s) => [String(s.id), s]))
);

// ¿La sectorial es planta de fibra? Reconoce tanto el nuevo element_type
// (nap/splitter/olt/mufa) como datos heredados cuyo subtipo (type) decía "NAP".
const isFiberSectorial = (sec) =>
    isFiber(sec?.element_type) ||
    String(sec?.type || "")
        .toUpperCase()
        .includes("NAP");

// Sectoriales con coordenadas válidas, para el selector del panel.
const traceableSectorials = computed(() =>
    sectorials.value.filter(
        (s) =>
            Number.isFinite(Number(s.latitude)) &&
            Number.isFinite(Number(s.longitude))
    )
);

// Etiqueta de cada opción del buscador de sectoriales.
const sectorialOptionLabel = (s) =>
    `${s?.name ?? ""} · ${isFiberSectorial(s) ? "Fibra/NAP" : "Radio"}`;

// El flujo arranca eligiendo la SECTORIAL; el destino se fija por coordenadas.
const traceSectorialId = ref("");
const fiberPoints = ref([]); // waypoints intermedios (solo fibra)
const fiberDrawing = ref(false);

// Destino opcional por coordenadas (pensado para clientes NUEVOS que aún no
// existen en el sistema). Se escribe "lat lng" (o "lat, lng") y, al confirmar,
// se marca un pin en el mapa y la ruta termina ahí.
const destCoordsInput = ref("");
const destPoint = ref(null); // { lat, lng } confirmado, o null
const destError = ref("");

const traceSectorial = computed(
    () =>
        traceableSectorials.value.find(
            (s) => String(s.id) === String(traceSectorialId.value)
        ) || null
);
const traceIsFiber = computed(
    () => !!traceSectorial.value && isFiberSectorial(traceSectorial.value)
);

// Parsea "4.458429 -74.636633" / "4.458429, -74.636633" → { lat, lng } válido.
const parseLatLng = (raw) => {
    const m = String(raw || "")
        .trim()
        .match(/(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)/);
    if (!m) return null;
    const lat = Number(m[1]);
    const lng = Number(m[2]);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    return { lat, lng };
};

const applyDestCoords = () => {
    const raw = destCoordsInput.value.trim();
    if (!raw) {
        destPoint.value = null;
        destError.value = "";
        return;
    }
    const p = parseLatLng(raw);
    if (!p) {
        destError.value = "Formato inválido. Usa: 4.458429 -74.636633";
        return;
    }
    destError.value = "";
    destPoint.value = p;
    if (map && window.google?.maps) map.panTo(p);
};

const clearDest = () => {
    destCoordsInput.value = "";
    destPoint.value = null;
    destError.value = "";
};

// Basta con tener una NAP de fibra seleccionada para empezar a dibujar.
const startFiberDrawing = () => {
    if (!traceIsFiber.value) return;
    layers.value.traces = true;
    fiberDrawing.value = true;
};
const finishFiberDrawing = () => {
    fiberDrawing.value = false;
};
const undoFiberPoint = () => {
    fiberPoints.value = fiberPoints.value.slice(0, -1);
};
const resetFiberTrace = () => {
    fiberPoints.value = [];
};

// En modo dibujo (fibra), cada clic en el mapa añade un punto a la ruta.
const onMapClick = (e) => {
    if (!fiberDrawing.value || !traceIsFiber.value) return;
    fiberPoints.value = [
        ...fiberPoints.value,
        { lat: e.latLng.lat(), lng: e.latLng.lng() },
    ];
};

// Ruta de fibra completa: NAP → waypoints → (destino por coordenadas, si se fijó).
const fiberRoutePath = computed(() => {
    const sec = traceSectorial.value;
    if (!sec || !traceIsFiber.value) return [];
    const path = [{ lat: Number(sec.latitude), lng: Number(sec.longitude) }];
    for (const p of fiberPoints.value) path.push(p);
    if (destPoint.value) path.push(destPoint.value);
    return path.filter(
        (p) => Number.isFinite(p.lat) && Number.isFinite(p.lng)
    );
});

// Distancia acumulada de la ruta dibujada (metros), tramo a tramo.
const fiberRouteDistanceM = computed(() => {
    const path = fiberRoutePath.value;
    let d = 0;
    for (let i = 1; i < path.length; i++) {
        d += haversineMeters(
            path[i - 1].lat,
            path[i - 1].lng,
            path[i].lat,
            path[i].lng
        );
    }
    return d;
});

const fiberDistanceLabel = computed(() => {
    const m = fiberRouteDistanceM.value;
    if (m >= 1000) return `${(m / 1000).toFixed(2)} km`;
    return `${Math.round(m)} m`;
});

const clearFiberOverlay = () => {
    if (fiberLine) {
        fiberLine.setMap(null);
        fiberLine = null;
    }
    fiberMarkers.forEach((m) => m.setMap(null));
    fiberMarkers = [];
};

// Pin del destino (cliente nuevo): teardrop rosado con una cruz blanca.
const destPinUrl = () => {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="40" viewBox="0 0 32 42"><path d="M16 0C7.163 0 0 7.163 0 16c0 12 16 26 16 26s16-14 16-26C32 7.163 24.837 0 16 0z" fill="#E11D48" stroke="#fff" stroke-width="2"/><path d="M16 9v12M10 15h12" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>`;
    return svgUrl(svg);
};

// Marca el destino fijado por coordenadas con un pin distintivo.
const addDestMarker = (g) => {
    if (!destPoint.value) return;
    const marker = new g.maps.Marker({
        map,
        position: destPoint.value,
        title: "Destino (cliente nuevo)",
        icon: {
            url: destPinUrl(),
            scaledSize: new g.maps.Size(30, 40),
            anchor: new g.maps.Point(15, 40),
        },
        zIndex: 1002,
    });
    fiberMarkers.push(marker);
};

// Resalta el enlace seleccionado. Fibra: ruta punteada NAP → waypoints →
// (destino por coordenadas) con marcadores numerados. Radio: línea recta
// NAP↔destino solo si hay coordenadas de destino.
const drawSelectedTrace = (g) => {
    clearFiberOverlay();
    const sec = traceSectorial.value;
    if (!sec) return;
    const a = { lat: Number(sec.latitude), lng: Number(sec.longitude) };
    if (!Number.isFinite(a.lat) || !Number.isFinite(a.lng)) return;

    if (traceIsFiber.value) {
        // Fibra: NAP → waypoints → destino. Línea punteada con ≥2 puntos.
        const path = fiberRoutePath.value;
        if (path.length >= 2) {
            fiberLine = new g.maps.Polyline({
                map,
                path,
                geodesic: true,
                strokeOpacity: 0,
                icons: [
                    {
                        icon: {
                            path: "M 0,-1 0,1",
                            strokeColor: "#0EA5E9",
                            strokeOpacity: 1,
                            strokeWeight: 3,
                            scale: 2,
                        },
                        offset: "0",
                        repeat: "14px",
                    },
                ],
                zIndex: 1000,
            });
        }

        fiberPoints.value.forEach((p, i) => {
            const marker = new g.maps.Marker({
                map,
                position: p,
                label: {
                    text: String(i + 1),
                    color: "#fff",
                    fontSize: "10px",
                },
                icon: {
                    path: g.maps.SymbolPath.CIRCLE,
                    scale: 8,
                    fillColor: "#0EA5E9",
                    fillOpacity: 1,
                    strokeColor: "#fff",
                    strokeWeight: 2,
                },
            });
            fiberMarkers.push(marker);
        });
        addDestMarker(g);
        return;
    }

    // Radioenlace: línea recta resaltada NAP → destino (solo con coordenadas).
    const b = destPoint.value;
    if (!b) return;
    fiberLine = new g.maps.Polyline({
        map,
        path: [a, b],
        geodesic: true,
        strokeColor: "#6366F1",
        strokeOpacity: 0.95,
        strokeWeight: 3.5,
        zIndex: 1000,
    });
    fiberMarkers.push(
        new g.maps.Marker({
            map,
            position: a,
            icon: {
                path: g.maps.SymbolPath.CIRCLE,
                scale: 6,
                fillColor: "#6366F1",
                fillOpacity: 1,
                strokeColor: "#fff",
                strokeWeight: 2,
            },
            zIndex: 1001,
        })
    );
    addDestMarker(g);
};

// ── Perfil de elevación (radioenlace) ───────────────────────────────────────
// Distancia geodésica (m) entre dos puntos por la fórmula del haversine, para
// no depender de la librería "geometry" de Google.
const haversineMeters = (lat1, lng1, lat2, lng2) => {
    const R = 6371000;
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const s =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(toRad(lat1)) *
            Math.cos(toRad(lat2)) *
            Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
};

// Alturas de antena (m) editables; afectan la línea de vista del perfil.
const apHeight = ref(15);
const cpeHeight = ref(6);

// Resultado de la consulta a la Elevation API.
const elevation = ref({
    loading: false,
    error: "",
    samples: [],
    distanceM: 0,
});

let elevationService = null;

const computeElevation = () => {
    const sec = traceSectorial.value;
    const dst = destPoint.value;
    const g = window.google;
    if (!sec || !dst || !g?.maps) return;

    const aLat = Number(sec.latitude);
    const aLng = Number(sec.longitude);
    const bLat = dst.lat;
    const bLng = dst.lng;
    const distanceM = haversineMeters(aLat, aLng, bLat, bLng);

    elevation.value = { loading: true, error: "", samples: [], distanceM };

    if (!elevationService) {
        elevationService = new g.maps.ElevationService();
    }
    elevationService.getElevationAlongPath(
        {
            path: [
                new g.maps.LatLng(aLat, aLng),
                new g.maps.LatLng(bLat, bLng),
            ],
            samples: 180,
        },
        (results, status) => {
            if (status === "OK" && Array.isArray(results)) {
                elevation.value = {
                    loading: false,
                    error: "",
                    samples: results.map((r) => r.elevation),
                    distanceM,
                };
            } else {
                elevation.value = {
                    loading: false,
                    error:
                        status === "REQUEST_DENIED"
                            ? "La Elevation API está deshabilitada para esta clave. Habilítala en Google Cloud Console (Elevation API)."
                            : `No se pudo obtener el perfil de elevación (${status}).`,
                    samples: [],
                    distanceM,
                };
            }
        }
    );
};

// Construye el perfil (terreno + línea de vista) en coordenadas de un SVG
// 760×220 listo para pintar en la plantilla. Detecta obstrucción comparando el
// terreno contra la recta AP→cliente (incluyendo alturas de antena).
const elevationChart = computed(() => {
    const s = elevation.value.samples;
    if (!s || s.length < 2) return null;
    const n = s.length;
    const apGround = s[0];
    const cpeGround = s[n - 1];
    const apTop = apGround + (Number(apHeight.value) || 0);
    const cpeTop = cpeGround + (Number(cpeHeight.value) || 0);

    let minEl = Math.min(...s, apTop, cpeTop);
    let maxEl = Math.max(...s, apTop, cpeTop);
    const span = Math.max(1, maxEl - minEl);
    minEl -= span * 0.12;
    maxEl += span * 0.12;

    const W = 760;
    const H = 220;
    const padT = 8;
    const padB = 22;
    const plotH = H - padT - padB;
    const xAt = (i) => (i / (n - 1)) * W;
    const yAt = (v) => padT + plotH - ((v - minEl) / (maxEl - minEl)) * plotH;

    let terrain = `M0,${(H - padB).toFixed(1)}`;
    for (let i = 0; i < n; i++) {
        terrain += ` L${xAt(i).toFixed(1)},${yAt(s[i]).toFixed(1)}`;
    }
    terrain += ` L${W},${(H - padB).toFixed(1)} Z`;

    const losY0 = yAt(apTop);
    const losY1 = yAt(cpeTop);

    // Margen mínimo de la línea de vista sobre el terreno (m). Negativo ⇒ choca.
    let worst = Infinity;
    for (let i = 1; i < n - 1; i++) {
        const losEl = apTop + (cpeTop - apTop) * (i / (n - 1));
        const margin = losEl - s[i];
        if (margin < worst) worst = margin;
    }
    if (!Number.isFinite(worst)) worst = 0;

    return {
        W,
        H,
        padB,
        terrain,
        los: `M0,${losY0.toFixed(1)} L${W},${losY1.toFixed(1)}`,
        ap: { x: 0, y: Number(losY0.toFixed(1)) },
        cpe: { x: W, y: Number(losY1.toFixed(1)) },
        clear: worst >= 0,
        worst: Math.round(worst),
        minEl: Math.round(minEl),
        maxEl: Math.round(maxEl),
        apGround: Math.round(apGround),
        cpeGround: Math.round(cpeGround),
    };
});

const elevationDistanceLabel = computed(() => {
    const m = elevation.value.distanceM;
    if (!m) return "";
    return m >= 1000 ? `${(m / 1000).toFixed(2)} km` : `${Math.round(m)} m`;
});

const initMap = async () => {
    if (!mapEl.value) throw new Error("Contenedor del mapa no disponible");
    if (!window.google?.maps)
        throw new Error("Google Maps no quedó disponible tras la carga");
    const g = window.google;
    map = new g.maps.Map(mapEl.value, {
        center: { lat: 4.5709, lng: -74.2973 },
        zoom: 6,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: true,
    });
    infoWindow = new g.maps.InfoWindow();

    // El mapa de calor lo dibujamos con un OverlayView sobre <canvas>
    // (createHeatmapOverlay), así que ya no dependemos de la librería
    // "visualization" / HeatmapLayer, que Google retiró.

    mapClickListener = map.addListener("click", onMapClick);
    mapReady = true;
    applyLayers();
};

const loadMapData = async () => {
    let step = "inicial";
    try {
        loading.value = true;
        error.value = "";
        apiKeyMissing.value = false;

        step = "leer configuración del tenant";
        const configResponse = await tenantApi.getMapsConfig();
        const config = configResponse.data?.data || {};
        if (!config.has_key || !config.google_maps_api_key) {
            apiKeyMissing.value = true;
            loading.value = false;
            return;
        }

        window.gm_authFailure = () => {
            error.value =
                "La clave de API de Google Maps fue rechazada por Google. Causas típicas: la facturación no está activada, la «Maps JavaScript API» no está habilitada, o la restricción de dominio (HTTP referrer) no incluye esta URL. Revísalo en Google Cloud Console.";
            loading.value = false;
        };

        step = "obtener datos de clientes y nodos";
        const response = await api.customers.getMapData();
        const data = response.data || {};
        allCustomers.value = data.customers || [];
        routers.value = data.routers || [];
        sectorials.value = data.sectorials || [];

        step = "cargar Google Maps";
        await loadGoogleMaps(config.google_maps_api_key);

        // Clusterer is optional: if the CDN fails we fall back to plain markers.
        step = "cargar agrupador de marcadores (opcional)";
        await loadMarkerClusterer().catch((e) =>
            console.warn("MarkerClusterer no disponible, se usarán marcadores individuales:", e)
        );

        loading.value = false;
        await nextTick();
        step = "renderizar el mapa";
        await initMap();
    } catch (err) {
        console.error(`Error al cargar el mapa (paso: ${step}):`, err);
        const detail =
            err?.response?.status
                ? `HTTP ${err.response.status}`
                : err?.message || String(err);
        error.value = `Error al cargar el mapa al ${step}. Detalle: ${detail}. Revisa la consola del navegador (F12) para más información.`;
        loading.value = false;
    }
};

watch(
    [filteredCustomers, layers],
    () => {
        applyLayers();
    },
    { deep: true }
);

// Redibuja solo el enlace seleccionado cuando cambian sus puntos, la sectorial
// o el destino, sin reconstruir el resto de capas.
watch(
    [fiberPoints, traceSectorialId, destPoint, fiberDrawing],
    () => {
        if (mapReady && window.google?.maps && layers.value.traces) {
            drawSelectedTrace(window.google);
        }
    },
    { deep: true }
);

// Cambiar de sectorial reinicia el destino, la ruta y el perfil en curso.
watch(traceSectorialId, () => {
    clearDest();
    fiberPoints.value = [];
    fiberDrawing.value = false;
    elevation.value = { loading: false, error: "", samples: [], distanceM: 0 };
});

onMounted(() => {
    loadMapData();
});

onBeforeUnmount(() => {
    if (mapClickListener) {
        mapClickListener.remove();
        mapClickListener = null;
    }
    clearLayers();
    map = null;
    mapReady = false;
    delete window.gm_authFailure;
});
</script>
