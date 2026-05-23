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
    meta: { requiresAuth: true, permission: 'staff.view' },
    children: [
      {
        path: '',
        name: 'Staff',
        component: () => import('@/pages/Staff.vue'),
        meta: { title: 'Personal', permission: 'staff.view' },
      },
      {
        path: 'create',
        name: 'StaffNew',
        component: () => import('@/pages/StaffNew.vue'),
        meta: { title: 'Nuevo Personal', permission: 'staff.create' },
      },
      {
        path: ':id/edit',
        name: 'StaffEdit',
        component: () => import('@/pages/EditStaff.vue'),
        meta: { title: 'Editar Personal', permission: 'staff.edit' },
      },
    ],
  },

  // ─── ROLES ───
  {
    path: '/roles',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'RolesManagement',
        component: () => import('@/pages/RolesManagement.vue'),
        meta: { title: 'Administración de Roles' },
      },
    ],
  },

  // ─── ROUTERS ───
  {
    path: '/routers',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'routers.view' },
    children: [
      {
        path: '',
        name: 'Routers',
        component: () => import('@/pages/Routers.vue'),
        meta: { title: 'Routers', permission: 'routers.view' },
      },
      {
        path: 'add',
        name: 'RouterAdd',
        component: () => import('@/pages/RouterAdd.vue'),
        meta: { title: 'Nuevo Router', permission: 'routers.create' },
      },
      {
        path: ':id/edit',
        name: 'RouterEdit',
        component: () => import('@/pages/RouterEdit.vue'),
        meta: { title: 'Editar Router', permission: 'routers.edit' },
      },
    ],
  },

  // ─── PLANS ───
  {
    path: '/planes',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'plans.view' },
    children: [
      {
        path: '',
        name: 'PlanList',
        component: () => import('@/pages/PlanList.vue'),
        meta: { title: 'Planes', permission: 'plans.view' },
      },
      {
        path: 'create',
        name: 'PlanCreate',
        component: () => import('@/pages/PlanCreate.vue'),
        meta: { title: 'Nuevo Plan', permission: 'plans.create' },
      },
      {
        path: ':id/edit',
        name: 'plan-edit',
        component: () => import('@/pages/PlanEdit.vue'),
        props: true,
        meta: { title: 'Editar Plan', permission: 'plans.edit' },
      },
    ],
  },

  // ─── CUSTOMERS ───
  {
    path: '/customers',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'customers.view' },
    children: [
      {
        path: '',
        name: 'Customers',
        component: () => import('@/pages/Customers.vue'),
        meta: { title: 'Clientes', permission: 'customers.view' },
      },
      {
        path: 'create',
        name: 'CustomerAdd',
        component: () => import('@/pages/CustomerAdd.vue'),
        meta: { title: 'Nuevo Cliente', permission: 'customers.create' },
      },
      {
        path: ':id/edit',
        name: 'CustomerEdit',
        component: () => import('@/pages/CustomerEdit.vue'),
        meta: { title: 'Editar Cliente', permission: 'customers.edit' },
      },
      {
        path: 'statistics',
        name: 'CustomerStatistics',
        component: () => import('@/pages/CustomerStatistics.vue'),
        meta: { title: 'Estadísticas', permission: 'customers.stats' },
      },
      {
        path: 'map',
        name: 'CustomerMap',
        component: () => import('@/pages/CustomerMap.vue'),
        meta: { title: 'Mapa', permission: 'customers.map' },
      },
    ],
  },

  // ─── SECTORIALS ───
  {
    path: '/sectorials',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'sectorials.view' },
    children: [
      {
        path: '',
        name: 'Sectorials',
        component: () => import('@/pages/Sectorial.vue'),
        meta: { title: 'Sectoriales', permission: 'sectorials.view' },
      },
      {
        path: 'create',
        name: 'SectorialAdd',
        component: () => import('@/pages/SectorialAdd.vue'),
        meta: { title: 'Nueva Sectorial', permission: 'sectorials.create' },
      },
      {
        path: ':id/edit',
        name: 'SectorialEdit',
        component: () => import('@/pages/SectorialEdit.vue'),
        meta: { title: 'Editar Sectorial', permission: 'sectorials.edit' },
      },
    ],
  },

  // ─── INSTALLATIONS ───
  {
    path: '/installations',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'support.view' },
    children: [
      {
        path: '',
        name: 'Installations',
        component: () => import('@/pages/Installations.vue'),
        meta: { title: 'Instalaciones', permission: 'support.view' },
      },
    ],
  },

  // ─── SUPPORT ───
  {
    path: '/support',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'support.view' },
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
        meta: { title: 'Ticket', permission: 'support.view' },
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
    meta: { requiresAuth: true, permission: 'billing.view' },
    children: [
      {
        path: '',
        name: 'BillingDashboard',
        component: () => import('@/pages/Billing/BillingDashboard.vue'),
        meta: { title: 'Facturación', permission: 'billing.view' },
      },
      {
        path: 'dashboard',
        redirect: { name: 'BillingDashboard' },
      },
      {
        path: 'invoices',
        name: 'InvoicesList',
        component: () => import('@/pages/Billing/InvoicesList.vue'),
        meta: { title: 'Facturas', permission: 'billing.view' },
      },
      {
        path: 'invoices/:id',
        name: 'InvoiceDetail',
        component: () => import('@/pages/Billing/InvoiceDetail.vue'),
        meta: { title: 'Factura', permission: 'billing.view' },
      },
      {
        path: 'payments',
        name: 'PaymentsList',
        component: () => import('@/pages/Billing/PaymentsList.vue'),
        meta: { title: 'Pagos', permission: 'billing.view' },
      },
      {
        path: 'payments/new',
        name: 'RegisterPayment',
        component: () => import('@/pages/Billing/RegisterPayment.vue'),
        meta: { title: 'Registrar Pago', permission: 'billing.payments' },
      },
      {
        path: 'payment-methods',
        name: 'PaymentMethods',
        component: () => import('@/pages/Billing/PaymentMethods.vue'),
        meta: { title: 'Formas de Pago', permission: 'billing.view' },
      },
      {
        path: 'additional-charges',
        name: 'AdditionalCharges',
        component: () => import('@/pages/Billing/AdditionalCharges.vue'),
        meta: { title: 'Servicios Adicionales', permission: 'billing.view' },
      },
    ],
  },

  // ─── MASS ACTIONS ───
  {
    path: '/mass-actions',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'mass_actions.execute' },
    children: [
      {
        path: '',
        name: 'MassActions',
        component: () => import('@/pages/MassActions.vue'),
        meta: { title: 'Acciones Masivas', permission: 'mass_actions.execute' },
      },
    ],
  },

  {
    path: '/inventory',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'inventory.view' },
    children: [
      {
        path: '',
        name: 'Inventory',
        component: () => import('@/pages/Inventory.vue'),
        meta: { title: 'Inventario', permission: 'inventory.view' },
      },
      {
        path: 'create',
        name: 'InventoryCreate',
        component: () => import('@/pages/InventoryForm.vue'),
        meta: { title: 'Agregar Producto', permission: 'inventory.create' },
      },
      {
        path: ':id/edit',
        name: 'InventoryEdit',
        component: () => import('@/pages/InventoryForm.vue'),
        meta: { title: 'Editar Producto', permission: 'inventory.edit' },
      },
      {
        path: 'stocks',
        name: 'InventoryStocks',
        component: () => import('@/pages/StockList.vue'),
        meta: { title: 'Stocks', permission: 'inventory.view' },
      },
      {
        path: 'providers',
        name: 'InventoryProviders',
        component: () => import('@/pages/ProviderList.vue'),
        meta: { title: 'Proveedores', permission: 'inventory.view' },
      },
      {
        path: 'branches',
        name: 'InventoryBranches',
        component: () => import('@/pages/BranchList.vue'),
        meta: { title: 'Sucursales', permission: 'inventory.view' },
      },
    ],
  },

  // ─── SETTINGS ───
  {
    path: '/settings',
    component: () => import('@/layouts/DefaultLayout.vue'),
    meta: { requiresAuth: true, permission: 'settings.view' },
    children: [
      {
        path: '',
        name: 'Settings',
        component: () => import('@/pages/Settings.vue'),
        meta: { title: 'Configuración', permission: 'settings.view' },
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
    return next({ name: 'Dashboard' });
  }

  if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
    return next({ name: 'Dashboard' });
  }

  const pageTitle = to.meta.title;
  document.title = pageTitle ? `ISPWatch | ${pageTitle}` : 'ISPWatch';

  next();
});

export default router;
