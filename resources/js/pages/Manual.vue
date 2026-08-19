<!--
  Centro de Ayuda.

  POR QUÉ ESTE LAYOUT
  El diseño anterior era una rejilla de tarjetas: una tarjeta por categoría, y
  el artículo se leía en un modal. Con 11 categorías y 41 artículos eso obliga a
  barrer la pantalla entera para encontrar un tema, y el modal tapa el índice
  justo cuando uno quiere saltar al artículo siguiente.

  Ahora es índice fijo a la izquierda + lectura a la derecha, que es como se leen
  las documentaciones y como está el manual de Converza. El índice nunca
  desaparece, el artículo se lee en la página (no en una capa encima), y el
  buscador filtra sobre el índice en vez de sobre nada.

  El CONTENIDO no cambió: sigue viniendo de la base (categorías y artículos
  editables por el superadmin). Lo que cambió es cómo se navega.
-->
<template>
  <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-4">

    <!-- ── Encabezado ──────────────────────────────────────────────────── -->
    <div class="flex flex-wrap justify-between items-start gap-3 mb-6">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center shadow-md shrink-0">
          <v-icon name="hi-book-open" class="w-7 h-7 text-white" />
        </div>
        <div class="min-w-0">
          <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Centro de Ayuda</h1>
          <p class="text-gray-500 dark:text-gray-400 text-sm">
            Documentación oficial y guías de uso del sistema
          </p>
        </div>
      </div>

      <div v-if="isSuperadmin" class="flex gap-2">
        <button @click="openCategoryModal()" class="px-4 py-2 bg-white dark:bg-gray-800 text-gray-700 dark:text-white border border-gray-200 dark:border-gray-700 rounded-lg shadow-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition flex items-center gap-2">
          <v-icon name="md-add" class="w-4 h-4"/> Categoría
        </button>
        <button @click="openArticleModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg shadow-sm font-medium hover:bg-indigo-700 transition flex items-center gap-2">
          <v-icon name="md-add" class="w-4 h-4"/> Artículo
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex justify-center py-20">
      <div class="animate-spin rounded-full h-12 w-12 border-4 border-indigo-500 border-t-transparent"></div>
    </div>

    <div v-else-if="!categories.length" class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700">
      <v-icon name="hi-book-open" class="w-16 h-16 mx-auto mb-4 opacity-50" />
      <p class="text-lg">Aún no hay artículos publicados en el Centro de Ayuda.</p>
    </div>

    <div v-else class="flex gap-6">

      <!-- ── Índice ────────────────────────────────────────────────────── -->
      <aside class="hidden lg:block w-72 shrink-0">
        <nav class="sticky top-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-3 max-h-[calc(100vh-7rem)] overflow-y-auto custom-scrollbar">

          <div class="px-1 pb-2">
            <div class="relative">
              <v-icon name="md-search" class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
              <input
                v-model="search"
                type="search"
                placeholder="Buscar en la ayuda…"
                class="w-full pl-8 pr-3 py-1.5 text-sm rounded-lg border border-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500"
              />
            </div>
          </div>

          <div v-for="category in filteredCategories" :key="category.id" class="mb-1">
            <div class="group/cat flex items-center justify-between px-3 pt-3 pb-1.5">
              <p class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold flex items-center gap-1.5 min-w-0">
                <v-icon :name="category.icon || 'hi-folder'" class="w-3.5 h-3.5 shrink-0" />
                <span class="truncate">{{ category.name }}</span>
              </p>
              <div v-if="isSuperadmin" class="flex gap-0.5 opacity-0 group-hover/cat:opacity-100 transition-opacity shrink-0">
                <button @click="openCategoryModal(category)" class="p-1 text-gray-400 hover:text-indigo-600 rounded" title="Editar categoría">
                  <v-icon name="md-edit" class="w-3 h-3"/>
                </button>
                <button @click="deleteCategory(category.id)" class="p-1 text-gray-400 hover:text-red-600 rounded" title="Eliminar categoría">
                  <v-icon name="md-delete" class="w-3 h-3"/>
                </button>
              </div>
            </div>

            <p v-if="!category.articles.length" class="px-3 py-1.5 text-xs text-gray-400 dark:text-gray-500 italic">
              Sin artículos.
            </p>

            <button
              v-for="item in category.articles"
              :key="item.id"
              @click="openItem(item)"
              class="w-full flex items-center gap-2.5 px-3 py-1.5 text-sm rounded-lg text-left transition"
              :class="selectedItem?.id === item.id
                ? 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 font-medium'
                : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'"
            >
              <span
                class="w-1.5 h-1.5 rounded-full shrink-0"
                :class="!item.is_published ? 'bg-orange-400'
                  : selectedItem?.id === item.id ? 'bg-indigo-500' : 'bg-gray-300 dark:bg-gray-600'"
                :title="!item.is_published ? 'Borrador' : ''"
              ></span>
              <span class="truncate">{{ item.title }}</span>
            </button>
          </div>

          <p v-if="!filteredCategories.length" class="px-3 py-4 text-sm text-gray-400 dark:text-gray-500">
            Sin resultados para «{{ search }}».
          </p>
        </nav>
      </aside>

      <!-- ── Lectura ───────────────────────────────────────────────────── -->
      <div class="flex-1 min-w-0 space-y-4">

        <!-- Selector en móvil: el índice lateral no cabe, pero navegar sí hace falta. -->
        <div class="lg:hidden bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-3">
          <select
            :value="selectedItem?.id || ''"
            @change="openItemById($event.target.value)"
            class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white focus:ring-indigo-500 focus:border-indigo-500"
          >
            <option value="">Elige un artículo…</option>
            <optgroup v-for="category in categories" :key="category.id" :label="category.name">
              <option v-for="item in category.articles" :key="item.id" :value="item.id">{{ item.title }}</option>
            </optgroup>
          </select>
        </div>

        <!-- Portada: qué hay aquí, sin obligar a abrir un artículo para verlo. -->
        <div v-if="!selectedItem" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
          <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">¿Qué necesitas hacer?</h2>
          <p class="text-gray-600 dark:text-gray-400 text-sm mb-5">
            {{ totalArticles }} artículos en {{ categories.length }} categorías. Elige un tema del
            índice o entra por aquí.
          </p>

          <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
            <button
              v-for="category in categories"
              :key="category.id"
              @click="openFirstOf(category)"
              :disabled="!category.articles.length"
              class="text-left p-4 rounded-lg border border-indigo-100 dark:border-indigo-900/40 bg-indigo-50/50 dark:bg-indigo-900/10 hover:border-indigo-300 dark:hover:border-indigo-700 transition disabled:opacity-50 disabled:cursor-default"
            >
              <div class="flex items-center gap-2 text-indigo-700 dark:text-indigo-300 font-semibold text-sm mb-1">
                <v-icon :name="category.icon || 'hi-folder'" class="w-4 h-4 shrink-0" />
                <span class="truncate">{{ category.name }}</span>
              </div>
              <p class="text-xs text-gray-600 dark:text-gray-400">
                {{ category.articles.length }} {{ category.articles.length === 1 ? 'artículo' : 'artículos' }}
              </p>
            </button>
          </div>
        </div>

        <!-- Artículo -->
        <article v-else class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
          <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between items-start gap-3">
            <div class="min-w-0">
              <h2 class="text-2xl font-bold text-gray-900 dark:text-white">{{ selectedItem.title }}</h2>
              <div class="flex flex-wrap items-center gap-2 mt-2">
                <span class="text-xs font-semibold px-2 py-1 bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 rounded-md">
                  {{ getCategoryName(selectedItem.category_id) }}
                </span>
                <span v-if="isSuperadmin && !selectedItem.is_published" class="text-xs font-semibold px-2 py-1 bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300 rounded-md">
                  Borrador oculto
                </span>
              </div>
            </div>

            <div class="flex gap-1 shrink-0">
              <button v-if="isSuperadmin" @click="openArticleModal(selectedItem)" class="p-2 text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded-lg transition" title="Editar artículo">
                <v-icon name="md-edit" class="w-4 h-4"/>
              </button>
              <button v-if="isSuperadmin" @click="deleteArticle(selectedItem.id)" class="p-2 text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition" title="Eliminar artículo">
                <v-icon name="md-delete" class="w-4 h-4"/>
              </button>
              <button @click="closeItem" class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition" title="Volver al índice">
                <v-icon name="io-close" class="w-5 h-5"/>
              </button>
            </div>
          </div>

          <div class="p-6 sm:p-8">
            <div class="prose prose-indigo dark:prose-invert max-w-none prose-img:rounded-xl prose-img:shadow-md ql-editor" v-html="selectedItem.content"></div>

            <div v-if="selectedItem.tips" class="mt-8 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-5 rounded-2xl flex gap-4">
              <div class="bg-amber-100 dark:bg-amber-900/50 p-2 rounded-xl h-fit">
                <v-icon name="hi-light-bulb" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
              </div>
              <div>
                <h4 class="font-semibold text-amber-900 dark:text-amber-200 mb-1">Tip útil</h4>
                <p class="text-sm text-amber-800 dark:text-amber-300/80 leading-relaxed m-0">{{ selectedItem.tips }}</p>
              </div>
            </div>
          </div>

          <!-- Seguir leyendo: sin esto hay que volver al índice tras cada artículo. -->
          <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-900/30 flex justify-between gap-3">
            <button
              v-if="neighbours.prev"
              @click="openItem(neighbours.prev)"
              class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition min-w-0"
            >
              <v-icon name="hi-chevron-left" class="w-4 h-4 shrink-0" />
              <span class="truncate">{{ neighbours.prev.title }}</span>
            </button>
            <span v-else></span>

            <button
              v-if="neighbours.next"
              @click="openItem(neighbours.next)"
              class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition min-w-0 text-right"
            >
              <span class="truncate">{{ neighbours.next.title }}</span>
              <v-icon name="hi-chevron-right" class="w-4 h-4 shrink-0" />
            </button>
          </div>
        </article>
      </div>
    </div>

    <!-- Category Modal (Superadmin) -->
    <div v-if="isEditingCategory" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl max-w-md w-full overflow-hidden">
            <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
                <h3 class="text-xl font-bold dark:text-white">{{ editingCategory.id ? 'Editar Categoría' : 'Nueva Categoría' }}</h3>
                <button @click="isEditingCategory = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-white"><v-icon name="io-close" class="w-6 h-6"/></button>
            </div>
            <form @submit.prevent="saveCategory" class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nombre de Categoría</label>
                    <input v-model="editingCategory.name" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2 focus:ring-indigo-500 focus:border-indigo-500"/>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Ícono (OhVueIcons name)</label>
                    <input v-model="editingCategory.icon" placeholder="ej. bi-people-fill" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2 focus:ring-indigo-500 focus:border-indigo-500"/>
                    <p class="text-xs text-gray-500 mt-1">Busca nombres de íconos en oh-vue-icons.js.org</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Orden (Opcional)</label>
                    <input v-model="editingCategory.display_order" type="number" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2 focus:ring-indigo-500 focus:border-indigo-500"/>
                </div>
                <div class="pt-4 flex justify-end gap-2">
                    <button type="button" @click="isEditingCategory = false" class="px-4 py-2 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-xl font-medium transition">Cancelar</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 font-medium transition shadow-sm">{{ saving ? 'Guardando...' : 'Guardar' }}</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Article Modal (Superadmin) -->
    <div v-if="isEditingArticle" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-xl max-w-4xl w-full h-[90vh] flex flex-col overflow-hidden">
            <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center shrink-0">
                <h3 class="text-xl font-bold dark:text-white">{{ editingArticle.id ? 'Editar Artículo' : 'Nuevo Artículo' }}</h3>
                <button @click="isEditingArticle = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-white"><v-icon name="io-close" class="w-6 h-6"/></button>
            </div>

            <form @submit.prevent="saveArticle" class="flex-1 overflow-y-auto p-6 flex flex-col gap-5 custom-scrollbar">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Título</label>
                        <input v-model="editingArticle.title" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2 focus:ring-indigo-500 focus:border-indigo-500"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Categoría</label>
                        <select v-model="editingArticle.category_id" required class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2 focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Selecciona una categoría</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                        </select>
                    </div>
                </div>

                <div class="flex-1 flex flex-col min-h-[300px]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Contenido (Documentación)</label>
                    <!-- Vue Quill Editor -->
                    <QuillEditor
                        v-model:content="editingArticle.content"
                        contentType="html"
                        toolbar="full"
                        theme="snow"
                        class="bg-white dark:bg-gray-900 dark:text-white rounded-b-xl flex-1"
                    />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tip Útil / Consejo (Opcional)</label>
                    <textarea v-model="editingArticle.tips" rows="2" class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                </div>

                <div class="flex items-center gap-6 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" v-model="editingArticle.is_published" class="w-5 h-5 text-indigo-600 rounded focus:ring-indigo-500"/>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Publicar para clientes</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Orden:</span>
                        <input type="number" v-model="editingArticle.display_order" class="w-20 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-2 py-1 focus:ring-indigo-500 focus:border-indigo-500"/>
                    </label>
                </div>
            </form>

            <div class="p-6 border-t border-gray-100 dark:border-gray-700 flex justify-end gap-3 bg-gray-50 dark:bg-gray-900/50 shrink-0">
                <button @click="isEditingArticle = false" class="px-5 py-2.5 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 rounded-xl font-medium transition">Cancelar</button>
                <button @click="saveArticle" :disabled="saving" class="px-5 py-2.5 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 font-medium transition shadow-md disabled:opacity-50 flex items-center gap-2">
                    <v-icon v-if="saving" name="bi-arrow-repeat" class="w-4 h-4 animate-spin"/>
                    {{ saving ? 'Guardando...' : 'Guardar Artículo' }}
                </button>
            </div>
        </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';
