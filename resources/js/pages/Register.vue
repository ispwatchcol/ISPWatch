<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-4 relative overflow-hidden">
    <!-- Background Decorative Elements -->
    <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-blue-400/20 dark:bg-blue-600/10 blur-[120px] rounded-full animate-pulse"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-indigo-400/20 dark:bg-indigo-600/10 blur-[120px] rounded-full animate-pulse" style="animation-delay: 2s"></div>

    <!-- Register Card -->
    <div class="relative z-10 w-full max-w-lg py-8 md:py-12">
      <!-- App Branding -->
      <div class="flex flex-col items-center mb-6 md:mb-8 animate-fade-in-down">
        <div class="bg-white dark:bg-slate-900 p-3 md:p-4 rounded-3xl shadow-xl mb-4 border border-slate-100 dark:border-slate-800 group transition-all hover:scale-110 cursor-pointer" @click="$router.push('/')">
          <img src="../assets/favicon.svg" alt="Logo" class="h-14 w-14 md:h-16 md:w-16 group-hover:rotate-12 transition-transform duration-500" />
        </div>
        <h1 class="text-2xl md:text-3xl font-black text-slate-900 dark:text-white tracking-tight">ISP<span class="text-blue-600">Watch</span></h1>
        <p class="text-slate-500 dark:text-slate-400 text-xs md:text-sm font-medium mt-1">Gestión de ISP Inteligente</p>
      </div>

      <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-2xl shadow-2xl rounded-[2rem] md:rounded-[2.5rem] p-6 md:p-10 border border-white/20 dark:border-slate-800">
        <!-- Título -->
        <div class="mb-8 text-center">
          <h2 class="text-2xl font-bold text-slate-900 dark:text-white">Crear tu cuenta</h2>
          <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">Regístrate para comenzar con tu prueba gratuita</p>
        </div>

        <!-- Trial Badge -->
        <div class="mb-8 p-4 bg-green-500/10 border border-green-500/20 rounded-2xl text-center group transition-all hover:bg-green-500/20">
          <p class="text-sm text-green-700 dark:text-green-400 font-bold flex items-center justify-center gap-2">
            <span class="text-xl">🎉</span> Plan Trial: <strong>30 días gratis</strong>
          </p>
        </div>

        <!-- Mensajes de estado -->
        <div v-if="errorMessage" class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-900/30 rounded-2xl text-red-600 dark:text-red-400 text-center text-sm font-medium animate-shake">
          <div class="flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            {{ errorMessage }}
          </div>
        </div>

        <div v-if="successMessage" class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-900/30 rounded-2xl text-green-600 dark:text-green-400 text-center text-sm font-medium animate-fade-in">
          <div class="flex items-center justify-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            {{ successMessage }}
          </div>
        </div>

        <!-- Formulario -->
        <form @submit.prevent="handleRegister" class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Nombre de empresa -->
            <div class="space-y-1.5">
              <label for="company_name" class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1">Empresa</label>
              <input
                type="text"
                id="company_name"
                v-model="form.company_name"
                placeholder="Mi ISP S.A.S."
                class="w-full px-4 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
                required
                maxlength="255"
                minlength="2"
                @paste="handlePaste($event, 'company_name')"
              />
            </div>

            <!-- Nombre completo -->
            <div class="space-y-1.5">
              <label for="name" class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1">Representante</label>
              <input
                type="text"
                id="name"
                v-model="form.name"
                placeholder="Juan Pérez"
                class="w-full px-4 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
                required
                maxlength="255"
                minlength="2"
                @paste="handlePaste($event, 'name')"
              />
            </div>
          </div>

          <!-- Correo electrónico -->
          <div class="space-y-1.5">
            <label for="email" class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1">Correo Electrónico</label>
            <input
              type="email"
              id="email"
              v-model="form.email"
              placeholder="you@example.com"
              class="w-full px-4 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
              required
              maxlength="255"
              @paste="handlePaste($event, 'email')"
            />
            <p class="text-[10px] text-slate-400 dark:text-slate-500 px-1 font-medium">
              Te enviaremos un <span class="text-blue-500 font-bold">enlace de verificación</span> para activar tu cuenta.
            </p>
          </div>

          <!-- Teléfono (opcional) -->
          <div class="space-y-1.5">
            <label for="phone" class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1">Teléfono <span class="text-[9px] opacity-50">(opcional)</span></label>
            <input
              type="tel"
              id="phone"
              v-model="form.phone"
              placeholder="+57 300 123 4567"
              class="w-full px-4 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
              maxlength="20"
              @paste="handlePaste($event, 'phone')"
            />
          </div>

          <!-- Contraseña -->
          <div class="space-y-1.5">
            <label for="password" class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1">Contraseña</label>
            <input
              type="password"
              id="password"
              v-model="form.password"
              placeholder="••••••••"
              class="w-full px-4 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
              required
              minlength="6"
              maxlength="128"
            />
            <p class="text-[10px] text-slate-400 dark:text-slate-500 px-1 font-medium">Usa al menos 6 caracteres.</p>
          </div>

          <!-- Botón de registro -->
          <button
            type="submit"
            :disabled="loading"
            class="group relative w-full overflow-hidden rounded-2xl bg-blue-600 px-6 py-4 font-bold text-white shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 hover:scale-[1.02] active:scale-[0.98] disabled:bg-slate-400 disabled:shadow-none disabled:scale-100 disabled:cursor-not-allowed mt-4"
          >
            <div class="relative z-10 flex items-center justify-center gap-2">
              <span v-if="loading" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              {{ loading ? "CREANDO ACCESO..." : "COMENZAR PRUEBA GRATIS" }}
              <svg v-if="!loading" class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
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
            <span class="bg-white dark:bg-slate-900 px-4 text-slate-400">¿Ya tienes una cuenta?</span>
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
          INICIAR SESIÓN
        </button>
      </div>

      <!-- Footer info -->
      <p class="text-center text-slate-400 dark:text-slate-500 text-xs mt-8 font-medium">
        Al registrarte, aceptas nuestros <a href="#" class="text-blue-600 dark:text-blue-400 hover:underline">Términos de Servicio</a>.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter } from 'vue-router';
