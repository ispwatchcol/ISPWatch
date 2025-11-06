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
      email: "",
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
        // 🔹 Buscar usuario por email_tenant
      const { data: user, error } = await supabase
        .from('user')
        .select(`
          id,
          email_tenant,
          password,
          tenant_id,
          role_id,
          user_name,
          user_lastname,
          role:role_id ( name )
        `)
        .eq('email_tenant', this.email)
        .single();

        if (error || !user) {
          this.errorMessage = "Usuario no encontrado.";
          return;
        }

        // 🔹 Validar contraseña
        if (user.password !== this.password) {
          this.errorMessage = "Contraseña incorrecta.";
          return;
        }

        // 🔹 Actualizar fecha de último acceso
        const { error: updateError } = await supabase
          .from('user')
          .update({ last_access: new Date().toISOString() })
          .eq('id', user.id);

        if (updateError) {
          console.error("⚠️ No se pudo actualizar last_access:", updateError.message);
        } else {
          console.log("🕓 Último acceso actualizado correctamente.");
        }

        // 🔹 Guardar información del usuario
        const userData = {
          id: user.id,
          email_tenant: user.email_tenant,
          tenant_id: user.tenant_id,
          role_id: user.role_id,
          user_name: user.user_name,
          user_lastname: user.user_lastname,
          role_name: user.role?.name ?? "Sin rol"
        };


        // 🔹 Guardar sesión en localStorage o sessionStorage
        if (this.remember) {
          localStorage.setItem("isLoggedIn", "true");
          localStorage.setItem("userData", JSON.stringify(userData));
        } else {
          sessionStorage.setItem("isLoggedIn", "true");
          sessionStorage.setItem("userData", JSON.stringify(userData));
        }

        // 🔹 Redirigir al dashboard
        console.log("✅ Login exitoso:", userData);
        await this.$router.push({ name: "Dashboard" });

      } catch (err) {
        console.error("❌ Error en login:", err);
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
