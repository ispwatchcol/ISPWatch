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
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import Sectorial from '@/pages/Sectorial.vue';
import SectorialAdd from '../pages/SectorialAdd.vue';
import CustomerStatistics from '@/pages/CustomerStatistics.vue';


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
        path: '/planes/:id/edit',
        name: 'plan-edit',
        component: () => import('@/pages/PlanEdit.vue'),
        props: true // Opcional, pero recomendado
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
      },
      {
        path: 'statistics',
        name: 'CustomerStatistics',
        component: CustomerStatistics,
      },
      {
        path: 'map',
        name: 'CustomerMap',
        component: () => import('@/pages/CustomerMap.vue'),
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
  
  // SUPPORT
  {
    path: '/support',
    component: DefaultLayout,
    meta: { requiresAuth: true, requiresStaff: true },
    children: [
      {
        path: '',
        name: 'Support',
        component: () => import('@/pages/Support.vue'),
        meta: { permission: 'support.view' } // Added permission
      },
      {
        path: 'create',
        name: 'SupportCreate',
        component: () => import('@/pages/SupportCreate.vue'),
        meta: { permission: 'support.create' }
      },
      {
        path: ':id',
        name: 'SupportDetail',
        component: () => import('@/pages/SupportDetail.vue'),
        // No strict permission here, handled in component (view own or all)
      },
      {
        path: ':id/edit',
        name: 'SupportEdit',
        component: () => import('@/pages/SupportEdit.vue'),
        meta: { permission: 'support.update' } // Admin/Staff only
      },
      {
        path: 'statistics',
        name: 'SupportStatistics',
        component: () => import('@/pages/SupportStatistics.vue'),
        meta: { permission: 'support.statistics' } // Admin/Staff only
      },
    ],
  },
  {
    path: '/billing',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Billing',
        component: () => import('@/pages/Billing.vue'),
      },
      {
        path: 'create',
        name: 'BillingCreate',
        component: () => import('@/pages/BillingForm.vue'),
      },
      {
        path: ':id/edit',
        name: 'BillingEdit',
        component: () => import('@/pages/BillingForm.vue'),
      }
    ]
  },
  {
    path: '/inventory',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Inventory',
        component: () => import('@/pages/Inventory.vue'),
      },
      {
        path: 'create',
        name: 'InventoryCreate',
        component: () => import('@/pages/InventoryForm.vue'),
      },
      {
        path: ':id/edit',
        name: 'InventoryEdit',
        component: () => import('@/pages/InventoryForm.vue'),
      }
    ]
  },
  {
    path: '/settings',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Settings',
        component: () => import('@/pages/Settings.vue'),
      }
    ]
  },
  {
    path: '/manual',
    component: DefaultLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Manual',
        component: () => import('@/pages/Manual.vue'),
      }
    ]
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

import { hasPermission, isStaffOrAdmin } from '../services/auth';

// ✅ Protección de rutas
router.beforeEach((to, from, next) => {
  const isLoggedIn =
    localStorage.getItem('isLoggedIn') === 'true' ||
    sessionStorage.getItem('isLoggedIn') === 'true';

  // 1. Check Login
  if (to.meta.requiresAuth && !isLoggedIn) {
    return next({ name: 'Login' });
  }

  // 2. Redirect logged in users from Login page
  if (to.name === 'Login' && isLoggedIn) {
    return next({ name: 'Dashboard' });
  }

  // 3. Check Staff Access
  if (to.meta.requiresStaff) {
    if (!isStaffOrAdmin()) {
      alert('No tienes permisos para acceder a esta sección. Solo el personal autorizado puede acceder al módulo de Soporte.');
      return next({ name: 'Dashboard' });
    }
  }

  // 4. Check Permissions
  if (to.meta.permission) {
    if (!hasPermission(to.meta.permission)) {
      // Redirect to dashboard or unauthorized page
        alert('No tienes permisos para acceder a esta sección.');
        return next({ name: 'Dashboard' });
    }
  }

  next();
});

export default router;
