<template>
    <div class="flex h-screen bg-gray-100">
        <!-- Sidebar -->
        <Sidebar />
        <!-- Main Content -->
        <div class="flex-1 flex flex-col">
            <main class="flex-1 p-4 bg-gray-100 overflow-y-auto">
                <Dashboard />
            </main>
        </div>
    </div>
</template>

<script setup>
import Sidebar from '../components/Sidebar.vue';
import Dashboard from '../pages/Dashboard.vue';
</script>