<template>
    <div class="flex min-h-screen bg-gray-100">
        <!-- Sidebar -->
        <aside class="fixed top-0 left-0 h-screen w-64 bg-white border-r border-gray-200 z-50">
            <Sidebar />
        </aside>

        <!-- Main Content -->
        <main class="flex-1 ml-64 p-6">
            <router-view />
        </main>
    </div>
</template>

<script setup>
import Sidebar from '../components/Sidebar.vue';
import { RouterView } from 'vue-router';
</script>