<template>
    <div
        class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-4 relative overflow-hidden"
    >
        <!-- Background Decorative Elements -->
        <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-blue-400/20 dark:bg-blue-600/10 blur-[120px] rounded-full animate-pulse"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-indigo-400/20 dark:bg-indigo-600/10 blur-[120px] rounded-full animate-pulse" style="animation-delay: 2s"></div>

        <!-- ✅ NOTIFICACIÓN DE VERIFICACIÓN EXITOSA -->
        <div 
          v-if="showVerificationSuccess" 
          class="fixed top-4 right-4 z-[100] w-full max-w-md animate-slide-in-right px-4"
        >
          <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl rounded-2xl shadow-2xl p-6 border border-green-200 dark:border-green-900/50">
            <!-- Header -->
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
              </div>
              <div>
                <h3 class="font-bold text-slate-900 dark:text-white">✅ Email Verificado</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">Tu cuenta está lista</p>
              </div>
              <button 
                @click="closeVerificationNotification"
                class="ml-auto p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition-all"
              >
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <!-- Success Message -->
            <div class="bg-green-50/50 dark:bg-green-900/10 rounded-xl p-4 mb-4 border border-green-100 dark:border-green-900/20">
              <p class="text-sm text-green-800 dark:text-green-300 mb-3">
                🎉 <strong>¡Tu correo ha sido verificado exitosamente!</strong>
              </p>
              <p class="text-xs text-green-700 dark:text-green-400/80 mb-2">
                Ahora puedes iniciar sesión con tus credenciales:
              </p>
              <div class="bg-white/50 dark:bg-slate-800/50 rounded-lg px-3 py-2 border border-green-200 dark:border-green-900/30 font-mono text-sm text-slate-800 dark:text-slate-200">
                👤 {{ verificationData.email_tenant }}
              </div>
            </div>

            <!-- Company info -->
            <div class="text-center text-[10px] text-slate-400 dark:text-slate-500 uppercase tracking-widest font-bold">
              {{ verificationData.company }}
            </div>
          </div>
        </div>

        <!-- ✅ NOTIFICACIÓN DE CREDENCIALES (después del registro) -->
        <div 
          v-if="showCredentialsNotification" 
          class="fixed top-4 right-4 z-[100] w-full max-w-md animate-slide-in-right px-4"
        >
          <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl rounded-2xl shadow-2xl p-6 border border-green-200 dark:border-green-900/50">
            <!-- Header -->
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
              </div>
              <div>
                <h3 class="font-bold text-slate-900 dark:text-white">🎉 ¡Cuenta Creada Exitosamente!</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">Usa estas credenciales para ingresar</p>
              </div>
              <button 
                @click="closeCredentialsNotification"
                class="ml-auto p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-xl transition-all"
              >
                <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <!-- Credenciales -->
            <div class="bg-blue-50/50 dark:bg-blue-900/10 rounded-xl p-4 mb-3 border border-blue-100 dark:border-blue-900/20">
              <label class="block text-[10px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider mb-1">👤 Usuario de acceso:</label>
              <div class="font-mono font-bold text-blue-800 dark:text-blue-200 text-lg bg-white/50 dark:bg-slate-800/50 rounded-lg px-3 py-2 border border-blue-200 dark:border-blue-900/30">
                {{ newAccountCredentials.email_tenant }}
              </div>
            </div>

            <div class="bg-indigo-50/50 dark:bg-indigo-900/10 rounded-xl p-4 mb-4 border border-indigo-100 dark:border-indigo-900/20">
              <label class="block text-[10px] font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider mb-1">🔑 Contraseña:</label>
              <div class="font-medium text-indigo-800 dark:text-indigo-200 bg-white/50 dark:bg-slate-800/50 rounded-lg px-3 py-2 border border-indigo-200 dark:border-indigo-900/30">
                La que ingresaste al registrarte
              </div>
            </div>

            <!-- Advertencia -->
            <div class="bg-amber-50/50 dark:bg-amber-900/10 border border-amber-200/50 dark:border-amber-900/30 rounded-xl p-3 text-xs text-amber-700 dark:text-amber-300 flex gap-2 items-center">
              <span class="text-base">⚠️</span>
              <span><strong>Importante:</strong> Ingresa el usuario exacto mostrado arriba, NO tu correo personal.</span>
            </div>
          </div>
        </div>

        <!-- Login Card -->
        <div class="relative z-10 w-full max-w-md py-8 md:py-12">
            <!-- App Branding -->
            <div class="flex flex-col items-center mb-6 md:mb-8 animate-fade-in-down">
                <div class="bg-white dark:bg-slate-900 p-3 md:p-4 rounded-3xl shadow-xl mb-4 border border-slate-100 dark:border-slate-800 group transition-all hover:scale-110">
                    <img
                        :src="'/brand/icon.svg'"
                        alt="ISP Watch"
                        class="h-14 w-14 md:h-16 md:w-16 group-hover:rotate-12 transition-transform duration-500"
                    />
                </div>
                <h1 class="text-2xl md:text-3xl font-black text-slate-900 dark:text-white tracking-tight">ISP<span class="text-blue-600">Watch</span></h1>
                <p class="text-slate-500 dark:text-slate-400 text-xs md:text-sm font-medium mt-1">Gestión de ISP Inteligente</p>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-2xl shadow-2xl rounded-[2rem] md:rounded-[2.5rem] p-6 md:p-10 border border-white/20 dark:border-slate-800">
                <!-- Título -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white">
                        Bienvenido de nuevo
                    </h2>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mt-1">
                        Ingresa tus credenciales para acceder al sistema
                    </p>
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

                <!-- Formulario -->
                <form @submit.prevent="handleLogin" class="space-y-6">
                    <!-- EMAIL -->
                    <div class="space-y-1.5">
                        <label
                            for="email_tenant"
                            class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1"
                            >Usuario de Acceso</label
                        >
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </div>
                            <input
                                type="text"
                                id="email_tenant"
                                v-model="loginData.email_tenant"
                                placeholder="p. ej. adm_miisp"
                                autocomplete="username"
                                class="w-full pl-11 pr-4 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
                                required
                                @paste="handlePaste"
                            />
                        </div>
                    </div>

                    <!-- PASSWORD -->
                    <div class="space-y-1.5">
                        <label
                            for="password"
                            class="block text-[11px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-widest px-1"
                            >Contraseña</label
                        >
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 group-focus-within:text-blue-500 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                                </svg>
                            </div>
                            <input
                                :type="showPassword ? 'text' : 'password'"
                                id="password"
                                v-model="loginData.password"
                                placeholder="••••••••"
                                autocomplete="current-password"
                                class="w-full pl-11 pr-12 py-4 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800 rounded-2xl focus:ring-4 focus:ring-blue-500/10 focus:border-blue-500 dark:focus:border-blue-500/50 transition-all outline-none text-slate-900 dark:text-white"
                                required
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-blue-500 transition-colors"
                            >
                                <svg v-if="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.973 9.973 0 012.878-4.642m3.743-2.39A9.969 9.969 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.97 9.97 0 01-4.043 5.031M3 3l18 18" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- RECORDAR & OLVIDO -->
                    <div class="flex items-center justify-between text-xs font-bold uppercase tracking-widest">
                        <label
                            for="remember"
                            class="flex items-center gap-2 text-slate-500 dark:text-slate-400 cursor-pointer group"
                        >
                            <div class="relative flex items-center">
                                <input
                                    type="checkbox"
                                    id="remember"
                                    v-model="loginData.remember"
                                    class="peer h-5 w-5 cursor-pointer appearance-none rounded-lg border-2 border-slate-200 dark:border-slate-800 transition-all checked:bg-blue-600 checked:border-blue-600"
                                />
                                <svg class="absolute w-3 h-3 text-white pointer-events-none hidden peer-checked:block ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <span class="group-hover:text-slate-700 dark:group-hover:text-slate-200 transition-colors">Recordarme</span>
                        </label>
                        <a
                            href="#"
                            class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition-colors"
                            >¿Olvidaste tu acceso?</a
                        >
                    </div>

                    <!-- BOTÓN LOGIN -->
                    <button
                        type="submit"
                        :disabled="loading"
                        class="group relative w-full overflow-hidden rounded-2xl bg-blue-600 px-6 py-4 font-bold text-white shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 hover:scale-[1.02] active:scale-[0.98] disabled:bg-slate-400 disabled:shadow-none disabled:scale-100 disabled:cursor-not-allowed"
                    >
                        <div class="relative z-10 flex items-center justify-center gap-2">
                            <span v-if="loading" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            {{ loading ? "VERIFICANDO..." : "ENTRAR AL SISTEMA" }}
                            <svg v-if="!loading" class="w-5 h-5 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                            </svg>
                        </div>
                    </button>
                </form>

                <!-- SEPARADOR -->
                <div class="relative my-8">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-slate-200 dark:border-slate-800"></div>
                    </div>
                    <div class="relative flex justify-center text-[10px] font-black uppercase tracking-widest">
                        <span class="bg-white dark:bg-slate-900 px-4 text-slate-400">¿Eres nuevo aquí?</span>
                    </div>
                </div>

                <!-- BOTÓN REGISTRO -->
                <button
                    @click="$router.push('/register')"
                    class="w-full group px-6 py-4 rounded-2xl border-2 border-slate-100 dark:border-slate-800 text-slate-700 dark:text-slate-300 font-bold transition-all hover:bg-slate-50 dark:hover:bg-slate-800 hover:border-slate-200 dark:hover:border-slate-700 hover:scale-[1.02] active:scale-[0.98] flex items-center justify-center gap-2"
                >
                    <svg class="w-5 h-5 text-slate-400 group-hover:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                    </svg>
                    CREAR UNA CUENTA
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
import { ref, onMounted, reactive } from "vue";
import { useRouter } from "vue-router";
import api from "../services/api.js";
import { useAuthStore } from "@/stores/auth.js";

