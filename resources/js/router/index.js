// router/index.js
import { createRouter, createWebHistory } from 'vue-router';

import Login from '../pages/Login.vue';
import Register from '../pages/Register.vue';
import Dashboard from '../pages/Dashboard.vue';

const routes = [
  { path: '/', name: 'Login', component: Login },
  { path: '/register', name: 'Register', component: Register },
  { path: '/dashboard', name: 'Dashboard', component: Dashboard, meta: { requiresAuth: true } },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to, from, next) => {
  const isLoggedIn =
    localStorage.getItem('isLoggedIn') === 'true' ||
    sessionStorage.getItem('isLoggedIn') === 'true';

  // Bloquea rutas protegidas si no hay “sesión”
  if (to.meta.requiresAuth && !isLoggedIn) {
    return next({ name: 'Login' });
  }

  // Si ya está logueado, evita volver al login
  if (to.name === 'Login' && isLoggedIn) {
    return next({ name: 'Dashboard' });
  }

  next();
});

export default router;
