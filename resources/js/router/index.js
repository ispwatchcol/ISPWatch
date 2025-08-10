import { createRouter, createWebHistory } from 'vue-router';
import DefaultLayout from '../layouts/DefaultLayout.vue';
import Dashboard from '../pages/Dashboard.vue';


const routes = [
    {
        path: "/",
        component: DefaultLayout,
        children: [
            {
                path: "",
                name: "dashboard",
                component: Dashboard,
            },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

export default router;