const router = useRouter();
const authStore = useAuthStore();

const loginData = ref({
    email_tenant: "",
    password: "",
    remember: false,
});

const showPassword = ref(false);
const loading = ref(false);
const errorMessage = ref("");

// ✅ Notificación de credenciales para nuevos usuarios
const showCredentialsNotification = ref(false);
const newAccountCredentials = reactive({
    email_tenant: '',
    company_name: '',
});

// ✅ Notificación de verificación exitosa
const showVerificationSuccess = ref(false);
const verificationData = reactive({
    email_tenant: '',
    company: '',
});

// Verificar si hay credenciales de una cuenta recién creada
onMounted(() => {
    // Check for email verification success from URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    
    if (urlParams.get('verified') === 'success') {
        const email_tenant = urlParams.get('email_tenant');
        const company = urlParams.get('company');
        
        if (email_tenant) {
            verificationData.email_tenant = decodeURIComponent(email_tenant);
            verificationData.company = company ? decodeURIComponent(company) : 'ISPWatch';
            showVerificationSuccess.value = true;
            
            // Pre-fill login field
            loginData.value.email_tenant = verificationData.email_tenant;
            
            // Clean URL
            window.history.replaceState({}, document.title, window.location.pathname);
            return;
        }
    }
    
    // Check for new account credentials
    const savedCredentials = localStorage.getItem('newAccountCredentials');
    if (savedCredentials) {
        try {
            const parsed = JSON.parse(savedCredentials);
            newAccountCredentials.email_tenant = parsed.email_tenant || '';
            newAccountCredentials.company_name = parsed.company_name || '';
            showCredentialsNotification.value = true;
            
            // Pre-llenar el campo de usuario
            loginData.value.email_tenant = parsed.email_tenant || '';
        } catch (e) {
            console.error('Error parsing saved credentials:', e);
        }
    }
});

