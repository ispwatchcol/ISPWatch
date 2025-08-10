<template>
  <li>
    <button
      @click="isOpen = !isOpen"
      class="flex items-center justify-between w-full p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/20 text-gray-700 dark:text-gray-200"
    >
      <div class="flex items-center gap-3">
        <v-icon :name="icon" class="w-5 h-5" />
        <span>{{ title }}</span>
      </div>
      <v-icon
        name="hi-chevron-down"
        class="w-4 h-4 transition-transform duration-200"
        :class="{ 'rotate-180': isOpen }"
      />
    </button>

    <ul
      v-if="isOpen"
      class="pl-4 mt-1 space-y-1"
    >
      <li v-for="item in items" 
        :key="item.name"
        :class="[
          'rounded',
          $route.path === item.to
            ? 'bg-blue-500 text-white'
            : 'text-gray-600 hover:bg-gray-200'
        ]"
      >
        <RouterLink
          :to="item.to"
          class="flex items-center space-x-2 p-2 w-full"
        >
          <v-icon v-if="item.icon" :name="item.icon" class="w-4 h-4" />
          <span>{{ item.name }}</span>
        </RouterLink>
      </li>
    </ul>
  </li>
</template>

<script setup>
import { ref } from "vue";

defineProps({
  icon: String,
  title: String,
  items: Array
});

const isOpen = ref(false);
</script>
