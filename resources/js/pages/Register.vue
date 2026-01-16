<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-blue-100 px-4">
    <div class="bg-white shadow-2xl rounded-3xl p-10 w-full max-w-md">
      
      <!-- Logo -->
      <div class="flex justify-center mb-6">
        <img src="../assets/logo.png" alt="Logo" class="h-20 w-20 animate-bounce" />
      </div>

      <!-- Título -->
      <h2 class="text-3xl font-extrabold text-center text-gray-900 mb-2">Crear cuenta</h2>
      <p class="text-center text-gray-500 mb-6">Regístrate para comenzar con tu prueba gratuita</p>

      <!-- Trial Badge -->
      <div class="mb-6 p-3 bg-green-50 border border-green-200 rounded-xl text-center">
        <p class="text-sm text-green-700 font-medium">
          🎉 Plan Trial: <strong>30 clientes gratis</strong>
        </p>
      </div>

      <!-- Mensaje de error -->
      <div v-if="errorMessage" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm">
        {{ errorMessage }}
      </div>

      <!-- Mensaje de éxito -->
      <div v-if="successMessage" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-xl text-green-600 text-sm">
        {{ successMessage }}
      </div>

      <!-- Formulario -->
      <form @submit.prevent="handleRegister" class="space-y-5">

        <!-- Nombre de empresa -->
        <div>
          <label for="company_name" class="block text-gray-700 font-medium mb-1">Nombre de tu empresa</label>
          <input
            type="text"
            id="company_name"
            v-model="form.company_name"
            placeholder="Mi ISP S.A.S."
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
            required
          />
        </div>

        <!-- Nombre completo -->
        <div>
          <label for="name" class="block text-gray-700 font-medium mb-1">Tu nombre completo</label>
          <input
            type="text"
            id="name"
            v-model="form.name"
            placeholder="Juan Pérez"
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
            required
          />
        </div>

        <!-- Correo electrónico -->
        <div>
          <label for="email" class="block text-gray-700 font-medium mb-1">Correo electrónico</label>
          <input
            type="email"
            id="email"
            v-model="form.email"
            placeholder="you@example.com"
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
            required
          />
        </div>

        <!-- Teléfono (opcional) -->
        <div>
          <label for="phone" class="block text-gray-700 font-medium mb-1">Teléfono <span class="text-gray-400">(opcional)</span></label>
          <input
            type="tel"
            id="phone"
            v-model="form.phone"
            placeholder="+57 300 123 4567"
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
          />
        </div>

        <!-- Contraseña -->
        <div>
          <label for="password" class="block text-gray-700 font-medium mb-1">Contraseña</label>
          <input
            type="password"
            id="password"
            v-model="form.password"
            placeholder="Mínimo 6 caracteres"
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
            required
            minlength="6"
          />
        </div>

        <!-- Botón de registro -->
        <button
          type="submit"
          :disabled="loading"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-2xl transition duration-300 shadow-lg disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center justify-center gap-2"
        >
          <svg v-if="loading" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          {{ loading ? "Creando cuenta..." : "Crear cuenta gratis" }}
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
        Ya tengo cuenta - Iniciar sesión
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter } from 'vue-router';
import { apiClient } from '../services/api';

const router = useRouter();

const form = reactive({
  company_name: '',
  name: '',
  email: '',
  phone: '',
  password: '',
});

const loading = ref(false);
const errorMessage = ref('');
const successMessage = ref('');

const handleRegister = async () => {
  loading.value = true;
  errorMessage.value = '';
  successMessage.value = '';

  try {
    const response = await apiClient.post('/register', {
      company_name: form.company_name,
      name: form.name,
      email: form.email,
      phone: form.phone || null,
      password: form.password,
    });

    if (response.data.success) {
      // ✅ Guardar credenciales en localStorage para mostrar en Login
      localStorage.setItem('newAccountCredentials', JSON.stringify({
        email_tenant: response.data.data.email_tenant,
        company_name: response.data.data.company_name,
      }));
      
      successMessage.value = '¡Cuenta creada! Redirigiendo al login...';
      
      // Redirigir al Login después de 1 segundo
      setTimeout(() => {
        router.push('/');
      }, 1000);
    } else {
      errorMessage.value = response.data.message || 'Error al crear la cuenta.';
    }
  } catch (error) {
    console.error('Registration error:', error);
    
    if (error.response?.data?.message) {
      errorMessage.value = error.response.data.message;
    } else if (error.response?.data?.errors) {
      const errors = error.response.data.errors;
      errorMessage.value = Object.values(errors).flat().join(' ');
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