import api from '../services/api';

const categories = ref([]);
const loading = ref(true);
const saving = ref(false);
const search = ref('');

const selectedItem = ref(null);
const isEditingCategory = ref(false);
const isEditingArticle = ref(false);

const editingCategory = ref({});
const editingArticle = ref({});

// Authorization Check
const currentUser = JSON.parse(localStorage.getItem('userData') || sessionStorage.getItem('userData') || '{}');
const isSuperadmin = computed(() => {
    return currentUser && currentUser.is_superadmin === true || currentUser.is_superadmin === 1;
});

const totalArticles = computed(() =>
    categories.value.reduce((n, c) => n + (c.articles?.length || 0), 0)
);

/**
 * El buscador filtra por título de artículo Y por nombre de categoría: quien
 * escribe "facturación" no siempre busca un artículo que se llame así, sino la
 * sección entera. Una categoría que coincide conserva todos sus artículos; las
 * que quedan sin resultados desaparecen para no dejar encabezados huérfanos.
 */
const filteredCategories = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return categories.value;

    return categories.value
        .map(c => {
            if (c.name.toLowerCase().includes(q)) return c;
            return { ...c, articles: (c.articles || []).filter(a => a.title.toLowerCase().includes(q)) };
        })
        .filter(c => (c.articles || []).length > 0);
});