import api from '../services/api'; // Use default export with auth.register method

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
 * Detect injection patterns (only really dangerous patterns)
 */
const detectInjectionAttempt = (input) => {
  if (typeof input !== 'string') return false;
  
  const suspiciousPatterns = [
    /<>/,                     // HTML tags
    /['"]/,                   // Quotes (SQL)
    /--/,                     // SQL comment
    /;/,                      // Statement terminator
    /union\s+select/i,        // SQL UNION SELECT
    /select\s+\*/i,           // SQL SELECT *
    /drop\s+table/i,          // SQL DROP TABLE
    /insert\s+into/i,         // SQL INSERT INTO
    /delete\s+from/i,         // SQL DELETE FROM
    /update\s+\w+\s+set/i,    // SQL UPDATE SET
    /<script/i,               // XSS script tag
    /javascript:/i,           // XSS javascript protocol
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
    const response = await api.auth.register(sanitizedData);

    if (response.data.success) {
      // ✅ Save credentials in localStorage for Login page display
      localStorage.setItem('newAccountCredentials', JSON.stringify({
        email_tenant: response.data.data.email_tenant,
        company_name: response.data.data.company_name,
      }));
      
      successMessage.value = '¡Cuenta creada! Te enviamos un enlace de verificación a tu correo. Revisa tu bandeja de entrada para activar tu cuenta.';
    } else {
      errorMessage.value = response.data.message || 'Error al crear la cuenta.';
    }
  } catch (error) {
    console.error('Registration error:', error);
    
    if (error.response?.status === 429) {
      errorMessage.value = error.response.data.message || 'Demasiados intentos. Espera unos minutos.';
    } else if (error.response?.data?.requires_verification) {
      // Redirect to resend verification page with email pre-filled
      router.push({
        name: 'ResendVerification',
        query: { email: sanitizedData.email }
      });
      return;
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
/* Transiciones Globales */
* {
    transition: background-color 0.3s, border-color 0.3s, color 0.3s;
}

/* Animaciones */
@keyframes fade-in-down {
    0% {
        opacity: 0;
        transform: translateY(-20px);
    }
    100% {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
    20%, 40%, 60%, 80% { transform: translateX(4px); }
}

.animate-fade-in-down {
    animation: fade-in-down 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

.animate-fade-in {
    animation: fade-in 0.5s ease-out;
}

.animate-shake {
    animation: shake 0.5s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
}

/* Custom Scrollbar */
::-webkit-scrollbar {
    width: 6px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: rgba(156, 163, 175, 0.2);
    border-radius: 10px;
}
.dark ::-webkit-scrollbar-thumb {
    background: rgba(75, 85, 99, 0.3);
}
</style>