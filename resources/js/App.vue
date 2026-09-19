<template>
  <div id="app">
    <router-view /> <!-- Aquí se renderizan todas las páginas según la ruta -->

    <!--
      EL ÚNICO contenedor de notificaciones. Se monta aquí, fuera del
      `router-view`, para que sobreviva a los cambios de página y quede por
      encima de cualquier modal (`.z-app-toast`). Ninguna pantalla debe montar
      otro: `NotificationToast` es sólo un adaptador que escribe en la misma
      cola. Ver `composables/useNotifications.js`.
    -->
    <NotificationHost />
  </div>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useAuthStore } from './stores/auth';
import NotificationHost from './components/NotificationHost.vue';

const authStore = useAuthStore();

const applyPreferences = (prefs) => {
    const html = document.documentElement;
    
    // Compact Mode (reduces base font size)
    if (prefs.compact_mode) {
        html.classList.add('compact-mode');
    } else {
        html.classList.remove('compact-mode');
    }
    
    // Animations
    if (prefs.animations_enabled === false) {
        html.classList.add('no-animations');
    } else {
        html.classList.remove('no-animations');
    }
}

const handlePrefsUpdate = (event) => {
    applyPreferences(event.detail);
}

onMounted(async () => {
    // Load initial preferences
    const saved = localStorage.getItem('uiPreferences');
    if (saved) {
        try {
            applyPreferences(JSON.parse(saved));
        } catch(e) {
            console.error('Error loading UI prefs:', e);
        }
    }

    // Hydrate the auth store from storage BEFORE checking authentication.
    // On a cold load the store starts empty and is otherwise only hydrated
    // lazily by the router guard — which runs after this hook. Without this
    // line `isAuthenticated` was false here, so the refresh below was silently
    // skipped and users kept whatever permissions were cached at their last
    // login. That meant role/permission changes (e.g. enabling "Ver
    // Sectoriales" for the Técnico role) never reached an already-remembered
    // session until a full credential re-login.
    authStore.loadFromStorage();

    // Refresh permissions from server so role_code and permissions stay in sync
    // without requiring re-login after role/permission changes
    if (authStore.isAuthenticated) {
        await authStore.refreshUserPermissions();
    }

    window.addEventListener('ui-preferences-updated', handlePrefsUpdate);
});

onUnmounted(() => {
    window.removeEventListener('ui-preferences-updated', handlePrefsUpdate);
});
</script>

<style>
/* Estilos globales opcionales */
</style>
