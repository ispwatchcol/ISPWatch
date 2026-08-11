<script setup>
/**
 * Manual de usuario dentro de la app (/manual). Va montado en DefaultLayout,
 * así que hereda el menú lateral y el tema claro/oscuro de la sesión.
 *
 * Todo el contenido vive en components/ManualContent.vue, que comparte con la
 * versión pública (/ayuda) — así no hay dos textos que mantener.
 */
import ManualContent from '@/components/ManualContent.vue';
</script>

<template>
    <ManualContent />
</template>
