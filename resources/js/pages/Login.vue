<template>
    <div
        class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-blue-100 px-4"
    >
        <div class="bg-white shadow-2xl rounded-3xl p-10 w-full max-w-md">
            <!-- Logo -->
            <div class="flex justify-center mb-6">
                <img
                    src="../assets/logo.png"
                    alt="Logo"
                    class="h-20 w-20 animate-bounce"
                />
            </div>

            <!-- Título -->
            <h2 class="text-3xl font-extrabold text-center text-gray-900 mb-2">
                Bienvenido
            </h2>
            <p class="text-center text-gray-500 mb-8">
                Inicia sesión para continuar
            </p>

            <!-- Mensaje de error -->
            <div
                v-if="errorMessage"
                class="mb-4 text-red-500 text-center text-sm"
            >
                {{ errorMessage }}
            </div>

            <!-- Formulario -->
            <form @submit.prevent="handleLogin" class="space-y-5">
                <!-- EMAIL -->
                <div>
                    <label
                        for="email"
                        class="block text-gray-700 font-medium mb-1"
                        >Correo electrónico</label
                    >
                    <input
                        type="text"
                        id="email_tenant"
                        v-model="loginData.email_tenant"
                        placeholder="usuario de ingreso"
                        autocomplete="username"
                        maxlength="100"
                        minlength="3"
                        pattern="^[a-zA-Z0-9@._-]+$"
                        class="w-full p-4 border border-gray-300 rounded-2xl"
                        required
                        @paste="handlePaste"
                    />
                </div>

                <!-- PASSWORD con ojito -->
                <div>
                    <label
                        for="password"
                        class="block text-gray-700 font-medium mb-1"
                        >Contraseña</label
                    >
                    <div class="relative">
                        <input
                            :type="showPassword ? 'text' : 'password'"
                            id="password"
                            v-model="loginData.password"
                            placeholder="********"
                            autocomplete="current-password"
                            maxlength="100"
                            minlength="4"
                            class="w-full p-4 border border-gray-300 rounded-2xl"
                            required
                        />
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700"
                        >
                            <svg
                                v-if="!showPassword"
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                />
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                                />
                            </svg>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M13.875 18.825A10.05 10.05 0 0112 19c-4.477 0-8.268-2.943-9.542-7a9.973 9.973 0 012.878-4.642m3.743-2.39A9.969 9.969 0 0112 5c4.477 0 8.268 2.943 9.542 7a9.97 9.97 0 01-4.043 5.031M3 3l18 18"
                                />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- RECORDAR -->
                <div class="flex items-center justify-between text-sm">
                    <label
                        for="remember"
                        class="flex items-center gap-2 text-gray-600"
                    >
                        <input
                            type="checkbox"
                            id="remember"
                            v-model="loginData.remember"
                            class="form-checkbox text-blue-600 rounded"
                        />
                        Recordarme
                    </label>
                    <a
                        href="#"
                        class="text-blue-600 hover:underline font-medium"
                        >¿Olvidaste tu contraseña?</a
                    >
                </div>

                <!-- BOTÓN LOGIN -->
                <button
                    type="submit"
                    :disabled="loading"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-2xl transition duration-300 shadow-lg disabled:bg-gray-400 disabled:cursor-not-allowed"
                >
                    {{ loading ? "Iniciando sesión..." : "Iniciar sesión" }}
                </button>
            </form>

            <!-- SEPARADOR -->
            <div class="flex items-center my-6">
                <hr class="flex-1 border-gray-300" />
                <span class="px-3 text-gray-400 font-medium">o</span>
                <hr class="flex-1 border-gray-300" />
            </div>

            <!-- BOTÓN REGISTRO -->
            <button
                @click="$router.push('/register')"
                class="w-full border border-gray-300 text-gray-700 py-3 rounded-2xl hover:bg-gray-50 transition duration-300 font-medium shadow-sm"
            >
                Crear cuenta
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref } from "vue";
import { useRouter } from "vue-router";
import api from "../services/api.js";

const router = useRouter();

const loginData = ref({
    email_tenant: "",
    password: "",
    remember: false,
});

const showPassword = ref(false);
const loading = ref(false);
const errorMessage = ref("");

// ===== FUNCIONES DE SEGURIDAD =====

// Sanitizar entrada: elimina caracteres peligrosos
const sanitizeInput = (input) => {
    if (typeof input !== 'string') return '';
    
    // Eliminar tags HTML/scripts
    let sanitized = input.replace(/<[^>]*>/g, '');
    
    // Eliminar caracteres de control
    sanitized = sanitized.replace(/[\x00-\x1F\x7F]/g, '');
    
    // Escapar caracteres especiales SQL
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

// Detectar intentos de inyección
const detectInjectionAttempt = (input) => {
    if (typeof input !== 'string') return false;
    
    const suspiciousPatterns = [
        /[<>]/,                   // HTML tags
        /['"]/,                   // Quotes (SQL)
        /--/,                     // SQL comment
        /;/,                      // Statement terminator
        /union/i,                 // SQL UNION
        /select/i,                // SQL SELECT
        /drop/i,                  // SQL DROP
        /insert/i,                // SQL INSERT
        /delete/i,                // SQL DELETE
        /update/i,                // SQL UPDATE
        /script/i,                // XSS script
        /javascript/i,            // XSS
        /\$/,                     // Variable injection
        /\{|\}/,                  // Template injection
    ];
    
    return suspiciousPatterns.some(pattern => pattern.test(input));
};

const handleLogin = async () => {
    loading.value = true;
    errorMessage.value = "";

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

            console.log("Usuario que se va a guardar:", user);

            if (loginData.value.remember) {
                localStorage.setItem("userData", JSON.stringify(user));
                localStorage.setItem("isLoggedIn", "true");
            } else {
                sessionStorage.setItem("userData", JSON.stringify(user));
                sessionStorage.setItem("isLoggedIn", "true");
            }

            router.push("/dashboard");
        } else {
            errorMessage.value = response.data.message || "Error de login.";
        }
    } catch (error) {
        console.error(error);
        if (error.response?.status === 401) {
            errorMessage.value = "Credenciales incorrectas.";
        } else {
            errorMessage.value = "Ocurrió un error inesperado.";
        }
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
body {
    font-family: "Inter", sans-serif;
}
</style>
