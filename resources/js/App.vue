<template>
  <div id="app">
    <router-view /> <!-- Aquí se renderizan todas las páginas según la ruta -->
  </div>
</template>

<script setup>
import { onMounted, onUnmounted } from 'vue';

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

onMounted(() => {
    // Load initial preferences
    const saved = localStorage.getItem('uiPreferences');
    if (saved) {
        try {
            applyPreferences(JSON.parse(saved));
        } catch(e) {
            console.error('Error loading UI prefs:', e);
        }
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
