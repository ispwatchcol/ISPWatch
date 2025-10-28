// resources/js/router/index.js

import { createRouter, createWebHistory } from 'vue-router';

// Páginas
import Login from '@/pages/Login.vue';
import Register from '@/pages/Register.vue';
import Dashboard from '@/pages/Dashboard.vue';
import Staff from '@/pages/Staff.vue';
import StaffNew from '@/pages/StaffNew.vue';

// Layouts
import DefaultLayout from '@/layouts/DefaultLayout.vue';

const routes = [
  {
    path: '/',
    name: 'Login',
    component: Login,
  },
  {
    path: '/register',
    name: 'Register',
    component: Register,
  },
  {
    path: '/dashboard',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Dashboard',
        component: Dashboard,
      },
      {
        path: '/staff',
        name: 'Staff',
        component: Staff,
        meta: { requiresAuth: true },
      },
      {
        path: '/staff/new',
        name: 'StaffNew',
        component: StaffNew,
        meta: { requiresAuth: true },
      },
      {
        path: '/editstaff/:id',
        name: 'EditStaff',
        component: () => import('@/pages/EditStaff.vue'),
      },
    ],
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.beforeEach((to, from, next) => {
  const isLoggedIn =
    localStorage.getItem('isLoggedIn') === 'true' ||
    sessionStorage.getItem('isLoggedIn') === 'true';

  // 🔒 Bloquea rutas protegidas si no hay sesión iniciada
  if (to.meta.requiresAuth && !isLoggedIn) {
    return next({ name: 'Login' });
  }

  // 🚫 Evita volver al login si el usuario ya está autenticado
  if (to.name === 'Login' && isLoggedIn) {
    return next({ name: 'Dashboard' });
  }

  next();
});

export default router;
