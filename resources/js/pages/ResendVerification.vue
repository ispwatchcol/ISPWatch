<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-blue-100 px-4">
    <div class="bg-white shadow-2xl rounded-3xl p-10 w-full max-w-md">
      
      <!-- Logo -->
      <div class="flex justify-center mb-6">
        <img src="../assets/Logo.png" alt="Logo" class="h-20 w-20 animate-bounce" />
      </div>

      <!-- Título -->
      <h2 class="text-3xl font-extrabold text-center text-gray-900 mb-2">Verificar Email</h2>
      <p class="text-center text-gray-500 mb-8">
        Reenvía el enlace de verificación a tu correo
      </p>

      <!-- Mensaje de error -->
      <div v-if="errorMessage" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
        {{ errorMessage }}
      </div>

      <!-- Mensaje de éxito -->
      <div v-if="successMessage" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-xl text-green-600 text-sm">
        {{ successMessage }}
      </div>

      <!-- Info Badge -->
      <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-xl text-center">
        <p class="text-sm text-blue-700 mb-2">
          📧 <strong>Tu cuenta ya existe</strong>
        </p>
        <p class="text-xs text-blue-600">
          Solo necesitas verificar tu correo electrónico para poder iniciar sesión
        </p>
      </div>

      <!-- Formulario -->
      <form @submit.prevent="handleResend" class="space-y-5">

        <!-- Correo electrónico -->
        <div>
          <label for="email" class="block text-gray-700 font-medium mb-1">Correo electrónico</label>
          <input
            type="email"
            id="email"
            v-model="email"
            placeholder="you@example.com"
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
            required
            maxlength="255"
          />
        </div>

        <!-- Botón de reenvío -->
        <button
          type="submit"
          :disabled="loading"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-2xl transition duration-300 shadow-lg disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center justify-center gap-2"
        >
          <svg v-if="loading" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          {{ loading ? "Reenviando..." : "Reenviar enlace de verificación" }}
        </button>
      </form>

      <!-- Separador -->
      <div class="flex items-center my-6">
        <hr class="flex-1 border-gray-300" />
        <span class="px-3 text-gray-400 font-medium">o</span>
        <hr class="flex-1 border-gray-300" />
      </div>

      <!-- Botón para ir a Login -->
      <button
        @click="$router.push('/')"
        class="w-full border border-gray-300 text-gray-700 py-3 rounded-2xl hover:bg-gray-50 transition duration-300 font-medium shadow-sm"
      >
        Ya verifiqué mi correo - Iniciar sesión
      </button>

      <!-- Botón para registrar otro correo -->
      <button
        @click="$router.push('/register')"
        class="w-full mt-3 text-gray-600 py-2 rounded-2xl hover:text-blue-600 transition duration-300 text-sm"
      >
        Usar otro correo electrónico
      </button>
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

// Pre-fill email from query parameter if provided
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
      
      // Clear email field after success
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
body {
  font-family: 'Inter', sans-serif;
}
</style>
