import { createRouter, createWebHistory } from 'vue-router';

// All page imports are lazy-loaded for optimal code-splitting
const routes = [
  // ─── PUBLIC ROUTES ───
  {
    path: '/',
    name: 'Login',
    component: () => import('@/pages/Login.vue'),
    meta: { title: 'Acceso' },
  },
  {
    path: '/register',
    name: 'Register',
    component: () => import('@/pages/Register.vue'),
    meta: { title: 'Registro' },
  },
  {
    path: '/resend-verification',
    name: 'ResendVerification',
    component: () => import('@/pages/ResendVerification.vue'),
    meta: { title: 'Verificación' },
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
        meta: { title: 'Panel' },
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
        meta: { title: 'Personal' },
      },
      {
        path: 'create',
        name: 'StaffNew',
        component: () => import('@/pages/StaffNew.vue'),
        meta: { title: 'Nuevo Personal' },
      },
      {
        path: ':id/edit',
        name: 'StaffEdit',
        component: () => import('@/pages/EditStaff.vue'),
        meta: { title: 'Editar Personal' },
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
        meta: { title: 'Routers' },
      },
      {
        path: 'add',
        name: 'RouterAdd',
        component: () => import('@/pages/RouterAdd.vue'),
        meta: { title: 'Nuevo Router' },
      },
      {
        path: ':id/edit',
        name: 'RouterEdit',
        component: () => import('@/pages/RouterEdit.vue'),
        meta: { title: 'Editar Router' },
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
        meta: { title: 'Planes' },
      },
      {
        path: 'create',
        name: 'PlanCreate',
        component: () => import('@/pages/PlanCreate.vue'),
        meta: { title: 'Nuevo Plan' },
      },
      {
        path: ':id/edit',
        name: 'plan-edit',
        component: () => import('@/pages/PlanEdit.vue'),
        props: true,
        meta: { title: 'Editar Plan' },
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
        meta: { title: 'Clientes' },
      },
      {
        path: 'create',
        name: 'CustomerAdd',
        component: () => import('@/pages/CustomerAdd.vue'),
        meta: { title: 'Nuevo Cliente' },
      },
      {
        path: ':id/edit',
        name: 'CustomerEdit',
        component: () => import('@/pages/CustomerEdit.vue'),
        meta: { title: 'Editar Cliente' },
      },
      {
        path: 'statistics',
        name: 'CustomerStatistics',
        component: () => import('@/pages/CustomerStatistics.vue'),
        meta: { title: 'Estadísticas' },
      },
      {
        path: 'map',
        name: 'CustomerMap',
        component: () => import('@/pages/CustomerMap.vue'),
        meta: { title: 'Mapa' },
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
        meta: { title: 'Sectoriales' },
      },
      {
        path: 'create',
        name: 'SectorialAdd',
        component: () => import('@/pages/SectorialAdd.vue'),
        meta: { title: 'Nueva Sectorial' },
      },
      {
        path: ':id/edit',
        name: 'SectorialEdit',
        component: () => import('@/pages/SectorialEdit.vue'),
        meta: { title: 'Editar Sectorial' },
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
        meta: { permission: 'support.view', title: 'Soporte' },
      },
      {
        path: 'create',
        name: 'SupportCreate',
        component: () => import('@/pages/SupportCreate.vue'),
        meta: { permission: 'support.create', title: 'Nuevo Ticket' },
      },
      {
        path: ':id',
        name: 'SupportDetail',
        component: () => import('@/pages/SupportDetail.vue'),
        meta: { title: 'Ticket' },
      },
      {
        path: ':id/edit',
        name: 'SupportEdit',
        component: () => import('@/pages/SupportEdit.vue'),
        meta: { permission: 'support.update', title: 'Editar Ticket' },
      },
      {
        path: 'statistics',
        name: 'SupportStatistics',
        component: () => import('@/pages/SupportStatistics.vue'),
        meta: { permission: 'support.statistics', title: 'Estadísticas Soporte' },
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
        name: 'BillingDashboard',
        component: () => import('@/pages/Billing/BillingDashboard.vue'),
        meta: { title: 'Facturación' },
      },
      {
        path: 'dashboard',
        redirect: { name: 'BillingDashboard' },
      },
      {
        path: 'invoices',
        name: 'InvoicesList',
        component: () => import('@/pages/Billing/InvoicesList.vue'),
        meta: { title: 'Facturas' },
      },
      {
        path: 'invoices/:id',
        name: 'InvoiceDetail',
        component: () => import('@/pages/Billing/InvoiceDetail.vue'),
        meta: { title: 'Factura' },
      },
      {
        path: 'payments',
        name: 'PaymentsList',
        component: () => import('@/pages/Billing/PaymentsList.vue'),
        meta: { title: 'Pagos' },
      },
      {
        path: 'payments/new',
        name: 'RegisterPayment',
        component: () => import('@/pages/Billing/RegisterPayment.vue'),
        meta: { title: 'Registrar Pago' },
      },
    ],
  },

  // ─── MASS ACTIONS ───
  {
    path: '/mass-actions',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'MassActions',
        component: () => import('@/pages/MassActions.vue'),
        meta: { title: 'Acciones Masivas' },
      },
    ],
  },

  {
    path: '/inventory',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'Inventory',
        component: () => import('@/pages/Inventory.vue'),
        meta: { title: 'Inventario' },
      },
      {
        path: 'create',
        name: 'InventoryCreate',
        component: () => import('@/pages/InventoryForm.vue'),
        meta: { title: 'Agregar Producto' },
      },
      {
        path: ':id/edit',
        name: 'InventoryEdit',
        component: () => import('@/pages/InventoryForm.vue'),
        meta: { title: 'Editar Producto' },
      },
      {
        path: 'stocks',
        name: 'InventoryStocks',
        component: () => import('@/pages/StockList.vue'),
        meta: { title: 'Stocks' },
      },
      {
        path: 'providers',
        name: 'InventoryProviders',
        component: () => import('@/pages/ProviderList.vue'),
        meta: { title: 'Proveedores' },
      },
      {
        path: 'branches',
        name: 'InventoryBranches',
        component: () => import('@/pages/BranchList.vue'),
        meta: { title: 'Sucursales' },
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
        meta: { title: 'Configuración' },
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
        meta: { title: 'Manual' },
      },
    ],
  },

  // ─── 404 ───
  {
    path: '/:pathMatch(.*)*',
    name: 'NotFound',
    component: () => import('@/pages/NotFound.vue'),
    meta: { title: 'Página no encontrada' },
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

// ─── NAVIGATION GUARD ───
// Uses Pinia auth store for centralized session validation
import { useAuthStore } from '@/stores/auth';

router.beforeEach((to, _from, next) => {
  const auth = useAuthStore();

  if (!auth.user) {
    auth.loadFromStorage();
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next({ name: 'Login' });
  }

  if (to.name === 'Login' && auth.isAuthenticated) {
    return next({ name: 'Dashboard' });
  }

  if (to.meta.requiresStaff && !auth.isStaffOrAdmin) {
    alert('No tienes permisos para acceder a esta sección. Solo el personal autorizado puede acceder al módulo de Soporte.');
    return next({ name: 'Dashboard' });
  }

  if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
    alert('No tienes permisos para acceder a esta sección.');
    return next({ name: 'Dashboard' });
  }

  const pageTitle = to.meta.title;
  document.title = pageTitle ? `ISPWatch | ${pageTitle}` : 'ISPWatch';

  next();
});

export default router;
