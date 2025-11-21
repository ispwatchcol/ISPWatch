// resources/js/app.js
import { createApp } from "vue";
import App from "./App.vue";
import router from "./router"; // Importamos el router
import { supabase } from "./supabase"; // Importamos Supabase
import "../css/app.css"; // Importa tus estilos globales

// -------------------------------
// CONFIGURACIÓN DE OH-VUE-ICONS
// -------------------------------
import { OhVueIcon, addIcons } from "oh-vue-icons";

import {
  PrUser,
  PrUsers,
  PrUserPlus,
  RiMapPinUserLine,
  FaDollarSign,
  OiAlert,
  HiTrendingUp,
  BiActivity,
  RiMapPinLine,
  RiSettings4Line,
  FaRegularBell,
  IoCalendar,
  HiWifi,
  BiSun,
  BiMoon,
  MdScreenshotmonitor,
  MdLogoutTwotone,
  MdDashboardOutlined,
  BiRouter,
  OiDiffAdded,
  OiPackage,
  RiMoneyDollarCircleLine,
  FaHeadphonesAlt,
  HiBookOpen,
  MdRouterRound,
  MdSupportagentRound,
  HiChevronDown,
  FaArrowLeft,
  FaUserEdit,
  PrServer    // ✅ Aquí está correctamente importado SOLO UNA VEZ
} from "oh-vue-icons/icons";

// REGISTRAR ICONOS
addIcons(
  PrUser,
  PrUsers,
  PrUserPlus,
  RiMapPinUserLine,
  FaDollarSign,
  OiAlert,
  HiTrendingUp,
  BiActivity,
  RiMapPinLine,
  RiSettings4Line,
  FaUserEdit,
  FaRegularBell,
  IoCalendar,
  HiWifi,
  BiSun,
  BiMoon,
  MdScreenshotmonitor,
  MdLogoutTwotone,
  MdDashboardOutlined,
  BiRouter,
  OiDiffAdded,
  OiPackage,
  RiMoneyDollarCircleLine,
  FaHeadphonesAlt,
  HiBookOpen,
  MdRouterRound,
  MdSupportagentRound,
  HiChevronDown,
  FaArrowLeft,
  PrServer    // ✅ Registrado aquí también
);

// -------------------------------
// CREACIÓN DE LA APP
// -------------------------------
const app = createApp(App);

// Hacer que Supabase esté disponible globalmente como $supabase
app.config.globalProperties.$supabase = supabase;

// Registrar OhVueIcon como componente global
app.component("v-icon", OhVueIcon);

// Usar el router
app.use(router);

// Montar la aplicación
app.mount("#app");
