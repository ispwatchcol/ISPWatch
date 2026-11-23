<template>
  <!-- Las tres listas del catálogo de inventario comparten UNA entrada de menú
       («Catálogo»): se pasa de una a otra desde aquí, sin volver al menú. -->
  <nav class="mb-6 flex gap-1 overflow-x-auto rounded-xl bg-white dark:bg-gray-800 p-1 shadow-sm border border-gray-100 dark:border-gray-700"
    aria-label="Catálogo de inventario" data-testid="inventory-catalog-nav">
    <RouterLink v-for="tab in tabs" :key="tab.to" :to="tab.to"
      class="flex-1 min-w-max inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition"
      :class="route.path === tab.to
        ? 'bg-purple-600 text-white shadow'
        : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
      :aria-current="route.path === tab.to ? 'page' : undefined">
      <v-icon :name="tab.icon" class="w-4 h-4" />
      {{ tab.label }}
    </RouterLink>
  </nav>
</template>

<script setup>
import { RouterLink, useRoute } from 'vue-router'

const route = useRoute()

const tabs = [
  { to: '/inventory/stocks', label: 'Stock / Modelos', icon: 'md-inventory-round' },
  { to: '/inventory/providers', label: 'Proveedores', icon: 'bi-building' },
  { to: '/inventory/branches', label: 'Sucursales', icon: 'md-storemalldirectory' },
]
</script>
