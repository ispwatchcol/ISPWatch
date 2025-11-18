<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-blue-100 px-4">
    <div class="bg-white shadow-2xl rounded-3xl p-10 w-full max-w-md">
      
      <!-- Logo -->
      <div class="flex justify-center mb-6">
        <img src="../assets/logo.png" alt="Logo" class="h-20 w-20 animate-bounce" />
      </div>

      <!-- Título -->
      <h2 class="text-3xl font-extrabold text-center text-gray-900 mb-2">Bienvenido</h2>
      <p class="text-center text-gray-500 mb-8">Inicia sesión para continuar</p>

      <!-- Mensaje de error -->
      <div v-if="errorMessage" class="mb-4 text-red-500 text-center text-sm">
        {{ errorMessage }}
      </div>

      <!-- Formulario -->
      <form @submit.prevent="handleLogin" class="space-y-5">
        <!-- EMAIL -->
        <div>
          <label for="email" class="block text-gray-700 font-medium mb-1">Correo electrónico</label>
          <input
            type="email"
            id="email"
            v-model="email"
            placeholder="you@example.com"
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
            required
          />
        </div>

        <!-- PASSWORD con ojito -->
        <div>
          <label for="password" class="block text-gray-700 font-medium mb-1">Contraseña</label>
          <div class="relative">
            <input
              :type="showPassword ? 'text' : 'password'"
              id="password"
              v-model="password"
              placeholder="********"
              class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm pr-12"
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
          <label class="flex items-center gap-2 text-gray-600">
            <input type="checkbox" v-model="remember" class="form-checkbox text-blue-600 rounded" /> Recordarme
          </label>
          <a href="#" class="text-blue-600 hover:underline font-medium">¿Olvidaste tu contraseña?</a>
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

<script>
import { supabase } from '../supabase'

export default {
  name: 'Login',
  data() {
    return {
      email: '',
      password: '',
      remember: false,
      loading: false,
      errorMessage: '',
      showPassword: false
    }
  },
  methods: {
    async handleLogin() {
      this.loading = true
      this.errorMessage = ''

      try {
        // ⚙️ Buscar usuario (tabla "user" requiere comillas dobles)
        const { data: user, error } = await supabase
          .from('users')
          .select(`
            id,
            email_tenant,
            password,
            tenant_id,
            role_id,
            user_name,
            user_lastname,
            role:role_id(name)
          `)
          .eq('email_tenant', this.email)
          .maybeSingle() // evita error si no hay resultados

        if (error || !user) {
          this.errorMessage = 'Usuario no encontrado.'
          return
        }

        // 🔐 Validar contraseña (plaintext)
        if (user.password !== this.password) {
          this.errorMessage = 'Contraseña incorrecta.'
          return
        }

        // 🕓 Actualizar último acceso
        const { error: updateError } = await supabase
          .from('users')
          .update({ last_access: new Date().toISOString() })
          .eq('id', user.id)

        if (updateError) {
          console.warn('⚠️ No se pudo actualizar last_access:', updateError.message)
        }

        // 💾 Guardar sesión
        const userData = {
          id: user.id,
          email_tenant: user.email_tenant,
          tenant_id: user.tenant_id,
          role_id: user.role_id,
          user_name: user.user_name,
          user_lastname: user.user_lastname,
          role_name: user.role?.name ?? 'Sin rol'
        }

        if (this.remember) {
          localStorage.setItem('isLoggedIn', 'true')
          localStorage.setItem('userData', JSON.stringify(userData))
        } else {
          sessionStorage.setItem('isLoggedIn', 'true')
          sessionStorage.setItem('userData', JSON.stringify(userData))
        }

        console.log('✅ Login exitoso:', userData)
        await this.$router.push({ name: 'Dashboard' })
      } catch (err) {
        console.error('❌ Error en login:', err)
        this.errorMessage = 'Ocurrió un error inesperado. Intenta de nuevo.'
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
body {
  font-family: 'Inter', sans-serif;
}
</style>
