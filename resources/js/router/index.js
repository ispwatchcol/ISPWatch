import { createRouter, createWebHistory } from 'vue-router';

// Páginas
import Login from '@/pages/Login.vue';
import Register from '@/pages/Register.vue';
import Dashboard from '@/pages/Dashboard.vue';
import Staff from '@/pages/Staff.vue';
import StaffNew from '@/pages/StaffNew.vue';
import Routers from '@/pages/Routers.vue';
import RouterAdd from '@/pages/RouterAdd.vue';
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
        path: 'staff',
        name: 'Staff',
        component: Staff,
        meta: { requiresAuth: true },
      },
      {
        path: 'staff/new',
        name: 'StaffNew',
        component: StaffNew,
        meta: { requiresAuth: true },
      },
      {
        path: 'editstaff/:id',
        name: 'EditStaff',
        component: () => import('@/pages/EditStaff.vue'),
      },
      {
        path: 'routers',
        name: 'Routers',
        component: Routers,
        meta: { requiresAuth: true },
      },
      {
        path: "/routers/add",
        name: "RouterAdd",
        component: () => import("@/pages/RouterAdd.vue"),
      },
    ],
  },

  // ✅ Ruta 404
  {
    path: '/:pathMatch(.*)*',
    name: 'NotFound',
    component: () => import('@/pages/NotFound.vue'),
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

// ✅ Protección de rutas
router.beforeEach((to, from, next) => {
  const isLoggedIn =
    localStorage.getItem('isLoggedIn') === 'true' ||
    sessionStorage.getItem('isLoggedIn') === 'true';

  if (to.meta.requiresAuth && !isLoggedIn) {
    return next({ name: 'Login' });
  }

  if (to.name === 'Login' && isLoggedIn) {
    return next({ name: 'Dashboard' });
  }

  next();
});

export default router;
