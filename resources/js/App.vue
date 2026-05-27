<template>
  <div id="app">
    <router-view /> <!-- Aquí se renderizan todas las páginas según la ruta -->
  </div>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useAuthStore } from './stores/auth';

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