/** Todos los artículos en el orden en que aparecen en el índice. */
const flatArticles = computed(() =>
    categories.value.flatMap(c => c.articles || [])
);

/** Artículo anterior y siguiente, cruzando categorías. */
const neighbours = computed(() => {
    if (!selectedItem.value) return { prev: null, next: null };

    const list = flatArticles.value;
    const i = list.findIndex(a => a.id === selectedItem.value.id);

    return {
        prev: i > 0 ? list[i - 1] : null,
        next: i >= 0 && i < list.length - 1 ? list[i + 1] : null,
    };
});

const loadData = async () => {
    loading.value = true;
    try {
        const response = await api.helpCenter.getAll();
        categories.value = response.data;

        // Enlace directo a un artículo (#articulo-12). Sin esto no hay forma de
        // pasarle a alguien "lee esto": el manual siempre abre en la portada.
        const fromHash = parseInt((window.location.hash.match(/^#articulo-(\d+)$/) || [])[1], 10);
        if (fromHash) openItemById(fromHash, false);
    } catch (e) {
        console.error("Error loading help center", e);
    } finally {
        loading.value = false;
    }
};

const getCategoryName = (id) => {
    const cat = categories.value.find(c => c.id === id);
    return cat ? cat.name : '';
};

const openItem = (item, scroll = true) => {
    selectedItem.value = item;

    // replaceState y no push: el manual no debe llenar el historial del
    // navegador con un paso por cada artículo leído.
    if (item) window.history.replaceState(null, '', `#articulo-${item.id}`);
    if (scroll) window.scrollTo({ top: 0, behavior: 'smooth' });
};

const openItemById = (id, scroll = true) => {
    const item = flatArticles.value.find(a => String(a.id) === String(id));
    if (item) openItem(item, scroll);
    else closeItem();
};

const openFirstOf = (category) => {
    if (category.articles?.length) openItem(category.articles[0]);
};

const closeItem = () => {
    selectedItem.value = null;
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
};

// --- Superadmin Category Methods ---
const openCategoryModal = (category = null) => {
    if (category) {
        editingCategory.value = { ...category };
    } else {
        editingCategory.value = { name: '', icon: '', description: '', display_order: 0 };
    }
    isEditingCategory.value = true;
};

const saveCategory = async () => {
    saving.value = true;
    try {
        if (editingCategory.value.id) {
            await api.helpCenter.updateCategory(editingCategory.value.id, editingCategory.value);
        } else {
            await api.helpCenter.createCategory(editingCategory.value);
        }
        isEditingCategory.value = false;
        await loadData();
    } catch(e) {
        alert("Error al guardar categoría");
    } finally {
        saving.value = false;
    }
};

const deleteCategory = async (id) => {
    if(!confirm("¿Eliminar categoría y todos sus artículos?")) return;
    try {
        await api.helpCenter.deleteCategory(id);
        // El artículo abierto puede haber sido de esa categoría: se cierra para
        // no quedar leyendo algo que ya no existe.
        closeItem();
        await loadData();
    } catch(e) {
        alert("Error al eliminar");
    }
};

// --- Superadmin Article Methods ---
const openArticleModal = (article = null) => {
    if (article) {
        editingArticle.value = { ...article };
    } else {
        editingArticle.value = {
            category_id: categories.value.length ? categories.value[0].id : '',
            title: '',
            content: '',
            tips: '',
            is_published: true,
            display_order: 0
        };
    }
    isEditingArticle.value = true;
};

const saveArticle = async () => {
    if(!editingArticle.value.title || !editingArticle.value.category_id) {
        alert("Por favor completa el título y la categoría");
        return;
    }

    saving.value = true;
    const editedId = editingArticle.value.id;

    try {
        if (editedId) {
            await api.helpCenter.updateArticle(editedId, editingArticle.value);
        } else {
            await api.helpCenter.createArticle(editingArticle.value);
        }
        isEditingArticle.value = false;
        await loadData();

        // Tras guardar, la lista se recarga y el objeto abierto queda obsoleto:
        // se vuelve a resolver por id para que el panel muestre lo recién
        // guardado y no la versión anterior.
        if (editedId) openItemById(editedId, false);
    } catch(e) {
        alert("Error al guardar artículo");
    } finally {
        saving.value = false;
    }
};

const deleteArticle = async (id) => {
    if(!confirm("¿Eliminar artículo permanentemente?")) return;
    try {
        await api.helpCenter.deleteArticle(id);
        if (selectedItem.value?.id === id) closeItem();
        await loadData();
    } catch(e) {
        alert("Error al eliminar");
    }
};

onMounted(() => {
    loadData();
});
</script>

<style>
/* Adjust Quill Toolbar for Dark Mode and General Styling */
.ql-toolbar.ql-snow {
    border-top-left-radius: 0.75rem;
    border-top-right-radius: 0.75rem;
    border-color: #e5e7eb;
    background-color: #f9fafb;
    font-family: inherit;
}
.ql-container.ql-snow {
    border-bottom-left-radius: 0.75rem;
    border-bottom-right-radius: 0.75rem;
    border-color: #e5e7eb;
    font-family: inherit;
    font-size: 0.95rem;
}

.dark .ql-toolbar.ql-snow {
    border-color: #374151; /* gray-700 */
    background-color: #1f2937; /* gray-800 */
}
.dark .ql-container.ql-snow {
    border-color: #374151;
}
.dark .ql-toolbar.ql-snow .ql-stroke {
    stroke: #d1d5db; /* gray-300 */
}
.dark .ql-toolbar.ql-snow .ql-fill {
    fill: #d1d5db;
}
.dark .ql-toolbar.ql-snow .ql-picker {
    color: #d1d5db;
}

/* Fix for Dark Mode in Article Content */
.dark .ql-editor {
    color: #f3f4f6 !important; /* gray-100 */
}

/* Ensure common elements inside editor also respect dark mode */
.dark .ql-editor p,
.dark .ql-editor span,
.dark .ql-editor li,
.dark .ql-editor h1,
.dark .ql-editor h2,
.dark .ql-editor h3,
.dark .ql-editor h4,
.dark .ql-editor h5,
.dark .ql-editor h6 {
    color: inherit !important;
}

/* Handle links in dark mode within the editor */
.dark .ql-editor a {
    color: #818cf8 !important; /* indigo-400 */
    text-decoration: underline;
}

/* Ensure images have some breathing room in dark mode */
.dark .ql-editor img {
    border: 1px solid #374151; /* gray-700 */
}

/*
 * El artículo se renderiza con la clase `ql-editor` para heredar los estilos
 * del editor, pero fuera del editor esa clase trae su propio padding y un alto
 * mínimo de página en blanco. Aquí se anulan: el contenedor ya pone el suyo.
 */
.prose.ql-editor {
    padding: 0;
    min-height: 0;
}

.custom-scrollbar::-webkit-scrollbar {
  width: 8px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: rgba(156, 163, 175, 0.5);
  border-radius: 20px;
}
.dark .custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: rgba(75, 85, 99, 0.5);
}
</style>