// Cerrar notificación y limpiar localStorage
const closeCredentialsNotification = () => {
    showCredentialsNotification.value = false;
    localStorage.removeItem('newAccountCredentials');
};

// Cerrar notificación de verificación
const closeVerificationNotification = () => {
    showVerificationSuccess.value = false;
};

// ===== FUNCIONES DE SEGURIDAD =====

// Sanitizar entrada: elimina caracteres peligrosos pero permite @ . _ -
const sanitizeInput = (input) => {
    if (typeof input !== 'string') return '';
    
    // Eliminar tags HTML/scripts
    let sanitized = input.replace(/<[^>]*>/g, '');
    
    // Eliminar caracteres de control
    sanitized = sanitized.replace(/[\x00-\x1F\x7F]/g, '');
    
    // Escapar caracteres especiales SQL (pero NO @ . _ - que son válidos para email_tenant)
    sanitized = sanitized.replace(/['";\\]/g, '');
    
    // Eliminar patrones de inyección comunes
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

// Manejar pegar contenido - sanitiza al pegar
const handlePaste = (event) => {
    event.preventDefault();
    const pastedText = event.clipboardData.getData('text/plain');
    const sanitized = sanitizeInput(pastedText);
    
    // Insertar texto sanitizado
    const target = event.target;
    const start = target.selectionStart;
    const end = target.selectionEnd;
    const currentValue = target.value;
    const newValue = currentValue.substring(0, start) + sanitized + currentValue.substring(end);
    
    // Actualizar el modelo
    loginData.value.email_tenant = newValue;
};

// Validar formato de entrada
const isValidInput = (input) => {
    if (!input || typeof input !== 'string') return false;
    if (input.length > 100) return false; // Max length
    if (input.length < 3) return false;   // Min length
    return true;
};

// Detectar intentos de inyección (solo patrones realmente peligrosos)
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

const handleLogin = async () => {
    loading.value = true;
    errorMessage.value = "";
    
    // Cerrar notificación de credenciales al intentar login
    closeCredentialsNotification();

    // ===== VALIDACIONES DE SEGURIDAD =====
    const rawEmail = loginData.value.email_tenant;
    const rawPassword = loginData.value.password;
    
    // 1. Detectar intentos de inyección
    if (detectInjectionAttempt(rawEmail)) {
        errorMessage.value = "Entrada no válida detectada.";
        loading.value = false;
        console.warn("⚠️ Posible intento de inyección detectado en email");
        return;
    }
    
    // 2. Sanitizar entradas
    const sanitizedEmail = sanitizeInput(rawEmail);
    const sanitizedPassword = rawPassword; // No sanitizar password (puede tener caracteres especiales legítimos)
    
    // 3. Validar longitudes y formato
    if (!isValidInput(sanitizedEmail)) {
        errorMessage.value = "Usuario debe tener entre 3 y 100 caracteres.";
        loading.value = false;
        return;
    }
    
    if (!sanitizedPassword || sanitizedPassword.length < 4 || sanitizedPassword.length > 100) {
        errorMessage.value = "Contraseña debe tener entre 4 y 100 caracteres.";
        loading.value = false;
        return;
    }

    try {
        const response = await api.auth.login({
            email_tenant: sanitizedEmail,
            password: sanitizedPassword,
        });

        if (response.data.success) {
            // ⚠️ EXTRAER CORRECTAMENTE EL USUARIO
            const user = response.data.data.user ?? response.data.data;

            // Clear any previous session first (including a stale "remember me"
            // entry in localStorage). loadFromStorage() reads localStorage
            // before sessionStorage, so an old remembered userData would
            // otherwise shadow this fresh login and keep outdated permissions.
            authStore.logout();

            // Persist through the store so authStore.user is populated
            // immediately (the router guard no longer has to lazily hydrate it).
            authStore.setUser(user, loginData.value.remember);

            router.push("/dashboard");
        } else {
            errorMessage.value = response.data.message || "Error de login.";
        }
    } catch (error) {
        console.error(error);
        
        // Manejo específico por código de error HTTP
        if (error.response) {
            const status = error.response.status;
            const data = error.response.data;
            
            if (status === 429) {
                // Rate limit excedido
                const retryAfter = data.retry_after || 60;
                errorMessage.value = data.message || `Demasiados intentos. Espera ${retryAfter} segundos.`;
            } else if (status === 400) {
                // Entrada sospechosa detectada
                errorMessage.value = data.message || "Entrada no válida detectada.";
            } else if (status === 401) {
                // Credenciales incorrectas
                errorMessage.value = "Credenciales incorrectas.";
            } else {
                // Otros errores
                errorMessage.value = data.message || "Ocurrió un error inesperado.";
            }
        } else {
            errorMessage.value = "Error de conexión. Verifica tu red.";
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
@keyframes slide-in-right {
    0% {
        opacity: 0;
        transform: translateX(100%);
    }
    100% {
        opacity: 1;
        transform: translateX(0);
    }
}

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

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
    20%, 40%, 60%, 80% { transform: translateX(4px); }
}

.animate-slide-in-right {
    animation: slide-in-right 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

.animate-fade-in-down {
    animation: fade-in-down 0.6s cubic-bezier(0.16, 1, 0.3, 1);
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
