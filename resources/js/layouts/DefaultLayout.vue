<template>
    <div class="flex min-h-screen bg-gray-100 dark:bg-gray-900">
        <!-- Mobile Header (Visible only on mobile) -->
        <div class="md:hidden fixed top-0 left-0 right-0 h-16 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 z-40 flex items-center justify-between px-4">
            <!-- Logo area -->
            <div class="flex items-center gap-2">
                <img :src="'/brand/icon.svg'" alt="ISP Watch" class="w-9 h-9" />
                <span class="font-extrabold text-lg text-gray-900 dark:text-white">ISP<span class="text-blue-600">Watch</span></span>
            </div>
            
            <!-- Hamburger Button -->
            <button 
                @click="isSidebarOpen = !isSidebarOpen"
                class="p-2 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                aria-label="Menu"
            >
                <svg v-if="!isSidebarOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
                <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Sidebar Backdrop (Mobile only) -->
        <div 
            v-if="isSidebarOpen" 
            @click="isSidebarOpen = false"
            class="fixed inset-0 bg-black/50 z-40 md:hidden backdrop-blur-sm transition-opacity"
        ></div>

        <!-- Sidebar -->
        <aside 
            class="fixed top-0 left-0 h-screen w-64 bg-white dark:bg-gray-900 border-r border-gray-200 dark:border-gray-800 z-50 transition-transform duration-300 ease-in-out md:translate-x-0"
            :class="isSidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <Sidebar />
        </aside>

        <!-- Main Content -->
        <main class="flex-1 md:ml-64 pt-16 md:pt-0 transition-all duration-300">
            <router-view />
        </main>

        <!-- WhatsApp Floating Button -->
        <WhatsAppButton />
    </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import Sidebar from '../components/Sidebar.vue';
import WhatsAppButton from '../components/WhatsAppButton.vue';

const isSidebarOpen = ref(false);
const route = useRoute();

// Close sidebar when route changes
watch(() => route.path, () => {
    isSidebarOpen.value = false;
});
</script>