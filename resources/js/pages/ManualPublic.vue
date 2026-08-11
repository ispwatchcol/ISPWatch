<script setup>
/**
 * Manual PÚBLICO (/ayuda): cualquiera con el link lo abre, sin iniciar sesión.
 * Sirve para pasárselo a un ISP que todavía no tiene cuenta, o para que un
 * técnico lo consulte desde el celular sin loguearse.
 *
 * No usa DefaultLayout porque ese layout monta el Sidebar, que depende del
 * store de sesión (permisos, tenant, usuario) que un invitado no tiene. El
 * contenido es el MISMO componente que consume /manual, así que ambos se
 * actualizan juntos.
 *
 * Es de sólo lectura y no expone ningún dato del tenant: el texto está
 * compilado en el bundle, no sale de la API.
 */
import { onMounted } from 'vue';
import ManualContent from '@/components/ManualContent.vue';

const year = new Date().getFullYear();

onMounted(() => {
    // El guard del router pone el título con el prefijo "ISPWatch | …", pero
    // esta página también se comparte por link suelto: le damos uno completo.
    document.title = 'Manual de Usuario — ISPWatch';
});
</script>

<template>
    <div class="manual-theme manual-public">
        <!-- Barra superior: identidad + puerta de entrada a la app -->
        <header class="mp-bar">
            <div class="mp-bar-in">
                <div class="mp-brand">
                    <!-- Ruta enlazada, no literal: si no, Vite intenta
                         resolverla como módulo y falla el build. -->
                    <img :src="'/brand/icon.svg'" alt="" class="mp-logo" />
                    <div>
                        <p class="mp-name">ISP<span>Watch</span></p>
                        <p class="mp-tag">Gestión de proveedores de internet</p>
                    </div>
                </div>

                <RouterLink to="/" class="mp-login">Iniciar sesión</RouterLink>
            </div>
        </header>

        <ManualContent public-mode />

        <footer class="mp-foot">
            <div class="mp-foot-in">
                <p>ISPWatch &middot; {{ year }}</p>
                <p>Manual de usuario · versión de referencia</p>
            </div>
        </footer>
    </div>
</template>

<style>
/* Hereda los tokens de .manual-theme, definidos en ManualContent.vue: la barra
   y el pie no repiten ni un color. */

.manual-public { background: var(--ground); min-height: 100vh; }

/* El manual ya no necesita llenar la pantalla: lo hace el wrapper. */
.manual-public .manual-doc { min-height: 0; }

.manual-public .mp-bar {
    background: var(--surface);
    border-bottom: 1px solid var(--rule);
}
.manual-public .mp-bar-in {
    max-width: 1220px;
    margin: 0 auto;
    padding: 0 28px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}
.manual-public .mp-brand { display: flex; align-items: center; gap: 11px; min-width: 0; }
.manual-public .mp-logo { width: 34px; height: 34px; flex: none; }
.manual-public .mp-name {
    font-family: var(--f-display);
    font-size: 17px;
    font-weight: 800;
    letter-spacing: -.02em;
    color: var(--ink);
    margin: 0;
    line-height: 1.15;
}
.manual-public .mp-name span { color: var(--accent); }
.manual-public .mp-tag {
    font-family: var(--f-mono);
    font-size: 9.5px;
    letter-spacing: .13em;
    text-transform: uppercase;
    color: var(--ink-mute);
    margin: 2px 0 0;
}
.manual-public .mp-login {
    flex: none;
    display: inline-flex;
    align-items: center;
    padding: 8px 16px;
    border-radius: 8px;
    background: var(--accent);
    color: #fff;
    font-family: var(--f-display);
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: filter .15s;
}
.manual-public .mp-login:hover { filter: brightness(1.08); }
.manual-public .mp-login:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}

.manual-public .mp-foot {
    background: var(--surface);
    border-top: 1px solid var(--rule);
}
.manual-public .mp-foot-in {
    max-width: 1220px;
    margin: 0 auto;
    padding: 22px 28px;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px 20px;
    font-family: var(--f-mono);
    font-size: 11px;
    color: var(--ink-mute);
}
.manual-public .mp-foot-in p { margin: 0; }

@media (max-width: 900px) {
    .manual-public .mp-bar-in { padding: 0 20px; }
    .manual-public .mp-foot-in { padding: 20px; }
    .manual-public .mp-tag { display: none; }
}
</style>
