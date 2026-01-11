<template>
  <li>
    <button
      @click="isOpen = !isOpen"
      class="group flex items-center justify-between w-full p-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all duration-200"
      :class="{ 'bg-indigo-50 dark:bg-indigo-900/10 text-indigo-600 dark:text-indigo-400': isOpen }"
    >
      <div class="flex items-center gap-3">
        <v-icon :name="icon" class="w-5 h-5 group-hover:scale-110 transition-transform duration-200 dark:text-white dark:group-hover:text-indigo-400" />
        <span class="text-sm font-medium">{{ title }}</span>
      </div>
      <v-icon
        name="hi-chevron-down"
        class="w-4 h-4 transition-transform duration-200 dark:text-white dark:group-hover:text-indigo-400"
        :class="{ 'rotate-180': isOpen }"
      />
    </button>

    <ul
      v-show="isOpen"
      class="pl-4 mt-1 space-y-1"
    >
      <li v-for="item in items" 
        :key="item.name"
      >
        <RouterLink
          :to="item.to"
          class="group flex items-center gap-3 p-2 rounded-lg text-sm font-medium transition-all duration-200"
          :class="[
            $route.path === item.to
              ? 'bg-indigo-50 dark:bg-indigo-900/10 text-indigo-600 dark:text-indigo-400'
              : 'text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-gray-50 dark:hover:bg-gray-800/50'
          ]"
        >
          <v-icon v-if="item.icon" :name="item.icon" class="w-4 h-4 dark:text-white dark:group-hover:text-indigo-400" />
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
