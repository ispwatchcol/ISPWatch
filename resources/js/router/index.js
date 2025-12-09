import { createRouter, createWebHistory } from 'vue-router';

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
// Importamos el layout
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
    ],
  },
  
  // STAFF
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

  // ROUTERS
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
        path: "add", // Corregido: "add" relativo, no "/routers/add" absoluto
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

  // 👇 AQUÍ ESTÁN LAS NUEVAS RUTAS DE PLANES
  {
    path: '/planes',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'PlanList',
        // Asegúrate de crear este archivo con el código que te di antes
        component: () => import('@/pages/PlanList.vue'),
      },
      {
        path: 'create',
        name: 'PlanCreate',
        // Crea este archivo cuando estés listo para el formulario de crear
        component: () => import('@/pages/PlanCreate.vue'),
      },
      {
        path: ':id/edit',
        name: 'PlanEdit',
        // Crea este archivo cuando estés listo para editar
        component: () => import('@/pages/PlanEdit.vue'),
      }
    ]
  },

  // CUSTOMERS
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
