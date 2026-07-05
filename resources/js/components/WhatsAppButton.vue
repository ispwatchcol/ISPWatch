<template>
    <div>
        <!-- Floating WhatsApp Button - bottom-left so it never overlaps the
             right-aligned pagination / action buttons. On desktop we clear the
             264px sidebar (md:left-72). z-40 keeps it below modals (z-50). -->
        <button
            @click="openModal"
            class="fixed bottom-6 left-6 md:bottom-8 md:left-72 z-40 w-14 h-14 bg-gradient-to-br from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 rounded-full shadow-lg hover:shadow-2xl flex items-center justify-center transition-all duration-300 hover:scale-110 group"
            aria-label="Contactar por WhatsApp"
        >
            <v-icon name="fa-whatsapp" class="w-8 h-8 text-white" />
            
            <!-- Pulse animation ring -->
            <span class="absolute inset-0 rounded-full bg-green-500 opacity-75 animate-ping"></span>
        </button>

        <!-- Modal -->
        <Transition name="modal">
            <div
                v-if="isModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
                @click.self="closeModal"
            >
                <div
                    class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-lg w-full p-6 transform transition-all max-h-[90vh] overflow-y-auto"
                    @click.stop
                >
                    <!-- Modal Header -->
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center">
                                <v-icon name="fa-whatsapp" class="w-6 h-6 text-white" />
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                                    Contacto por WhatsApp
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Cuéntanos cómo podemos ayudarte
                                </p>
                            </div>
                        </div>
                        <button
                            @click="closeModal"
                            class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition-colors"
                        >
                            <v-icon name="md-close" class="w-5 h-5" />
                        </button>
                    </div>

                    <!-- Phone Number Display -->
                    <div class="mb-6 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Número de contacto:
                        </p>
                        <p class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                            <v-icon name="fa-whatsapp" class="w-4 h-4 text-green-600 dark:text-green-400" />
                            +57 {{ formattedPhone }}
                        </p>
                    </div>

                    <!-- Form Fields -->
                    <div class="space-y-4 mb-6">
                        <!-- Name -->
                        <div>
                            <label
                                for="whatsapp-name"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
                            >
                                Tu nombre <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="whatsapp-name"
                                v-model="name"
                                type="text"
                                placeholder="Ej: Juan Pérez"
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 transition-all"
                            />
                        </div>

                        <!-- Company -->
                        <div>
                            <label
                                for="whatsapp-company"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
                            >
                                Empresa <span class="text-red-500">*</span>
                            </label>
                            <input
                                id="whatsapp-company"
                                v-model="company"
                                type="text"
                                placeholder="Ej: Mi Empresa ISP"
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 transition-all"
                            />
                        </div>

                        <!-- Issue/Question -->
                        <div>
                            <label
                                for="whatsapp-issue"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2"
                            >
                                ¿Qué inconveniente tienes? <span class="text-red-500">*</span>
                            </label>
                            <textarea
                                id="whatsapp-issue"
                                v-model="issue"
                                rows="4"
                                placeholder="Describe el inconveniente o duda que tienes..."
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 resize-none transition-all"
                            ></textarea>
                        </div>
                    </div>

                    <!-- Preview Message -->
                    <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800 rounded-lg">
                        <p class="text-xs font-semibold text-green-700 dark:text-green-400 mb-2">
                            Vista previa del mensaje:
                        </p>
                        <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line">
                            {{ previewMessage }}
                        </p>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex gap-3">
                        <button
                            @click="closeModal"
                            class="flex-1 px-4 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors font-medium"
                        >
                            Cancelar
                        </button>
                        <button
                            @click="sendMessage"
                            :disabled="!isFormValid"
                            class="flex-1 px-4 py-2.5 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white rounded-lg font-medium transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:from-green-500 disabled:hover:to-green-600 flex items-center justify-center gap-2"
                        >
                            <v-icon name="fa-whatsapp" class="w-4 h-4" />
                            Enviar mensaje
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const isModalOpen = ref(false);
const name = ref('');
const company = ref('');
const issue = ref('');
const phoneNumber = '573125759381'; // Colombia country code + number

const formattedPhone = computed(() => {
    const number = '3125759381';
    return `${number.slice(0, 3)} ${number.slice(3)}`;
});

const isFormValid = computed(() => {
    return name.value.trim() && company.value.trim() && issue.value.trim();
});

const previewMessage = computed(() => {
    const nameText = name.value.trim() || '[Tu nombre]';
    const companyText = company.value.trim() || '[Tu empresa]';
    const issueText = issue.value.trim() || '[Descripción del inconveniente]';
    
    return `Hola, soy ${nameText} de ${companyText}.
Necesito apoyo con el siguiente inconveniente: ${issueText}.
Gracias por la atención.`;
});

const openModal = () => {
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
};

const sendMessage = () => {
    if (!isFormValid.value) return;
    
    // Generate the message from template
    const message = `Hola, soy ${name.value.trim()} de ${company.value.trim()}.
Necesito apoyo con el siguiente inconveniente: ${issue.value.trim()}.
Gracias por la atención.`;
    
    // Encode message for URL
    const encodedMessage = encodeURIComponent(message);
    
    // Create WhatsApp URL
    const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodedMessage}`;
    
    // Open in new tab
    window.open(whatsappUrl, '_blank');
    
    // Close modal and reset fields
    closeModal();
    name.value = '';
    company.value = '';
    issue.value = '';
};

// Close modal on Escape key
const handleEscape = (e) => {
    if (e.key === 'Escape' && isModalOpen.value) {
        closeModal();
    }
};

// Add escape key listener
if (typeof window !== 'undefined') {
    window.addEventListener('keydown', handleEscape);
}
</script>

<style scoped>
/* Modal transition animations */
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.3s ease;
}

.modal-enter-active > div,
.modal-leave-active > div {
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

.modal-enter-from > div,
.modal-leave-to > div {
    transform: scale(0.9);
    opacity: 0;
}

/* Ping animation for the floating button */
@keyframes ping {
    75%, 100% {
        transform: scale(1.5);
        opacity: 0;
    }
}

.animate-ping {
    animation: ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
}
</style>
