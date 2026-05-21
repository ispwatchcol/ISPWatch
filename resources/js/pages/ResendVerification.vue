<template>
  <div
    class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-4 relative overflow-hidden"
  >
    <!-- Background Decorative Elements -->
    <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-blue-400/20 dark:bg-blue-600/10 blur-[120px] rounded-full animate-pulse"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-indigo-400/20 dark:bg-indigo-600/10 blur-[120px] rounded-full animate-pulse" style="animation-delay: 2s"></div>

    <div class="relative z-10 w-full max-w-md py-8 md:py-12">
      <!-- App Branding -->
      <div class="flex flex-col items-center mb-6 md:mb-8 animate-fade-in-down">
        <div class="bg-white dark:bg-slate-900 p-3 md:p-4 rounded-3xl shadow-xl mb-4 border border-slate-100 dark:border-slate-800 group transition-all hover:scale-110">
          <img
            src="../assets/favicon.svg"
            alt="Logo"
            class="h-14 w-14 md:h-16 md:w-16 group-hover:rotate-12 transition-transform duration-500"
          />
        </div>
        <h1 class="text-2xl md:text-3xl font-black text-slate-900 dark:text-white tracking-tight">ISP<span class="text-blue-600">Watch</span></h1>
        <p class="text-slate-500 dark:text-slate-400 text-xs md:text-sm font-medium mt-1">Gestión de ISP Inteligente</p>
      </div>

      <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-2xl shadow-2xl rounded-[2rem] md:rounded-[2.5rem] p-6 md:p-10 border border-white/20 dark:border-slate-800">
        <!-- Título -->
        <div class="mb-8">
          <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Verificar Email</h2>
          <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">
            Reenvía el enlace de verificación a tu correo
          </p>
        </div>

        <!-- Info Badge -->
        <div class="mb-6 p-4 bg-blue-50/80 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-900/30 rounded-2xl">
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/40 rounded-xl flex items-center justify-center flex-shrink-0 mt-0.5">
              <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
              </svg>
            </div>
            <div>
              <p class="text-sm font-semibold text-blue-800 dark:text-blue-300">Tu cuenta ya existe</p>
              <p class="text-xs text-blue-600 dark:text-blue-400 mt-0.5">
                Solo necesitas verificar tu correo electrónico para poder iniciar sesión
              </p>
            </div>
          </div>
        </div>

        <!-- Mensaje de error -->
        <div
          v-if="errorMessage"
          class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-900/30 rounded-2xl text-red-600 dark:text-red-400 text-center text-sm font-medium animate-shake"
        >
          <div class="flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            {{ errorMessage }}
          </div>
        </div>

        <!-- Mensaje de éxito -->
        <div
          v-if="successMessage"
          class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-900/30 rounded-2xl text-green-700 dark:text-green-400 text-sm font-medium"
        >
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            {{ successMessage }}
          </div>
        </div>

        <!-- Formulario -->
        <form @submit.prevent="handleResend" class="space-y-6">
          <!-- Correo electrónico -->
          <div class="space-y-1.5">
            <label
              for="email"
              class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1"
            >Correo electrónico</label>
            <div class="relative group">
              <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 group-focus-within:text-blue-500 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
              </div>
              <input
                type="email"
                id="email"
                v-model="email"
                placeholder="you@example.com"
                autocomplete="email"
                class="w-full pl-11 pr-4 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
                required
                maxlength="255"
              />
            </div>
          </div>

          <!-- Botón de reenvío -->
          <button
            type="submit"
            :disabled="loading"
            class="group relative w-full overflow-hidden rounded-2xl bg-blue-600 px-6 py-4 font-bold text-white shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 hover:scale-[1.02] active:scale-[0.98] disabled:bg-slate-400 disabled:shadow-none disabled:scale-100 disabled:cursor-not-allowed"
          >
            <div class="relative z-10 flex items-center justify-center gap-2">
              <span v-if="loading" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              {{ loading ? "ENVIANDO..." : "REENVIAR ENLACE DE VERIFICACIÓN" }}
              <svg v-if="!loading" class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
              </svg>
            </div>
          </button>
        </form>

        <!-- Separador -->
        <div class="relative my-8">
          <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-slate-200 dark:border-slate-800"></div>
          </div>
          <div class="relative flex justify-center text-[10px] font-black uppercase tracking-widest">
            <span class="bg-white dark:bg-slate-900 px-4 text-slate-400">opciones</span>
          </div>
        </div>

        <!-- Botón para ir a Login -->
        <button
          @click="$router.push('/')"
          class="w-full group px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-800 text-slate-700 dark:text-slate-300 font-bold transition-all hover:bg-slate-50 dark:hover:bg-slate-800 hover:border-slate-200 dark:hover:border-slate-700 hover:scale-[1.02] active:scale-[0.98] flex items-center justify-center gap-2"
        >
          <svg class="w-5 h-5 text-slate-400 group-hover:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
          </svg>
          YA VERIFIQUÉ MI CORREO - INICIAR SESIÓN
        </button>

        <!-- Botón para registrar otro correo -->
        <button
          @click="$router.push('/register')"
          class="w-full mt-3 text-slate-500 dark:text-slate-400 py-2 text-sm font-medium hover:text-blue-600 dark:hover:text-blue-400 transition-colors"
        >
          Usar otro correo electrónico
        </button>
      </div>

      <!-- Footer info -->
      <p class="text-center text-slate-400 dark:text-slate-500 text-xs mt-8 font-medium">
        &copy; 2026 ISPWatch Cloud. Todos los derechos reservados.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import api from '../services/api';

const router = useRouter();
const route = useRoute();

const email = ref('');
const loading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

onMounted(() => {
  if (route.query.email) {
    email.value = route.query.email;
  }
});

const handleResend = async () => {
  loading.value = true;
  errorMessage.value = '';
  successMessage.value = '';

  if (!email.value) {
    errorMessage.value = 'Por favor ingresa tu correo electrónico.';
    loading.value = false;
    return;
  }

  try {
    const response = await api.auth.resendVerification(email.value);

    if (response.data.success) {
      successMessage.value = response.data.message || '¡Enlace de verificación reenviado! Revisa tu bandeja de entrada.';
      setTimeout(() => {
        successMessage.value = '';
      }, 5000);
    } else {
      errorMessage.value = response.data.message || 'Error al reenviar el enlace.';
    }
  } catch (error) {
    console.error('Resend verification error:', error);
    if (error.response?.data?.message) {
      errorMessage.value = error.response.data.message;
    } else {
      errorMessage.value = 'Error de conexión. Inténtalo de nuevo.';
    }
  } finally {
    loading.value = false;
  }
};
</script>

<style scoped>
* {
  transition: background-color 0.3s, border-color 0.3s, color 0.3s;
}

@keyframes fade-in-down {
  0% { opacity: 0; transform: translateY(-20px); }
  100% { opacity: 1; transform: translateY(0); }
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
  20%, 40%, 60%, 80% { transform: translateX(4px); }
}

.animate-fade-in-down {
  animation: fade-in-down 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

.animate-shake {
  animation: shake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
}
</style>
