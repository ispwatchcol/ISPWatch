import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import '../css/app.css'
import { OhVueIcon, addIcons } from "oh-vue-icons";
import {
    MdRouterRound,
    MdDashboardOutlined,
    PrUser,
    PrUsers,
    PrUserPlus,
    RiMapPinUserLine,
    BiRouter,
    OiDiffAdded,
    OiPackage,
    OiPackageDependencies,
    RiMoneyDollarCircleLine,
    LaMoneyBillWaveSolid,
    FaHeadphonesAlt,
    RiSettings4Line,
    HiBookOpen,
    HiChevronDown,
    BiSun,
    BiMoon,
    MdScreenshotmonitor,
    MdLogoutTwotone,
    HiWifi,
} from "oh-vue-icons/icons";

addIcons(
    MdRouterRound,
    MdDashboardOutlined,
    PrUser,
    PrUsers,
    PrUserPlus,
    RiMapPinUserLine,
    BiRouter,
    OiDiffAdded,
    OiPackage,
    OiPackageDependencies,
    RiMoneyDollarCircleLine,
    LaMoneyBillWaveSolid,
    FaHeadphonesAlt,
    RiSettings4Line,
    HiBookOpen,
    HiChevronDown,
    BiSun,
    BiMoon,
    MdScreenshotmonitor,
    MdLogoutTwotone,
    HiWifi,
);


const app = createApp(App);
app.component("v-icon", OhVueIcon);
app.use(router);
app.mount("#app");
