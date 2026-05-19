<template>
  <div class="min-h-screen bg-gray-100 dark:bg-gray-900 p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
      <div>
        <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
          Centro de Ayuda
        </h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Documentación oficial y guías de uso del sistema</p>
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

    <!-- Content Grid -->
    <div v-if="loading" class="flex justify-center py-20">
      <div class="animate-spin rounded-full h-12 w-12 border-4 border-indigo-500 border-t-transparent"></div>
    </div>
    
    <div v-else class="mx-auto w-full">
      <div v-if="categories.length === 0" class="text-center py-20 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700">
        <v-icon name="hi-book-open" class="w-16 h-16 mx-auto mb-4 opacity-50" />
        <p class="text-lg">Aún no hay artículos publicados en el Centro de Ayuda.</p>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 items-start">
        <!-- Category Card -->
        <div 
          v-for="category in categories" 
          :key="category.id" 
          class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden"
        >
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
               <v-icon v-if="category.icon" :name="category.icon" class="text-indigo-600 dark:text-indigo-400 w-5 h-5" />
               <v-icon v-else name="hi-folder" class="text-indigo-600 dark:text-indigo-400 w-5 h-5" />
              <h3 class="font-semibold text-gray-900 dark:text-white">{{ category.name }}</h3>
            </div>
            
            <div v-if="isSuperadmin" class="flex gap-1">
              <button @click.stop="openCategoryModal(category)" class="p-1.5 text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 rounded-lg transition-colors" title="Editar Categoría">
                <v-icon name="md-edit" class="w-4 h-4"/>
              </button>
              <button @click.stop="deleteCategory(category.id)" class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30 rounded-lg transition-colors" title="Eliminar Categoría">
                <v-icon name="md-delete" class="w-4 h-4"/>
              </button>
            </div>
          </div>
          
          <ul class="py-2">
            <li v-if="!category.articles.length" class="px-5 py-6 text-sm text-center text-gray-400 dark:text-gray-500 italic">
              No hay artículos en esta categoría.
            </li>
            <li 
              v-for="item in category.articles" 
              :key="item.id"
              class="group flex justify-between items-center px-1"
            >
              <button 
                @click="openItem(item)"
                class="flex-1 text-left px-4 py-2.5 mx-2 my-0.5 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-gray-700 hover:text-indigo-700 dark:hover:text-indigo-300 text-sm font-medium transition-colors flex justify-between items-center"
              >
                <div class="flex items-center gap-2">
                  <span v-if="!item.is_published" class="w-2 h-2 rounded-full bg-orange-400" title="Borrador"></span>
                  {{ item.title }}
                </div>
                <v-icon name="hi-chevron-right" class="text-gray-300 dark:text-gray-600 w-4 h-4 group-hover:text-indigo-400 transition-colors" />
              </button>
              
              <div v-if="isSuperadmin" class="pr-3 opacity-0 group-hover:opacity-100 transition-opacity flex gap-1">
                 <button @click.stop="openArticleModal(item)" class="p-1.5 text-gray-400 hover:text-indigo-600 rounded">
                    <v-icon name="md-edit" class="w-3 h-3"/>
                 </button>
                 <button @click.stop="deleteArticle(item.id)" class="p-1.5 text-gray-400 hover:text-red-600 rounded">
                    <v-icon name="md-delete" class="w-3 h-3"/>
                 </button>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Article Viewing Modal -->
    <div 
      v-if="selectedItem && !isEditingCategory && !isEditingArticle" 
      class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/60 backdrop-blur-sm"
      @click="closeModal"
    >
      <div 
        class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl max-w-4xl w-full h-[90vh] flex flex-col overflow-hidden animate-in fade-in zoom-in duration-200"
        @click.stop
      >
        <div class="p-6 sm:px-8 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50 flex-shrink-0">
          <div>
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white">{{ selectedItem.title }}</h3>
            <div class="flex items-center gap-2 mt-2">
              <span class="text-xs font-semibold px-2 py-1 bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300 rounded-md">
                {{ getCategoryName(selectedItem.category_id) }}
              </span>
              <span v-if="isSuperadmin && !selectedItem.is_published" class="text-xs font-semibold px-2 py-1 bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300 rounded-md">
                Borrador Oculto
              </span>
            </div>
          </div>
          <button 
            @click="closeModal"
            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700 transition"
          >
            <v-icon name="io-close" class="w-6 h-6" />
          </button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 sm:p-8 custom-scrollbar">
           <!-- Quill HTML Rendering -->
           <div class="prose prose-indigo dark:prose-invert max-w-none prose-img:rounded-xl prose-img:shadow-md ql-editor" v-html="selectedItem.content"></div>
           
           <div v-if="selectedItem.tips" class="mt-10 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-5 rounded-2xl flex gap-4">
               <div class="bg-amber-100 dark:bg-amber-900/50 p-2 rounded-xl h-fit">
                   <v-icon name="hi-light-bulb" class="w-6 h-6 text-amber-600 dark:text-amber-400" />
               </div>
               <div>
                   <h4 class="font-semibold text-amber-900 dark:text-amber-200 mb-1">Tip Útil</h4>
                   <p class="text-sm text-amber-800 dark:text-amber-300/80 leading-relaxed m-0">{{ selectedItem.tips }}</p>
               </div>
           </div>
        </div>
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

const loadData = async () => {
    loading.value = true;
    try {
        const response = await api.helpCenter.getAll();
        categories.value = response.data;
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

const openItem = (item) => {
  selectedItem.value = item;
};

const closeModal = () => {
  selectedItem.value = null;
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
        loadData();
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
        loadData();
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
    try {
        if (editingArticle.value.id) {
            await api.helpCenter.updateArticle(editingArticle.value.id, editingArticle.value);
        } else {
            await api.helpCenter.createArticle(editingArticle.value);
        }
        isEditingArticle.value = false;
        loadData();
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
        loadData();
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