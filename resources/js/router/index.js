import { createRouter, createWebHistory } from 'vue-router';

// All page imports are lazy-loaded for optimal code-splitting
const routes = [
  // ─── PUBLIC ROUTES ───
  {
    path: '/',
    name: 'Login',
    component: () => import('@/pages/Login.vue'),
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('@/pages/Register.vue'),
  },
  {
    path: '/resend-verification',
    name: 'ResendVerification',
    component: () => import('@/pages/ResendVerification.vue'),
  },

  // ─── DASHBOARD ───
  {
    path: '/dashboard',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Dashboard',
        component: () => import('@/pages/Dashboard.vue'),
      },
    ],
  },

  // ─── STAFF ───
  {
    path: '/staff',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Staff',
        component: () => import('@/pages/Staff.vue'),
      },
      {
        path: 'create',
        name: 'StaffNew',
        component: () => import('@/pages/StaffNew.vue'),
      },
      {
        path: ':id/edit',
        name: 'StaffEdit',
        component: () => import('@/pages/EditStaff.vue'),
      },
    ],
  },

  // ─── ROUTERS ───
  {
    path: '/routers',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Routers',
        component: () => import('@/pages/Routers.vue'),
      },
      {
        path: 'add',
        name: 'RouterAdd',
        component: () => import('@/pages/RouterAdd.vue'),
      },
      {
        path: ':id/edit',
        name: 'RouterEdit',
        component: () => import('@/pages/RouterEdit.vue'),
      },
    ],
  },

  // ─── PLANS ───
  {
    path: '/planes',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'PlanList',
        component: () => import('@/pages/PlanList.vue'),
      },
      {
        path: 'create',
        name: 'PlanCreate',
        component: () => import('@/pages/PlanCreate.vue'),
      },
      {
        path: ':id/edit',
        name: 'plan-edit',
        component: () => import('@/pages/PlanEdit.vue'),
        props: true,
      },
    ],
  },

  // ─── CUSTOMERS ───
  {
    path: '/customers',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Customers',
        component: () => import('@/pages/Customers.vue'),
      },
      {
        path: 'create',
        name: 'CustomerAdd',
        component: () => import('@/pages/CustomerAdd.vue'),
      },
      {
        path: ':id/edit',
        name: 'CustomerEdit',
        component: () => import('@/pages/CustomerEdit.vue'),
      },
      {
        path: 'statistics',
        name: 'CustomerStatistics',
        component: () => import('@/pages/CustomerStatistics.vue'),
      },
      {
        path: 'map',
        name: 'CustomerMap',
        component: () => import('@/pages/CustomerMap.vue'),
      },
    ],
  },

  // ─── SECTORIALS ───
  {
    path: '/sectorials',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Sectorials',
        component: () => import('@/pages/Sectorial.vue'),
      },
      {
        path: 'create',
        name: 'SectorialAdd',
        component: () => import('@/pages/SectorialAdd.vue'),
      },
      {
        path: ':id/edit',
        name: 'SectorialEdit',
        component: () => import('@/pages/SectorialEdit.vue'),
      },
    ],
  },

  // ─── SUPPORT ───
  {
    path: '/support',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, requiresStaff: true },
    children: [
      {
        path: '',
        name: 'Support',
        component: () => import('@/pages/Support.vue'),
        meta: { permission: 'support.view' },
      },
      {
        path: 'create',
        name: 'SupportCreate',
        component: () => import('@/pages/SupportCreate.vue'),
        meta: { permission: 'support.create' },
      },
      {
        path: ':id',
        name: 'SupportDetail',
        component: () => import('@/pages/SupportDetail.vue'),
      },
      {
        path: ':id/edit',
        name: 'SupportEdit',
        component: () => import('@/pages/SupportEdit.vue'),
        meta: { permission: 'support.update' },
      },
      {
        path: 'statistics',
        name: 'SupportStatistics',
        component: () => import('@/pages/SupportStatistics.vue'),
        meta: { permission: 'support.statistics' },
      },
    ],
  },

  // ─── BILLING ───
  {
    path: '/billing',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'BillingSummary',
        component: () => import('@/pages/Billing/BillingDashboard.vue'),
      },
      {
        path: 'dashboard',
        name: 'BillingDashboard',
        component: () => import('@/pages/Billing/BillingDashboard.vue'),
      },
      {
        path: 'invoices',
        name: 'InvoicesList',
        component: () => import('@/pages/Billing/InvoicesList.vue'),
      },
      {
        path: 'invoices/:id',
        name: 'InvoiceDetail',
        component: () => import('@/pages/Billing/InvoiceDetail.vue'),
      },
      {
        path: 'payments',
        name: 'PaymentsList',
        component: () => import('@/pages/Billing/PaymentsList.vue'),
      },
      {
        path: 'payments/new',
        name: 'RegisterPayment',
        component: () => import('@/pages/Billing/RegisterPayment.vue'),
      },
    ],
  },

  // ─── INVENTORY ───
  {
    path: '/inventory',
    component: () => import('@/layouts/DefaultLayout.vue'),
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
      },
      {
        path: 'stocks',
        name: 'InventoryStocks',
        component: () => import('@/pages/StockList.vue'),
      },
      {
        path: 'providers',
        name: 'InventoryProviders',
        component: () => import('@/pages/ProviderList.vue'),
      },
      {
        path: 'branches',
        name: 'InventoryBranches',
        component: () => import('@/pages/BranchList.vue'),
      },
    ],
  },

  // ─── SETTINGS ───
  {
    path: '/settings',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Settings',
        component: () => import('@/pages/Settings.vue'),
      },
    ],
  },

  // ─── MANUAL ───
  {
    path: '/manual',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Manual',
        component: () => import('@/pages/Manual.vue'),
      },
    ],
  },

  // ─── 404 ───
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

// ─── NAVIGATION GUARD ───
// Uses Pinia auth store for centralized session validation
import { useAuthStore } from '@/stores/auth';

router.beforeEach((to, from, next) => {
  const auth = useAuthStore();

  // Ensure user data is loaded from storage
  if (!auth.user) {
    auth.loadFromStorage();
  }

  // 1. Auth check
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next({ name: 'Login' });
  }

  // 2. Redirect logged-in users from Login page
  if (to.name === 'Login' && auth.isAuthenticated) {
    return next({ name: 'Dashboard' });
  }

  // 3. Staff access check
  if (to.meta.requiresStaff && !auth.isStaffOrAdmin) {
    alert('No tienes permisos para acceder a esta sección. Solo el personal autorizado puede acceder al módulo de Soporte.');
    return next({ name: 'Dashboard' });
  }

  // 4. Permission check
  if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
    alert('No tienes permisos para acceder a esta sección.');
    return next({ name: 'Dashboard' });
  }

  next();
});

export default router;
