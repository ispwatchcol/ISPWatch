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
            maxlength="255"
            minlength="2"
            @paste="handlePaste($event, 'company_name')"
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
            maxlength="255"
            minlength="2"
            @paste="handlePaste($event, 'name')"
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
            maxlength="255"
            @paste="handlePaste($event, 'email')"
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
            maxlength="20"
            @paste="handlePaste($event, 'phone')"
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
            maxlength="128"
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

// ===== SECURITY FUNCTIONS =====

/**
 * Sanitize input: remove dangerous characters
 */
const sanitizeInput = (input) => {
  if (typeof input !== 'string') return '';
  
  // Remove HTML tags
  let sanitized = input.replace(/<[^>]*>/g, '');
  
  // Remove control characters
  sanitized = sanitized.replace(/[\x00-\x1F\x7F]/g, '');
  
  // Remove SQL dangerous characters
  sanitized = sanitized.replace(/['";\\]/g, '');
  
  // Remove common injection patterns
  const dangerousPatterns = [
    /--/g,                    // SQL comment
    /\/\*/g,                  // SQL block comment start
    /\*\//g,                  // SQL block comment end
    /xp_/gi,                  // SQL Server extended procedure
    /union\s+select/gi,       // SQL UNION injection
    /select\s+\*/gi,          // SQL SELECT *
    /drop\s+table/gi,         // SQL DROP TABLE
    /insert\s+into/gi,        // SQL INSERT
    /delete\s+from/gi,        // SQL DELETE
    /update\s+\w+\s+set/gi,   // SQL UPDATE
    /<script/gi,              // XSS script tag
    /javascript:/gi,          // XSS javascript protocol
    /on\w+\s*=/gi,            // XSS event handlers
  ];
  
  dangerousPatterns.forEach(pattern => {
    sanitized = sanitized.replace(pattern, '');
  });
  
  return sanitized.trim();
};

/**
 * Sanitize email input
 */
const sanitizeEmail = (email) => {
  if (typeof email !== 'string') return '';
  // Allow only valid email characters
  return email.toLowerCase().replace(/[^a-z0-9@._\-+]/gi, '').trim();
};

/**
 * Sanitize phone input
 */
const sanitizePhone = (phone) => {
  if (typeof phone !== 'string') return '';
  // Allow only numbers, +, -, spaces, and parentheses
  return phone.replace(/[^0-9+\-\s()]/g, '').trim();
};

/**
 * Detect injection patterns
 */
const detectInjectionAttempt = (input) => {
  if (typeof input !== 'string') return false;
  
  const suspiciousPatterns = [
    /<>/,                   // HTML tags
    /['"]/,                 // Quotes (SQL)
    /--/,                   // SQL comment
    /;/,                    // Statement terminator
    /union/i,               // SQL UNION
    /select/i,              // SQL SELECT
    /drop/i,                // SQL DROP
    /insert/i,              // SQL INSERT
    /delete/i,              // SQL DELETE
    /update/i,              // SQL UPDATE
    /script/i,              // XSS script
    /javascript/i,          // XSS
    /\$/,                   // Variable injection
    /\{|\}/,                // Template injection
  ];
  
  return suspiciousPatterns.some(pattern => pattern.test(input));
};

/**
 * Validate input length
 */
const isValidLength = (input, min = 2, max = 255) => {
  if (!input || typeof input !== 'string') return false;
  return input.length >= min && input.length <= max;
};

/**
 * Handle paste event - sanitize pasted content
 */
const handlePaste = (event, fieldName) => {
  event.preventDefault();
  const pastedText = event.clipboardData.getData('text/plain');
  
  let sanitized;
  switch (fieldName) {
    case 'email':
      sanitized = sanitizeEmail(pastedText);
      break;
    case 'phone':
      sanitized = sanitizePhone(pastedText);
      break;
    default:
      sanitized = sanitizeInput(pastedText);
  }
  
  // Update the form field
  form[fieldName] = sanitized;
};

/**
 * Handle registration with security checks
 */
const handleRegister = async () => {
  loading.value = true;
  errorMessage.value = '';
  successMessage.value = '';

  // ===== SECURITY VALIDATION =====
  
  // 1. Detect injection attempts
  const fieldsToCheck = [form.company_name, form.name, form.email];
  for (const field of fieldsToCheck) {
    if (detectInjectionAttempt(field)) {
      errorMessage.value = 'Entrada no válida detectada.';
      loading.value = false;
      console.warn('⚠️ Possible injection attempt detected');
      return;
    }
  }
  
  // 2. Validate lengths
  if (!isValidLength(form.company_name, 2, 255)) {
    errorMessage.value = 'El nombre de la empresa debe tener entre 2 y 255 caracteres.';
    loading.value = false;
    return;
  }
  
  if (!isValidLength(form.name, 2, 255)) {
    errorMessage.value = 'Tu nombre debe tener entre 2 y 255 caracteres.';
    loading.value = false;
    return;
  }
  
  if (!isValidLength(form.password, 6, 128)) {
    errorMessage.value = 'La contraseña debe tener entre 6 y 128 caracteres.';
    loading.value = false;
    return;
  }
  
  // 3. Sanitize inputs before sending
  const sanitizedData = {
    company_name: sanitizeInput(form.company_name),
    name: sanitizeInput(form.name),
    email: sanitizeEmail(form.email),
    phone: form.phone ? sanitizePhone(form.phone) : null,
    password: form.password, // Don't sanitize passwords
  };

  try {
    const response = await apiClient.post('/register', sanitizedData);

    if (response.data.success) {
      // ✅ Save credentials in localStorage for Login page display
      localStorage.setItem('newAccountCredentials', JSON.stringify({
        email_tenant: response.data.data.email_tenant,
        company_name: response.data.data.company_name,
      }));
      
      successMessage.value = '¡Cuenta creada! Redirigiendo al login...';
      
      // Redirect to Login after 1 second
      setTimeout(() => {
        router.push('/');
      }, 1000);
    } else {
      errorMessage.value = response.data.message || 'Error al crear la cuenta.';
    }
  } catch (error) {
    console.error('Registration error:', error);
    
    if (error.response?.status === 429) {
      errorMessage.value = error.response.data.message || 'Demasiados intentos. Espera unos minutos.';
    } else if (error.response?.data?.message) {
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
