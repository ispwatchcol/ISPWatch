import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";
import '../css/app.css'
import { OhVueIcon, addIcons } from "oh-vue-icons";
import {
    MdRouterRound,
    MdDashboardOutlined,
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
} from "oh-vue-icons/icons";

addIcons(
    MdRouterRound,
    MdDashboardOutlined,
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
    MdScreenshotmonitor
);


const app = createApp(App);
app.component("v-icon", OhVueIcon);
app.use(router);
app.mount("#app");
