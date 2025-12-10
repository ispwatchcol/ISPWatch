import { createRouter, createWebHistory } from 'vue-router';

// Páginas
import Login from '@/pages/Login.vue';
import Register from '@/pages/Register.vue';
import Dashboard from '@/pages/Dashboard.vue';
import Staff from '@/pages/Staff.vue';
import StaffNew from '@/pages/StaffNew.vue';
import EditStaff from '@/pages/EditStaff.vue';
import Routers from '@/pages/Routers.vue';
import Customers from '@/pages/Customers.vue';
import CustomerAdd from '@/pages/CustomerAdd.vue';
import CustomerEdit from '@/pages/CustomerEdit.vue';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Sectorial from '@/pages/Sectorial.vue';
import SectorialAdd from '../pages/SectorialAdd.vue';

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
    ],
  },
  {
    path: '/staff',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Staff',
        component: Staff,
      },
      {
        path: 'create',
        name: 'StaffNew',
        component: StaffNew,
      },
      {
        path: ':id/edit',
        name: 'StaffEdit',
        component: EditStaff,
      },
    ],
  },

  {
    path: '/routers',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Routers',
        component: Routers,
      },
      {
        path: "/routers/add",
        name: "RouterAdd",
        component: () => import("@/pages/RouterAdd.vue"),
      },
      {
      path: ':id/edit', 
      name: 'RouterEdit',
      component: () => import("@/pages/RouterEdit.vue"),
      },
    ],
  },

  {
    path: '/customers',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Customers',
        component: Customers,
      },
      {
        path: 'create',
        name: 'CustomerAdd',
        component: CustomerAdd,
      },
      {
        path: ':id/edit',
        name: 'CustomerEdit',
        component: CustomerEdit,
      }
    ],
  },

  {
    path: '/sectorials',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Sectorials',
        component: Sectorial,
      },
      {
        path: 'create',
        name: 'SectorialAdd',
        component: SectorialAdd,
      },
      {
        path: ':id/edit',
        name: 'SectorialEdit',
        component: () => import('@/pages/SectorialEdit.vue'),
      }
    ],
  },

  // ✅ Ruta 404
  {
    path: '/:pathMatch(.*)*',
    name: 'NotFound',
    component: () => import('../pages/NotFound.vue'),
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
