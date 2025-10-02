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

        <div>
          <label for="password" class="block text-gray-700 font-medium mb-1">Contraseña</label>
          <input
            type="password"
            id="password"
            v-model="password"
            placeholder="********"
            class="w-full p-4 border border-gray-300 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-blue-400 transition shadow-sm"
            required
          />
        </div>

        <div class="flex items-center justify-between text-sm">
          <label class="flex items-center gap-2 text-gray-600">
            <input type="checkbox" v-model="remember" class="form-checkbox text-blue-600 rounded" /> Recordarme
          </label>
          <a href="#" class="text-blue-600 hover:underline font-medium">¿Olvidaste tu contraseña?</a>
        </div>

        <button
          type="submit"
          :disabled="loading"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-2xl transition duration-300 shadow-lg disabled:bg-gray-400 disabled:cursor-not-allowed"
        >
          {{ loading ? "Iniciando sesión..." : "Iniciar sesión" }}
        </button>
      </form>

      <!-- Separador -->
      <div class="flex items-center my-6">
        <hr class="flex-1 border-gray-300" />
        <span class="px-3 text-gray-400 font-medium">o</span>
        <hr class="flex-1 border-gray-300" />
      </div>

      <!-- Botón de registro -->
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
import { supabase } from '../supabase';

export default {
  name: "Login",
  data() {
    return {
      email: "",       // Aquí usaremos "email" como identificador
      password: "",
      remember: false,
      loading: false,
      errorMessage: ""
    };
  },
  methods: {
    async handleLogin() {
      this.loading = true;
      this.errorMessage = "";

      try {
        // 1. Buscar al usuario por email
        const { data: users, error } = await supabase
          .from('user')
          .select('id, email, password')
          .eq('email', this.email)
          .single(); // Esperamos solo un resultado

        if (error || !users) {
          this.errorMessage = "Usuario no encontrado.";
          return;
        }

        // 2. Validar la contraseña
        // ⚠️ Esto asume que la contraseña está guardada en texto plano (no recomendado)
        if (users.password !== this.password) {
          this.errorMessage = "Contraseña incorrecta.";
          return;
        }

        // 3. Guardar "Recordarme"
        const userData = { id: users.id, email: users.email };

        if (this.remember) {
          localStorage.setItem("isLoggedIn", "true");
          localStorage.setItem("userData", JSON.stringify(userData));
          localStorage.setItem("userEmail", this.email);
        } else {
          sessionStorage.setItem("isLoggedIn", "true");
          sessionStorage.setItem("userData", JSON.stringify(userData));
          localStorage.removeItem("userEmail");
        }

        // 4. Redirigir al Dashboard
        console.log("Login exitoso, redirigiendo al dashboard...");
        await this.$router.push({ name: "Dashboard" });


      } catch (err) {
        console.error("Error en login:", err);
        this.errorMessage = "Ocurrió un error inesperado. Intenta de nuevo.";
      } finally {
        this.loading = false;
      }
    },
  },
};
</script>

<style scoped>
body {
  font-family: 'Inter', sans-serif;
}
</style>
