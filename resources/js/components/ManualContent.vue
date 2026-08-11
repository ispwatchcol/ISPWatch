<script setup>
/**
 * Contenido del manual de usuario. Es el ESPEJO de docs/MANUAL_USUARIO.md — si
 * corriges algo aquí, corrígelo también allí. Los dos ya se separaron una vez y
 * el de la app acumuló información falsa durante meses; por eso este manual es
 * estático y vive en el repositorio, y no en una tabla que cualquiera pueda
 * editar sin dejar rastro en el control de versiones.
 *
 * Lo consumen DOS páginas, para que no haya dos textos que mantener:
 *   · pages/Manual.vue       → /manual, dentro de la app (con sesión)
 *   · pages/ManualPublic.vue → /ayuda, público, sin login
 *
 * El Centro de Ayuda editable (categorías + artículos con Quill) sigue
 * existiendo en /centro-ayuda → pages/HelpCenter.vue.
 */
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';

const props = defineProps({
    /**
     * Modo público: se renderiza sin sesión, así que no puede enlazar a rutas
     * autenticadas (el guard las rebotaría al login).
     */
    publicMode: { type: Boolean, default: false },
});

const sections = [
    { id: 'que-es',        n: '01', title: 'Qué es ISPWatch' },
    { id: 'ingresar',      n: '02', title: 'Iniciar sesión' },
    { id: 'navegar',       n: '03', title: 'Cómo moverse por el sistema' },
    { id: 'panel',         n: '04', title: 'El Panel (Dashboard)' },
    { id: 'clientes',      n: '05', title: 'Clientes' },
    { id: 'instalaciones', n: '06', title: 'Prospectos e instalaciones' },
    { id: 'facturacion',   n: '07', title: 'Facturación' },
    { id: 'pagos',         n: '08', title: 'Pagos y recaudos' },
    { id: 'cortes',        n: '09', title: 'Corte y reconexión de morosos' },
    { id: 'gastos',        n: '10', title: 'Gastos' },
    { id: 'routers',       n: '11', title: 'Routers y red' },
    { id: 'sectoriales',   n: '12', title: 'Sectoriales y fibra óptica' },
    { id: 'planes',        n: '13', title: 'Planes de internet' },
    { id: 'soporte',       n: '14', title: 'Soporte técnico' },
    { id: 'inventario',    n: '15', title: 'Inventario' },
    { id: 'personal',      n: '16', title: 'Personal y roles' },
    { id: 'configuracion', n: '17', title: 'Configuración de la empresa' },
    { id: 'masivas',       n: '18', title: 'Acciones masivas' },
    { id: 'faq',           n: '19', title: 'Preguntas frecuentes' },
];

const activeId = ref('que-es');
const search = ref('');

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return sections;
    return sections.filter((s) => s.title.toLowerCase().includes(q) || s.n.includes(q));
});

let observer = null;

function goTo(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    activeId.value = id;
}

onMounted(() => {
    nextTick(() => {
        // La banda -25%/-65% marca la sección "en lectura": la que ocupa el
        // tercio superior del viewport, no la que apenas asoma por abajo.
        observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((e) => {
                    if (e.isIntersecting) activeId.value = e.target.id;
                });
            },
            { rootMargin: '-25% 0px -65% 0px', threshold: 0 }
        );
        sections.forEach((s) => {
            const el = document.getElementById(s.id);
            if (el) observer.observe(el);
        });
    });
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
<div class="manual-doc manual-theme">

    <!-- ══ Masthead ══════════════════════════════════════════════════════ -->
    <header class="masthead">
        <div class="masthead-in">
            <div class="wordmark">
                <span class="mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.9"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 6.04A8.97 8.97 0 006 3.75c-1.05 0-2.06.18-3 .51v14.25A8.99 8.99 0 016 18c2.3 0 4.41.87 6 2.29m0-14.25a8.97 8.97 0 016-2.29c1.05 0 2.06.18 3 .51v14.25A8.99 8.99 0 0018 18a8.97 8.97 0 00-6 2.29m0-14.25v14.25"/>
                    </svg>
                </span>
                <div>
                    <h1>Manual de usuario</h1>
                    <p>ISPWatch &middot; Gestión de proveedores de internet</p>
                </div>
            </div>
            <div class="masthead-meta">
                Para <b>administradores, staff y técnicos</b><br>
                19 secciones &middot; versión de referencia
            </div>
        </div>
    </header>

    <div class="shell">

        <!-- ══ Índice ════════════════════════════════════════════════════ -->
        <nav class="toc" aria-label="Índice del manual">
            <p class="toc-label">Contenido</p>

            <input
                v-model="search"
                type="search"
                class="toc-search"
                placeholder="Buscar sección…"
                aria-label="Buscar sección"
            />

            <select
                class="toc-select"
                :value="activeId"
                aria-label="Ir a una sección"
                @change="goTo($event.target.value)"
            >
                <option v-for="s in sections" :key="s.id" :value="s.id">
                    {{ s.n }} · {{ s.title }}
                </option>
            </select>

            <ol>
                <li v-for="s in filtered" :key="s.id">
                    <a
                        :href="`#${s.id}`"
                        :class="{ on: activeId === s.id }"
                        @click.prevent="goTo(s.id)"
                    >
                        <span class="n">{{ s.n }}</span>
                        <span>{{ s.title }}</span>
                    </a>
                </li>
            </ol>

            <p v-if="filtered.length === 0" class="toc-empty">
                Sin resultados para «{{ search }}».
            </p>
        </nav>

        <!-- ══ Contenido ═════════════════════════════════════════════════ -->
        <main class="doc">

            <p class="lede">
                ISPWatch es donde tu empresa de internet lleva <strong>todo</strong>: los clientes,
                lo que cada uno paga, los equipos de la red y el soporte técnico. Este manual cubre
                <strong>cada módulo del sistema</strong>, en el orden en que los vas a necesitar, y
                está escrito sin dar por sentado ningún conocimiento técnico.
            </p>

            <!-- 01 ─────────────────────────────────────────────────────── -->
            <section id="que-es">
                <span class="sec-num">01</span>
                <h2>Qué es ISPWatch</h2>

                <div class="thesis">
                    <span class="tag">La idea que lo explica todo</span>
                    <p>ISPWatch no es una hoja de cálculo bonita: está conectado con los routers de
                    verdad.</p>
                    <p>Cuando das de alta a un cliente, el sistema lo configura solo en el equipo.
                    Cuando se atrasa en el pago, le corta el internet solo. Y cuando paga, se lo
                    devuelve solo. No hay que entrar a ningún equipo a mano.</p>
                </div>

                <p>De ahí sale casi todo lo que puede sorprenderte al principio: por qué la
                facturación se configura por router y no por empresa, por qué la palabra «VPN»
                aparece tanto en los diagnósticos, y por qué un cliente puede figurar como cortado
                en pantalla y seguir navegando en la realidad.</p>
            </section>

            <!-- 02 ─────────────────────────────────────────────────────── -->
            <section id="ingresar">
                <span class="sec-num">02</span>
                <h2>Iniciar sesión</h2>

                <ol class="steps">
                    <li><span class="k">1</span><div><b>Abre la dirección de tu empresa</b><span>Por ejemplo <code>https://ispwatch-crm.app</code>.</span></div></li>
                    <li><span class="k">2</span><div><b>Escribe tu correo de acceso</b><span>Ojo: <strong>no es tu correo personal</strong>. Es uno que crea el sistema, con la forma <code>nombre.apellido@nombre-de-tu-empresa</code> — por ejemplo <code>maria.gomez@mi-isp</code>.</span></div></li>
                    <li><span class="k">3</span><div><b>Escribe tu contraseña</b><span>Marca <em>Recordarme</em> si quieres que el sistema te recuerde en este computador.</span></div></li>
                    <li><span class="k">4</span><div><b>Pulsa Ingresar</b><span></span></div></li>
                </ol>

                <h3>Si algo sale mal</h3>
                <div class="tw"><table>
                    <thead><tr><th>Mensaje</th><th>Qué significa</th><th>Qué hacer</th></tr></thead>
                    <tbody>
                        <tr><td><em>Credenciales incorrectas</em></td><td>El usuario o la contraseña no coinciden</td><td>Revisa que uses el correo <strong>de acceso</strong>, no el personal</td></tr>
                        <tr><td><em>Por favor verifica tu correo…</em></td><td>Tu cuenta existe pero nunca confirmaste el correo</td><td>Busca el correo de confirmación (revisa también el correo no deseado). Si no llegó, usa <strong>Reenviar verificación</strong></td></tr>
                        <tr><td><em>Demasiados intentos. Espera N segundos</em></td><td>Fallaste la contraseña 5 veces en un minuto</td><td>Espera el tiempo que indica e inténtalo de nuevo</td></tr>
                        <tr><td><em>Entrada no válida detectada</em></td><td>Escribiste caracteres que el sistema bloquea por seguridad (comillas, punto y coma)</td><td>Escribe sólo el correo, sin símbolos raros</td></tr>
                    </tbody>
                </table></div>

                <div class="note">
                    <span class="tag">Si te expulsa solo del sistema</span>
                    <p>Tu sesión caducó. Vuelve a entrar: no se pierde nada de lo que ya habías
                    guardado.</p>
                </div>
            </section>

            <!-- 03 ─────────────────────────────────────────────────────── -->
            <section id="navegar">
                <span class="sec-num">03</span>
                <h2>Cómo moverse por el sistema</h2>

                <p>A la izquierda está el <strong>menú lateral</strong>. Los grupos que ves dependen
                de tu rol: <strong>si no ves una sección, es porque tu usuario no tiene permiso para
                ella</strong>.</p>

                <div class="tw"><table>
                    <thead><tr><th>Grupo</th><th>Contiene</th><th>Se muestra si tienes</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Dashboard</strong></td><td>Resumen general</td><td>Permiso de estadísticas</td></tr>
                        <tr><td><strong>Usuarios</strong></td><td>Lista de clientes, agregar cliente, estadísticas, mapa</td><td>Ver clientes</td></tr>
                        <tr><td><strong>Soporte</strong></td><td>Tickets, nuevo ticket, instalaciones, estadísticas</td><td>Ver soporte</td></tr>
                        <tr><td><strong>Gestión</strong></td><td>Routers, planes de internet, sectoriales, topología FTTH</td><td>Cualquiera de los tres</td></tr>
                        <tr><td><strong>Inventarios</strong></td><td>Equipos, stock/modelos, proveedores, sucursales</td><td>Ver inventario</td></tr>
                        <tr><td><strong>Finanzas</strong></td><td>Resumen, facturación, pagos, formas de pago, tipos de factura, servicios adicionales, gastos, categorías</td><td>Ver facturación <strong>o</strong> ver gastos</td></tr>
                        <tr><td><strong>Personal</strong></td><td>Empleados y técnicos</td><td>Ver personal</td></tr>
                        <tr><td><strong>Acciones masivas</strong></td><td>Cargas por Excel y paneles de reintentos</td><td>Ejecutar acciones masivas</td></tr>
                        <tr><td><strong>Configuración</strong></td><td>Datos de la empresa, plantillas, ajustes</td><td>Ver ajustes</td></tr>
                        <tr><td><strong>Manual</strong></td><td>Este manual y el centro de ayuda</td><td>Todos</td></tr>
                    </tbody>
                </table></div>

                <div class="note">
                    <span class="tag">Importante</span>
                    <p>Si necesitas ver una sección y no aparece, <strong>no es un error del
                    sistema</strong>. Pídele a un administrador que revise tu rol en
                    <em>Personal → Roles</em>.</p>
                </div>
            </section>

            <!-- 04 ─────────────────────────────────────────────────────── -->
            <section id="panel">
                <span class="sec-num">04</span>
                <h2>El Panel (Dashboard)</h2>

                <p>Es la primera pantalla al entrar. Muestra de un vistazo:</p>

                <div class="tw"><table>
                    <thead><tr><th>Tarjeta</th><th>Qué cuenta exactamente</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Clientes</strong></td><td><strong>Totales</strong>: clientes habilitados. <strong>Activos</strong>: los que además tienen el servicio prendido. <strong>Suspendidos</strong>: la resta de los dos</td></tr>
                        <tr><td><strong>Ingresos del mes</strong></td><td>El <strong>dinero que entró</strong> este mes: la suma de los pagos con fecha de este mes. No es lo facturado</td></tr>
                        <tr><td><strong>Saldo pendiente</strong></td><td>Lo que el conjunto de clientes debe, de <strong>todos</strong> los meses, no sólo del actual</td></tr>
                        <tr><td><strong>Tasa de recaudo</strong></td><td>Qué porcentaje de lo facturado este mes ya se cobró. Un 0 % puede significar que aún no se ha emitido nada</td></tr>
                        <tr><td><strong>Tickets</strong></td><td>Abiertos (incluye los que están en progreso) y urgentes</td></tr>
                        <tr><td><strong>Infraestructura</strong></td><td>Sectoriales y routers registrados</td></tr>
                        <tr><td><strong>Actividad reciente</strong></td><td>Últimos movimientos del sistema</td></tr>
                    </tbody>
                </table></div>

                <div class="note stop">
                    <span class="tag">La alerta más importante del panel</span>
                    <p>Los <strong>routers con falla general</strong>. Si un equipo está marcado en
                    falla masiva aparece resaltado con su nombre e IP. Ver <a href="#routers" @click.prevent="goTo('routers')">sección 11</a>.</p>
                </div>

                <h3>Dos cosas que suelen confundir</h3>
                <ul>
                    <li><strong>«Ingresos del mes» cuenta pagos, no facturas.</strong> Un cliente que paga en agosto una factura de julio suma a agosto. Si quieres ver lo <em>facturado</em>, ve a <em>Finanzas → Facturación</em>.</li>
                    <li><strong>«Saldo pendiente» arrastra deuda vieja.</strong> No mide el mes: mide todo lo que está sin pagar. Por eso puede subir aunque este mes se haya cobrado bien.</li>
                </ul>
            </section>

            <!-- 05 ─────────────────────────────────────────────────────── -->
            <section id="clientes">
                <span class="sec-num">05</span>
                <h2>Clientes</h2>

                <h3>Ver la lista</h3>
                <p><em>Usuarios → Lista de usuarios.</em> Puedes <strong>buscar</strong> por nombre,
                cédula, IP o correo, <strong>ordenar</strong> haciendo clic en el encabezado de una
                columna y pasar de página con los controles de abajo.</p>
                <p>La búsqueda <strong>ya no distingue mayúsculas</strong>: buscar <code>eliud</code>
                encuentra a <em>Eliud</em>. Antes no era así y podía parecer que un cliente no
                existía.</p>

                <h3>Crear un cliente</h3>
                <p><em>Usuarios → Agregar usuario.</em> El formulario está dividido en bloques; los
                campos con asterisco son obligatorios.</p>

                <h4>Bloque 1 · Datos personales</h4>
                <div class="tw"><table>
                    <thead><tr><th>Campo</th><th>Qué poner</th></tr></thead>
                    <tbody>
                        <tr><td>Nombre *</td><td>Nombre del cliente. Si es una empresa, marca <strong>Es empresa</strong> y el apellido queda opcional</td></tr>
                        <tr><td>Apellido</td><td>Apellido</td></tr>
                        <tr><td>Cédula *</td><td>Documento de identidad</td></tr>
                        <tr><td>Teléfono</td><td>Celular de contacto</td></tr>
                        <tr><td>Correo personal *</td><td>Correo real del cliente. Debe ser único</td></tr>
                        <tr><td>Correo de acceso</td><td>El que usará para entrar. <strong>Si lo dejas vacío, el sistema lo crea solo</strong></td></tr>
                        <tr><td>Contraseña *</td><td>Mínimo 6 caracteres</td></tr>
                    </tbody>
                </table></div>
                <div class="note">
                    <span class="tag">Sobre las tildes y la ñ</span>
                    <p>El nombre se guarda tal cual lo escribes (José Muñoz). El correo de acceso se
                    convierte automáticamente a letras sin tilde (<code>jose.munoz@…</code>), porque
                    los equipos de red no aceptan esos caracteres.</p>
                </div>

                <h4>Bloque 2 · Ubicación</h4>
                <p>Dirección, ciudad, departamento, estrato (1 a 6) y precinto del equipo. También
                puedes marcar la ubicación en el mapa para que aparezca en <em>Mapa de usuarios</em>.</p>

                <h4>Bloque 3 · Servicio</h4>
                <div class="tw"><table>
                    <thead><tr><th>Campo</th><th>Qué poner</th></tr></thead>
                    <tbody>
                        <tr><td>Plan de internet</td><td>El plan que contrató</td></tr>
                        <tr><td>Router</td><td>El equipo al que se conecta</td></tr>
                        <tr><td>Sectorial</td><td>La antena o elemento que lo atiende</td></tr>
                        <tr><td>IP</td><td>La dirección IP que se le asigna</td></tr>
                        <tr><td>Fecha de instalación</td><td><strong>Muy importante</strong>: de aquí sale el cobro proporcional</td></tr>
                        <tr><td>Es fibra</td><td>Marca si el cliente es FTTH. Si eliges OLT y puerto NAP, se detecta solo</td></tr>
                    </tbody>
                </table></div>
                <div class="note">
                    <span class="tag">Regla de la IP</span>
                    <p>Dos clientes del <strong>mismo router</strong> no pueden tener la misma IP. La
                    misma IP sí puede repetirse en <strong>otro</strong> router. Si te da error,
                    revisa que no la tenga ya otro cliente de ese equipo.</p>
                </div>

                <h4>Bloque 4 · Primera factura</h4>
                <p>Aquí decides qué se le cobra al cliente que entra a mitad de mes:</p>
                <div class="tw"><table>
                    <thead><tr><th>Opción</th><th>Qué hace</th></tr></thead>
                    <tbody>
                        <tr><td><strong>No facturar</strong></td><td>No se le cobra el mes en curso. Su primera factura sale el mes siguiente</td></tr>
                        <tr><td><strong>Prorrateado</strong></td><td>Se le cobran sólo los días que faltan del mes</td></tr>
                        <tr><td><strong>Mes completo</strong></td><td>Se le cobra el mes entero, como a un cliente antiguo</td></tr>
                    </tbody>
                </table></div>
                <p>Y además <strong>meses de cortesía</strong>, que son meses <em>posteriores</em> al
                de instalación que salen en cero. Ejemplo: instalado el 16 de julio con
                <em>Prorrateado + 1 mes de cortesía</em> ⇒ paga del 16 al 31 de julio,
                <strong>agosto sale gratis</strong> y septiembre vuelve a la tarifa normal.</p>
                <p>Si dejas estas casillas vacías, el cliente <strong>hereda</strong> lo que tenga
                configurado su plan, y si el plan tampoco lo define, lo del router.</p>
                <div class="note">
                    <span class="tag">El sistema te muestra el cálculo antes de guardar</span>
                    <p>Al llenar la fecha de instalación y el plan aparece una vista previa con el
                    monto exacto. <strong>No cobra nada todavía</strong>, sólo te enseña el resultado.</p>
                </div>

                <h4>Bloque 5 · Credenciales del equipo</h4>
                <p>Según cómo esté configurado el router, te pedirá usuario y contraseña
                <strong>PPPoE</strong>, o usuario y contraseña <strong>HotSpot</strong>, o la
                <strong>dirección MAC</strong>. Si no aplica, el bloque no aparece.</p>

                <h4>Bloque 6 · Opciones</h4>
                <ul>
                    <li><strong>No facturar a este cliente:</strong> lo saca de <strong>todo</strong> el ciclo automático — ni factura, ni recordatorio, ni notificación, ni corte. Para cortesías institucionales o pruebas.</li>
                    <li><strong>No enviar notificaciones de factura:</strong> <strong>no</strong> afecta la facturación. La factura se sigue generando y la mora y el corte funcionan igual; sólo apaga los avisos de correo/WhatsApp. Para clientes que piden explícitamente no recibir esos mensajes.</li>
                </ul>

                <h3>Guardar: las dos opciones</h3>
                <div class="tw"><table>
                    <thead><tr><th>Botón</th><th>Qué hace</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Guardar</strong></td><td>Registra al cliente <strong>sólo en el sistema</strong>. No toca el router</td></tr>
                        <tr><td><strong>Guardar y cargar a la RB</strong></td><td>Registra al cliente <strong>y lo configura en el equipo de red</strong></td></tr>
                    </tbody>
                </table></div>
                <p>El cliente se guarda <strong>de inmediato</strong> y la carga al equipo se hace
                <strong>en segundo plano</strong>. No tienes que esperar con la pantalla abierta ni
                te va a salir un «tiempo de espera agotado»: son dos cosas separadas. La parte del
                router tarda alrededor de medio minuto por cliente.</p>

                <div class="note warn">
                    <span class="tag">La causa más común de «creé el cliente y no tiene internet»</span>
                    <p>El router tiene que tener activada el <strong>alta automática</strong>
                    (<em>Agregar cliente a MikroTik</em>, en su ficha), y <strong>viene apagada de
                    fábrica</strong>. Si está apagada el cliente se guarda perfectamente pero
                    <strong>nunca se carga al equipo</strong>, y el aviso de esto sólo queda en la
                    bitácora: en pantalla no se ve nada raro.</p>
                </div>

                <p>Si la carga en segundo plano falla, entra a la ficha del cliente y usa el botón de
                <strong>aprovisionar</strong>. Ese botón exige que el cliente tenga <strong>router</strong>,
                <strong>plan</strong> e <strong>IP</strong>; si le falta alguno te lo dice y no hace nada.</p>

                <h3>Editar un cliente</h3>
                <p>El mismo formulario con los datos actuales, más cuatro pestañas:
                <strong>Facturación</strong> (facturas, saldo, saldo a favor y servicios adicionales),
                <strong>Documentos</strong>, <strong>Instalaciones</strong> y <strong>Tickets</strong>.</p>

                <h4>Servicios adicionales del cliente</h4>
                <p>Al final de la pestaña <em>Facturación</em> está lo que el cliente paga
                <strong>además de su plan</strong>: el alquiler de un router extra, un punto de TV,
                soporte premium. Arriba del listado ves cuánto se le suma cada mes.</p>
                <div class="tw"><table>
                    <thead><tr><th>Campo</th><th>Qué hace</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Precio para este cliente</strong></td><td>Déjalo <strong>vacío</strong> para usar el del catálogo — así, si algún día subes el precio de lista, a este cliente también le sube. Ponle un valor para <strong>congelárselo</strong> (aparece la etiqueta <em>Precio propio</em>)</td></tr>
                        <tr><td><strong>Cantidad</strong></td><td>Para cobrar el mismo servicio más de una vez, por ejemplo dos routers extra</td></tr>
                        <tr><td><strong>Desde</strong></td><td>A partir de qué fecha se cobra</td></tr>
                        <tr><td><strong>Hasta</strong></td><td>Opcional: baja programada</td></tr>
                    </tbody>
                </table></div>
                <div class="note">
                    <span class="tag">Dar de baja vs. eliminar</span>
                    <p><em>Dar de baja</em> deja de cobrarlo desde la próxima factura y conserva todo
                    el historial — es lo que quieres casi siempre. <em>Eliminar</em> sólo funciona si
                    el servicio <strong>nunca llegó a cobrarse</strong>.</p>
                </div>
                <p>El servicio adicional <strong>no genera una factura aparte</strong>: sale como una
                línea más dentro de la mensualidad. Si el cliente paga $50.000 de plan y alquila un
                router de $20.000, recibe <strong>una sola factura de $70.000</strong> con las dos
                líneas.</p>

                <div class="tw"><table>
                    <thead><tr><th>Situación</th><th>Qué pasa con el adicional</th></tr></thead>
                    <tbody>
                        <tr><td>El cliente está en un <strong>mes de cortesía</strong></td><td>Se cobra o no según la casilla <em>Cobrar en meses de cortesía</em> del catálogo</td></tr>
                        <tr><td>El servicio se <strong>desactiva en el catálogo</strong></td><td>Los clientes que ya lo tienen <strong>lo siguen pagando</strong>. Desactivar sólo lo quita de la lista al asignar</td></tr>
                        <tr><td>Se <strong>da de baja</strong> la asignación</td><td>Deja de cobrarse desde la siguiente factura. Las anteriores no cambian</td></tr>
                        <tr><td>Se <strong>borra la factura</strong> del mes</td><td>Al regenerarla el adicional se vuelve a cobrar. No se pierde</td></tr>
                        <tr><td>La generación mensual corre <strong>dos veces</strong></td><td>El adicional se cobra <strong>una sola vez</strong></td></tr>
                    </tbody>
                </table></div>

                <p>Si un cliente no tiene plan que cobrarle (un empleado con cortesía, o alguien sin
                plan asignado) pero sí tiene servicios adicionales, el sistema le emite
                <strong>una factura sólo con ellos</strong>. Tiene sentido: no paga internet, pero sí
                está alquilando el equipo.</p>
                <p>Un servicio puede quedarse <strong>activo pero sin facturarse</strong> durante
                meses sin que nadie lo note. Por eso, en <em>Finanzas → Servicios adicionales →
                Catálogo</em> un aviso ámbar te dice cuántos servicios activos no se cobraron este
                mes, por cuánto y a qué clientes; y en la ficha del cliente la asignación lleva la
                etiqueta <strong>Sin cobrar este mes</strong>.</p>

                <h4>Firmar el contrato</h4>
                <p>En la pestaña <em>Documentos</em>, abajo, está el contrato de servicio: se arma
                solo con los datos del cliente, el cliente firma en pantalla y al guardar se genera
                el PDF firmado. Antes de firmar verás <strong>con qué número quedará</strong> (por
                ejemplo <code>CTR-00042</code>); ese consecutivo va impreso dentro del documento y no
                se repite nunca.</p>
                <div class="note">
                    <span class="tag">Un solo contrato firmado por cliente</span>
                    <p>Si el cliente ya tiene contrato, la zona de firma se reemplaza por un aviso:
                    para generar uno nuevo hay que <strong>eliminar primero el anterior</strong>. Es a
                    propósito — así no se acumulan dos contratos casi iguales sin saber cuál vale. Lo
                    mismo aplica a la hoja de instalación de cada orden.</p>
                </div>

                <h3>Estados del cliente: suspender, retirar, cancelar</h3>
                <p>Desde la ficha del cliente, con los botones <strong>Suspender</strong> y
                <strong>Activar</strong>. Esto actúa <strong>de verdad sobre el router</strong>: al
                suspender, el cliente deja de navegar. Necesitas el permiso <em>Activar y Desactivar
                Clientes</em>.</p>

                <div class="tw"><table>
                    <thead><tr><th>Estado</th><th class="ctr">Navega</th><th>¿Se le sigue facturando?</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Activo</strong></td><td class="ctr yes">Sí</td><td>Sí</td></tr>
                        <tr><td><strong>Gratis</strong></td><td class="ctr yes">Sí</td><td>No — es un plan de cortesía</td></tr>
                        <tr><td><strong>Suspendido</strong></td><td class="ctr no">No</td><td><strong>Sí.</strong> Es un corte temporal: puede volver pagando, así que esos meses se cobran</td></tr>
                        <tr><td><strong>Retirado</strong></td><td class="ctr no">No</td><td><strong>No.</strong> Es una baja definitiva</td></tr>
                        <tr><td><strong>Cancelado</strong></td><td class="ctr no">No</td><td><strong>No.</strong> Es una baja definitiva</td></tr>
                    </tbody>
                </table></div>

                <div class="note warn">
                    <span class="tag">Suspender no es dar de baja</span>
                    <p>Al suspendido se le siguen emitiendo facturas mes a mes, a propósito: si se
                    reconecta, esos meses existen. Lo único que frena la acumulación es el tope de
                    <em>«Dejar de facturar al moroso»</em> del router.</p>
                    <p><strong>Al cliente que se fue de verdad hay que ponerlo en Retirado o
                    Cancelado</strong>, no dejarlo suspendido. Si lo dejas suspendido seguirá
                    generando deuda que nadie va a pagar y ensuciará tus reportes de cartera.</p>
                </div>

                <p>El corte automático por mora deja al cliente en <strong>Suspendido</strong>, igual
                que si lo suspendieras a mano. La diferencia es que del corte por mora el sistema
                <strong>lo reconecta solo cuando paga</strong>; del manual, no.</p>

                <h3>Eliminar un cliente</h3>
                <p>El sistema pedirá confirmación escribiendo <code>ELIMINAR</code>. Se borra todo,
                sin dejar rastro: su perfil, facturas, pagos, servicios, tickets e instalaciones; sus
                <strong>archivos</strong> (contrato firmado, actas, fotos, documentos), que se borran
                del almacenamiento; y su <strong>configuración en el router</strong> — usuario PPPoE
                (cortándole la sesión activa), cola de velocidad, usuario de HotSpot, reserva DHCP,
                listas de acceso y amarre de IP/MAC.</p>

                <div class="note stop">
                    <span class="tag">Si el router no responde, el cliente se borra igual</span>
                    <p>Verás un mensaje naranja diciendo que no se pudo limpiar el equipo. En ese caso
                    <strong>la configuración sigue en el router y el cliente sigue navegando</strong>:
                    hay que quitarla a mano. No se hace de otra forma porque, si un router caído
                    bloqueara el borrado, tendrías clientes imposibles de eliminar.</p>
                </div>
                <div class="note stop">
                    <span class="tag">Piénsalo dos veces</span>
                    <p>Si el cliente sólo se retiró, es mejor desactivarlo que borrarlo: así conservas
                    su historial de pagos y el sistema deja de facturarle igual. <strong>El borrado es
                    definitivo</strong> — incluidas las facturas pagadas, que son historia contable.</p>
                </div>

                <h3>Mapa y estadísticas</h3>
                <ul>
                    <li><em>Usuarios → Mapa de usuarios</em>: cada cliente aparece como un punto; se ven también las antenas con su radio de cobertura.</li>
                    <li><em>Usuarios → Estadísticas</em>: totales, distribución por plan y por estado.</li>
                </ul>
            </section>

            <!-- 06 ─────────────────────────────────────────────────────── -->
            <section id="instalaciones">
                <span class="sec-num">06</span>
                <h2>Prospectos e instalaciones</h2>

                <p>Un <strong>prospecto</strong> es alguien interesado que todavía no es cliente.</p>

                <h3>Registrar un prospecto y agendar</h3>
                <p>En <em>Soporte → Instalaciones → Nueva instalación</em>, llena los datos de la
                persona, elige <strong>fecha</strong> y <strong>técnico</strong> y guarda. El
                prospecto queda en estado <strong>agendado</strong>.</p>

                <h3>El día de la instalación</h3>
                <ol class="steps">
                    <li><span class="k">1</span><div><b>Llena los datos técnicos</b><span>En <em>Conexión / Red</em> elige sectorial, core, plan y la <strong>IP del cliente</strong>; en <em>Hoja técnica</em> van equipos, mediciones y observaciones. <strong>Guardar datos técnicos</strong> guarda las dos partes de una vez.</span></div></li>
                    <li><span class="k">2</span><div><b>Carga los equipos y materiales</b><span>Los equipos con serial se eligen de una lista agrupada por quién los tiene; los materiales se agregan con su cantidad («4 RJ45»). Cada línea <strong>se descuenta del inventario</strong>. El botón <strong>Devolver</strong> deshace la carga.</span></div></li>
                    <li><span class="k">3</span><div><b>Sube fotos</b><span>Puedes seleccionarlas <strong>todas juntas</strong>: el sistema las comprime en el teléfono y las envía una por una por su cuenta. Hasta <strong>10 MB</strong> cada una, en JPG, PNG o WEBP.</span></div></li>
                    <li><span class="k">4</span><div><b>Registra el cobro</b><span>Costo de instalación, cargos adicionales, descuento (con motivo), forma de pago y cuánto recibió. <em>Cobrar equipo de la instalación</em> sólo ofrece lo que de verdad se descargó, para que la factura y el acta no digan cosas distintas.</span></div></li>
                    <li><span class="k">5</span><div><b>Muestra la hoja antes de firmar</b><span><strong>Ver hoja antes de firmar</strong> abre el documento tal como va a quedar, todavía sin firmas. Incluye lo que acabas de escribir aunque no hayas guardado, y <strong>no guarda ni cierra nada</strong>.</span></div></li>
                    <li><span class="k">6</span><div><b>Recoge las firmas</b><span>La del cliente y la del técnico, dibujadas en pantalla. El sistema <strong>no deja cerrar la orden con una firma vacía</strong>.</span></div></li>
                </ol>

                <div class="note">
                    <span class="tag">La IP del cliente no es la IP local del PPPoE</span>
                    <p>La primera es la que queda asignada al abonado, y es la que viaja al alta
                    cuando conviertes el prospecto en cliente. La <em>IP local</em> es la punta del
                    router dentro del secret PPPoE. Cada campo tiene su propio desplegable de
                    <strong>IPs libres</strong> del core.</p>
                </div>

                <p>Al firmar, el sistema genera la <strong>hoja de instalación en PDF</strong> y la
                orden queda cerrada. El PDF aparece en <em>Documentos de la orden</em>, en esa misma
                pantalla, y queda guardado en la pestaña <em>Documentos</em> del cliente. La hoja
                incluye la lista de equipos y materiales con su serial: es lo que el cliente firma
                que recibió, y lo que sirve para reclamar un equipo que no vuelva.</p>

                <div class="note">
                    <span class="tag">Una orden, una hoja firmada</span>
                    <p>Una vez firmada, el botón de firmar desaparece. Si la hoja quedó mal,
                    <strong>elimínala</strong> en <em>Documentos de la orden</em> y vuelve a firmar. La
                    hoja <strong>no incluye las fotos</strong>: esas se consultan en los documentos.</p>
                </div>

                <p>Al completar la instalación se genera automáticamente la <strong>factura de
                instalación</strong>.</p>

                <h3>Convertir el prospecto en cliente</h3>
                <p>En la instalación completada, pulsa <strong>Convertir prospecto en cliente</strong>.
                Se abre el alta <strong>ya rellenada</strong> con los datos personales, la
                <strong>fecha de instalación</strong> (que decide el prorrateo) y los datos técnicos de
                la orden: core, plan, sectorial o caja NAP, IP del cliente, credenciales PPPoE y MAC
                del módem. Si la caja es una NAP, el alta entra sola en modo <strong>fibra</strong> y
                deduce la OLT.</p>
                <p>Al guardar, el prospecto queda enlazado al cliente, su estado pasa a
                <strong>convertido</strong> y sus instalaciones y documentos se trasladan a la ficha
                del cliente nuevo.</p>
                <div class="note">
                    <span class="tag">Si algún dato técnico sale vacío</span>
                    <p>Es que no quedó guardado en la orden: vuelve a la instalación, complétalo y
                    pulsa <strong>Guardar datos técnicos</strong>. Lo que escribas tú manda sobre lo
                    que traiga la orden.</p>
                </div>
            </section>

            <!-- 07 ─────────────────────────────────────────────────────── -->
            <section id="facturacion">
                <span class="sec-num">07</span>
                <h2>Facturación</h2>

                <p><strong>Las facturas se generan solas.</strong> No hay que emitirlas a mano cada
                mes. Pero hay algo que sorprende a casi todo el mundo la primera vez:</p>

                <div class="thesis">
                    <span class="tag">Lo más importante del sistema</span>
                    <p>La facturación se configura POR ROUTER, no por empresa ni por cliente.</p>
                    <p>Esto significa que si un cliente no recibe factura, lo primero que hay que
                    mirar es la configuración de su router — no la del cliente.</p>
                </div>

                <div class="tw"><table>
                    <thead><tr><th>Campo en pantalla</th><th>Qué significa</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Se emite la factura — Día / Hora</strong></td><td>Cuándo se <em>genera</em> la factura de los clientes de ese router</td></tr>
                        <tr><td><strong>Vence la factura — Día límite</strong></td><td>Último día para pagar. Pasado ese día la factura queda <em>vencida</em>, pero el servicio sigue activo</td></tr>
                        <tr><td><strong>Recordatorio de pago — Día / Hora</strong></td><td>Cuándo se avisa al cliente de lo que tiene pendiente</td></tr>
                        <tr><td><strong>Se corta el servicio — Día / Hora</strong></td><td>Desde qué día del mes se empieza a suspender morosos</td></tr>
                        <tr><td><strong>Suspender tras X facturas vencidas</strong></td><td>Cuántas facturas sin pagar tolera antes de cortar. <strong>Es la condición real del corte</strong></td></tr>
                        <tr><td><strong>Dejar de facturar al moroso</strong></td><td>A partir de cuántas facturas pendientes se le deja de emitir la mensualidad. Por defecto: el umbral de corte <strong>+ 2</strong></td></tr>
                        <tr><td><strong>Modo de facturación</strong></td><td><em>Anticipado</em> (se cobra el mes que empieza) o <em>Vencido</em> (el que terminó)</td></tr>
                    </tbody>
                </table></div>

                <p>Si configuras el día 31 y el mes tiene 30 días, el sistema factura el día 30. No se
                salta.</p>

                <h3>Qué periodo cubre la factura, y por qué el corte no cae el día que uno espera</h3>
                <ul>
                    <li><strong>El periodo facturado es siempre el mes calendario completo</strong>, sin importar qué día se emita. La única excepción es el prorrateo de la primera factura, que arranca el día de la instalación.</li>
                    <li><strong>Anticipado</strong> = el periodo es el mes en que se emite. <strong>Vencido</strong> = el mes anterior.</li>
                    <li><strong>El día límite de pago no corta nada.</strong> Sólo marca desde cuándo la factura cuenta como vencida.</li>
                    <li><strong>El día de corte es una ventana, no una fecha exacta.</strong> Desde ese día y hasta fin de mes el sistema revisa cada hora, y suspende únicamente a quien haya llegado al número de facturas vencidas configurado. Con el umbral en 2, el cliente arrastra un ciclo entero antes de que lo corten: por eso el corte «real» suele caer un mes después del primer impago.</li>
                    <li><strong>El recordatorio</strong> se envía una sola vez por ciclo, en <strong>un solo mensaje</strong> con todas las facturas pendientes y el total adeudado.</li>
                    <li><strong>Al cliente cortado se le sigue facturando.</strong> Lo que frena la acumulación es el tope <em>«Dejar de facturar al moroso»</em>. Con corte en 2 y tope +2, el cliente acumula 4 facturas y ahí para. Los <strong>retirados</strong> y <strong>cancelados</strong> no facturan nunca.</li>
                </ul>
                <p>El panel de facturación del router muestra un recuadro <strong>«Así queda el
                ciclo»</strong> que traduce la configuración a fechas reales del mes en curso y avisa
                de combinaciones sospechosas. Es sólo informativo: no cambia nada.</p>

                <h3>El Panel de Finanzas</h3>
                <p><em>Finanzas → Resumen.</em> Arriba a la derecha hay un <strong>selector de
                mes</strong>: todo lo que ves debajo es de ese mes. No puedes avanzar más allá del mes
                actual.</p>
                <div class="tw"><table>
                    <thead><tr><th>Tarjeta</th><th>Qué cuenta exactamente</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Facturado del mes</strong></td><td>Lo emitido en el mes elegido. <strong>No incluye facturas anuladas</strong></td></tr>
                        <tr><td><strong>Recaudado del mes</strong></td><td>Todo el dinero que entró en el mes, aunque sea de facturas viejas</td></tr>
                        <tr><td><strong>Gastos del mes</strong></td><td>Gastos con fecha en el mes, sin los anulados</td></tr>
                        <tr><td><strong>Balance del mes</strong></td><td>Recaudado <strong>−</strong> gastos. Verde si te sobró, rojo si no</td></tr>
                        <tr><td><strong>Cartera total</strong></td><td>Lo que te deben <strong>en total</strong>, de todos los meses. Es la única cifra acumulada</td></tr>
                        <tr><td><strong>Tasa de cobro</strong></td><td>De lo facturado <strong>en ese mes</strong>, qué porcentaje ya está pagado</td></tr>
                    </tbody>
                </table></div>
                <p><strong>El balance es de caja, no de papel.</strong> Resta los gastos de lo que
                <em>cobraste</em>, no de lo que <em>facturaste</em>: una factura emitida que nadie pagó
                no sirve para pagar la nómina.</p>
                <p><strong>La tasa de cobro no compara recaudado contra facturado.</strong> Compara lo
                pagado <em>de las facturas de ese mes</em> contra lo facturado ese mes. Si cobras mora
                de hace tres meses, ese dinero suma en <em>Recaudado</em> pero no sube la tasa.</p>
                <div class="note">
                    <span class="tag">Permisos</span>
                    <p><em>Gastos</em> y <em>Balance</em> sólo se ven con permiso de gastos. Un rol de
                    sólo facturación ve las otras cuatro tarjetas y estas dos no aparecen — no salen en
                    cero, desaparecen.</p>
                </div>

                <h3>Ver y buscar facturas</h3>
                <div class="tw"><table>
                    <thead><tr><th>Estado</th><th>Significa</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Borrador</strong></td><td>Creada pero no emitida</td></tr>
                        <tr><td><strong>Emitida</strong></td><td>Enviada al cliente, pendiente de pago</td></tr>
                        <tr><td><strong>Parcial</strong></td><td>Tiene un abono, falta saldo</td></tr>
                        <tr><td><strong>Pagada</strong></td><td>Cancelada por completo</td></tr>
                        <tr><td><strong>Vencida</strong></td><td>Pasó la fecha de pago sin pagarse</td></tr>
                        <tr><td><strong>Anulada</strong></td><td>Sin efecto</td></tr>
                    </tbody>
                </table></div>
                <p>El buscador busca a la vez por <strong>número de factura</strong>, <strong>nombre</strong>,
                <strong>apellido</strong> y <strong>correo</strong>. Los totales de arriba
                (<em>Total facturado</em> y <em>Saldo pendiente</em>) suman <strong>todas</strong> las
                facturas que cumplen el filtro, no sólo las de la página que estás viendo. Las
                anuladas no se cuentan.</p>
                <p><strong>Exportar a Excel.</strong> El botón <em>Exportar CSV</em> (en Facturación,
                Recaudos y Gastos) descarga todo lo que cumple el filtro que tengas puesto. Filtra
                primero, exporta después.</p>

                <h3>Servicios adicionales</h3>
                <div class="tw"><table>
                    <thead><tr><th>Pestaña</th><th>Para qué</th><th>Cómo se cobra</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Catálogo</strong></td><td>Algo que se cobra <strong>todos los meses</strong>: alquiler de un router extra, soporte mensual, un punto de TV</td><td>Se suma a la <strong>factura mensual</strong>. No genera factura aparte</td></tr>
                        <tr><td><strong>Cargo puntual</strong></td><td>Algo que se cobra <strong>una sola vez</strong>: un traslado, un cambio de equipo, una reconexión</td><td>Genera <strong>su propia factura</strong></td></tr>
                    </tbody>
                </table></div>
                <p>Al crear un servicio del catálogo eliges nombre, descripción, <strong>precio
                mensual</strong>, el <strong>cobro del primer mes</strong> (mes completo, proporcional
                a los días, o no cobrar el primero) y si se <strong>cobra en meses de cortesía</strong>
                — viene activado, porque lo normal es que la promoción cubra el internet, no el
                alquiler del equipo.</p>
                <div class="note">
                    <span class="tag">Desactivar en vez de eliminar</span>
                    <p>Un servicio que ya está asignado a algún cliente no se puede borrar. Es a
                    propósito: las facturas que ya lo cobraron tienen que poder seguir explicando qué
                    se cobró.</p>
                </div>

                <h3>Tipos de factura</h3>
                <p><em>Finanzas → Tipos de factura.</em> No estás limitado a los de fábrica: crea
                «Factura de Equipos», «Factura de TV», «Reconexión», lo que uses. Cada tipo tiene un
                <strong>color</strong> para la etiqueta del listado.</p>
                <div class="tw"><table>
                    <tbody>
                        <tr><td><strong>Tipos del sistema</strong></td><td><em>Plan Mensual</em>, <em>Instalación</em>, <em>Servicio Adicional</em> y <em>Cargo de Ticket</em>. No se editan ni se borran: la facturación automática depende de ellos</td></tr>
                        <tr><td><strong>Desactivar un tipo</strong></td><td>Deja de ofrecerse al facturar, pero las facturas que ya lo usan conservan su etiqueta</td></tr>
                        <tr><td><strong>Eliminar un tipo</strong></td><td>Sólo si <strong>nunca</strong> se ha emitido una factura con él</td></tr>
                    </tbody>
                </table></div>

                <h3>Corregir una factura</h3>
                <ul>
                    <li><strong>Editar:</strong> cambia fechas, total o notas.</li>
                    <li><strong>Marcar como no pagada:</strong> revierte los pagos y restaura el saldo.</li>
                    <li><strong>Eliminar:</strong> la borra.</li>
                </ul>
                <div class="note stop">
                    <span class="tag">Al eliminar una factura, el sistema NO la volverá a generar nunca</span>
                    <p>Deja una marca interna para ese cliente y ese mes. Si la borraste por error,
                    tendrás que crearla a mano. El mes siguiente se factura con normalidad.</p>
                </div>

                <h3>Recordatorios y avisos</h3>
                <p>Se envían solos en el día y la hora configurados en el router, por correo, por
                WhatsApp o por ambos. También puedes enviarlos a mano: <strong>individual</strong>
                desde el detalle de la factura, o <strong>masivo</strong> desde la lista. El sistema
                <strong>no duplica</strong> recordatorios dentro del mismo ciclo.</p>
                <p><strong>Un cliente = un mensaje.</strong> Si debe varias facturas recibe un solo
                correo/WhatsApp con el listado y el total. Los recordatorios automáticos van sólo a
                clientes <strong>activos</strong>: al que ya está cortado el aviso le llega tarde — el
                corte fue el aviso.</p>
                <p>Sobre el aviso de <em>nueva factura</em>: si el cliente ya debía facturas
                anteriores, el correo lo dice (cuántas tiene pendientes, el saldo anterior y la deuda
                total). Y si la factura <strong>nace saldada</strong> porque el saldo a favor la cubrió
                entera, <strong>no se envía ningún aviso</strong>.</p>

                <h3>Clientes que no se facturan</h3>
                <div class="tw"><table>
                    <thead><tr><th>Forma</th><th>Efecto</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Plan de cortesía</strong></td><td>Su servicio queda en «gratis» y no se factura</td></tr>
                        <tr><td><strong>No facturar a este cliente</strong></td><td>Lo saca de todo: factura, recordatorio, notificación y corte</td></tr>
                        <tr><td><strong>No enviar notificaciones</strong></td><td>La factura se sigue generando y cobrando normalmente; sólo se apagan los avisos. No afecta mora ni corte</td></tr>
                    </tbody>
                </table></div>
            </section>

            <!-- 08 ─────────────────────────────────────────────────────── -->
            <section id="pagos">
                <span class="sec-num">08</span>
                <h2>Pagos y recaudos</h2>

                <h3>Registrar un pago</h3>
                <p>En <em>Finanzas → Pagos / Recaudos → Registrar pago</em>: elige el cliente, el
                monto, la fecha y la forma de pago (Efectivo, Tarjeta, Corresponsal, Transacción, o
                las que hayas creado). Opcionalmente, referencia y notas.</p>

                <h3>Qué pasa automáticamente al guardar</h3>
                <ol class="steps">
                    <li><span class="k">1</span><div><b>Se aplica a las facturas pendientes</b><span>Empezando por la más antigua.</span></div></li>
                    <li><span class="k">2</span><div><b>Si sobra dinero, queda como saldo a favor</b><span>Y se usa en la próxima factura.</span></div></li>
                    <li><span class="k">3</span><div><b>Si falta dinero, la factura queda pagada igual</b><span>Y lo que faltó pasa a la próxima factura.</span></div></li>
                    <li><span class="k">4</span><div><b>Si queda al día y estaba cortado por mora, se le devuelve el internet</b><span>La reconexión automática <strong>sólo aplica a cortes por facturación</strong>. Si lo suspendieron a mano, hay que reactivarlo a mano.</span></div></li>
                </ol>

                <h3>Abonos parciales: el saldo pasa a la próxima factura</h3>
                <p>Cuando el cliente paga <strong>menos</strong> de lo que debe, la factura se marca
                como <strong>Pagada</strong> y su saldo queda en cero; lo que faltó queda como saldo
                pendiente del cliente, y la <strong>próxima factura mensual</strong> lo cobra
                automáticamente con una línea <em>«Saldo pendiente de facturas anteriores (#…)»</em>.</p>
                <p><strong>Ejemplo.</strong> El cliente debe $50.000 y abona $30.000. Esa factura queda
                pagada y quedan $20.000 pendientes. El mes siguiente, si el plan vale $50.000, su
                factura será de <strong>$70.000</strong>.</p>

                <div class="note warn">
                    <span class="tag">El abono parcial saca al cliente de la mora</span>
                    <p>Al quedar la factura pagada, el cliente <strong>sale de mora</strong>. Si estaba
                    cortado se le devuelve el internet, y <strong>no se le vuelve a cortar hasta que se
                    venza la factura nueva</strong> (la que ya trae la deuda vieja sumada). Al
                    registrar un abono parcial el sistema te avisa de esto y te pide confirmar.</p>
                </div>

                <div class="tw"><table>
                    <thead><tr><th>Dónde</th><th>Qué se ve</th></tr></thead>
                    <tbody>
                        <tr><td>Al registrar un pago</td><td>Bloque ámbar <em>«Saldo pendiente arrastrado»</em> junto al saldo del cliente</td></tr>
                        <tr><td>Ficha del cliente → Facturación</td><td>Aviso ámbar con el total arrastrado</td></tr>
                        <tr><td>Lista de facturas, columna Saldo</td><td><em>«↷ $X a la próxima»</em> en la factura que abonó, y <em>«incluye $Y de saldo anterior»</em> en la que lo cobra</td></tr>
                        <tr><td>Detalle de la factura</td><td>De qué factura vino el saldo y a cuál se fue</td></tr>
                    </tbody>
                </table></div>

                <p>Si registraste el abono por error, <strong>eliminar el pago</strong> o
                <strong>Marcar como no pagada</strong> devuelve el saldo a la factura original —
                siempre que la próxima factura no lo haya cobrado todavía. Si ya lo cobró, el saldo se
                queda en esa factura nueva: no se cobra dos veces.</p>

                <h3>Buscar en la lista de recaudos</h3>
                <p>La lista muestra <strong>todos</strong> los recaudos, de más nuevo a más viejo. El
                buscador de arriba busca por <strong>cliente</strong> y <strong>referencia</strong>, y
                además <strong>cada columna tiene su propio buscador</strong>, que se combinan entre sí:</p>
                <div class="tw"><table>
                    <thead><tr><th>Columna</th><th>Qué acepta</th></tr></thead>
                    <tbody>
                        <tr><td>Fecha</td><td>Dos casillas: <strong>desde</strong> y <strong>hasta</strong> (ambas incluidas). Puedes usar sólo una</td></tr>
                        <tr><td>Cliente</td><td>Nombre, apellido, nombre completo o cédula</td></tr>
                        <tr><td>Monto</td><td>Dos casillas: <strong>mínimo</strong> y <strong>máximo</strong></td></tr>
                        <tr><td>Método</td><td>Lista con tus formas de pago</td></tr>
                        <tr><td>Referencia</td><td>Parte del número de comprobante</td></tr>
                        <tr><td>Registrado por</td><td>Nombre del usuario. Escribe <code>sistema</code> para ver los pagos automáticos</td></tr>
                        <tr><td>Facturas afectadas</td><td>Número (o parte) de una factura cubierta por el recaudo</td></tr>
                    </tbody>
                </table></div>
                <p>Cada forma de pago tiene su color fijo. Los números de <em>Facturas afectadas</em>
                usan el mismo código de color que la columna <em>Tipo</em> de Facturación (azul = Plan
                Mensual, verde = Instalación, morado = Adicional, ámbar = Cargo Ticket). Si un recaudo
                no cubrió ninguna factura, dice <strong>Saldo a favor</strong>. En el celular no hay
                tabla: los mismos filtros salen con el botón <strong>Filtros</strong>.</p>

                <h3>Saldo a favor</h3>
                <p>En la ficha del cliente, pestaña <em>Facturación</em>. Un administrador puede
                ajustarlo manualmente si hace falta. <strong>No confundir con el saldo pendiente
                arrastrado</strong>: el saldo a favor es plata que el cliente pagó de más y se le
                descuenta; el arrastrado es plata que le falta y se le sumará.</p>
            </section>

            <!-- 09 ─────────────────────────────────────────────────────── -->
            <section id="cortes">
                <span class="sec-num">09</span>
                <h2>Corte y reconexión de morosos</h2>

                <h3>Cómo funciona</h3>
                <p>El sistema revisa <strong>cada hora</strong> si hay que cortar a alguien. Corta a un
                cliente cuando se cumplen <strong>todas</strong> estas condiciones:</p>
                <ol class="steps">
                    <li><span class="k">1</span><div><b>Su router está en Corte Automático</b><span>Si está en Corte Manual, el sistema <strong>no corta</strong>: sólo deja la lista de pendientes para que alguien decida.</span></div></li>
                    <li><span class="k">2</span><div><b>Ya llegó el día de corte configurado</b><span></span></div></li>
                    <li><span class="k">3</span><div><b>Ya llegó la hora de corte configurada</b><span></span></div></li>
                    <li><span class="k">4</span><div><b>El cliente acumula al menos N facturas vencidas</b><span>N lo configuras en el router.</span></div></li>
                </ol>
                <div class="note">
                    <span class="tag">Los abonos parciales sacan al cliente de la cuenta</span>
                    <p>Al abonar, esa factura queda pagada y el faltante viaja a la próxima, así que
                    deja de contar para el corte hasta que la factura nueva se venza sin pagar.</p>
                </div>

                <h3>Qué le pasa al cliente cortado</h3>
                <p>Deja de navegar, pero <strong>sí puede entrar al portal de pago</strong> de tu
                empresa. Ese acceso queda abierto a propósito, para que pueda pagar y reconectarse.
                Además, mientras está cortado, <strong>cualquier página que intente abrir lo lleva al
                portal</strong>: no ve un «sin conexión» a secas, ve tu página de pago.</p>
                <div class="note">
                    <span class="tag">El portal tiene que estar configurado</span>
                    <p>Es una dirección que se configura una sola vez al instalar el sistema. Si no
                    está, <strong>las reglas de bloqueo ni siquiera se pueden aplicar</strong>. En ese
                    caso es cosa de soporte técnico, no algo que se arregle desde las pantallas.</p>
                </div>

                <h3>Ver qué se cortó y qué falló</h3>
                <div class="tw"><table>
                    <thead><tr><th>Panel</th><th>Qué muestra</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Bitácora de facturación</strong></td><td>Facturas que no se pudieron crear, con el error y el número de intentos</td></tr>
                        <tr><td><strong>Bitácora de cortes</strong></td><td>Cortes y reconexiones que fallaron en el equipo</td></tr>
                    </tbody>
                </table></div>
                <p>En ambos puedes pulsar <strong>Reintentar</strong> sobre una fila o
                <strong>Reintentar todo</strong>. Además, en la bitácora de cortes está el botón
                <strong>Reconciliar</strong>: revisa uno por uno los clientes que el sistema dio por
                suspendidos y comprueba que <strong>realmente</strong> estén cortados en el equipo. Si
                alguno no lo está, lo vuelve a cortar. El sistema lo hace solo cada hora; el botón
                sirve para forzarlo.</p>

                <h3>El cliente aparece cortado pero sigue navegando</h3>
                <p>Este es <strong>el problema más común del sistema</strong>, y casi siempre es una de
                tres cosas. El sistema dice «cortado» porque hizo su parte; lo que falla está en el
                equipo. Revísalo en este orden, de la causa más frecuente a la menos:</p>

                <h4>1 · El túnel VPN del router está caído</h4>
                <p>Sin túnel, ISPWatch no puede darle ninguna orden al equipo: ni cortar, ni
                reconectar, ni cargar clientes. Y como el corte se registra en la base de datos antes
                de llegar al equipo, la pantalla muestra al cliente cortado aunque en la realidad nunca
                se tocó nada.</p>
                <p>Entra a <em>Gestión → Lista de Routers</em>, abre el equipo y pulsa <strong>Verificar
                VPN</strong>. <strong>Señal de alarma:</strong> muchos clientes del mismo router sin
                cortar a la vez. Un cliente suelto suele ser otra cosa; el router entero es casi siempre
                la VPN.</p>

                <h4>2 · Las reglas de bloqueo no están, o quedaron muy abajo</h4>
                <p>El router aplica sus reglas <strong>en orden, de arriba hacia abajo</strong>, y se
                queda con la primera que coincide. Si las de ISPWatch quedaron por debajo de una regla
                que deja pasar el tráfico (muy común: las que trae el equipo de fábrica), nunca llegan a
                ejecutarse. Para el router las reglas están ahí; simplemente no se leen nunca.</p>
                <p>Pulsa <strong>Verificar reglas de bloqueo</strong> para ver cómo están, y
                <strong>Aplicar reglas de bloqueo</strong>: además de instalarlas si faltan,
                <strong>las vuelve a subir al primer lugar</strong>. Puedes pulsarlo las veces que
                quieras — no duplica reglas ni rompe lo que ya está bien. Para poder aplicarlas, el
                router necesita tener la <strong>interfaz WAN</strong> configurada.</p>

                <h4>3 · El cliente sigue con conexiones abiertas de antes</h4>
                <p>Un corte sólo afecta a las conexiones <strong>nuevas</strong>. El sistema corta las
                existentes al suspender, pero si el corte se aplicó tarde puede que alguna quede
                colgada unos minutos. Espera un par de minutos y vuelve a comprobar.</p>

                <div class="note warn">
                    <span class="tag">Después de arreglar cualquiera de las tres</span>
                    <p>Entra a <em>Acciones masivas → Bitácora de cortes</em> y pulsa
                    <strong>Reconciliar</strong>. Sin este paso, los clientes que ya estaban mal
                    marcados siguen navegando aunque el router ya esté bien configurado.</p>
                </div>

                <div class="tw"><table>
                    <thead><tr><th>Si con eso no se arregla, revisa</th><th>Dónde</th></tr></thead>
                    <tbody>
                        <tr><td>Que el router esté en <strong>Corte Automático</strong>, no Manual</td><td>Ficha del router → tipo de corte</td></tr>
                        <tr><td>Que el equipo responda al SSH</td><td>Ficha del router → <strong>Probar conexión SSH</strong></td></tr>
                        <tr><td>Que el cliente no esté marcado <strong>«No facturar»</strong></td><td>Ficha del cliente</td></tr>
                        <tr><td>Que el cliente no haya <strong>abonado</strong></td><td>Ficha del cliente → Facturación</td></tr>
                        <tr><td>El error exacto del intento fallido</td><td><strong>Acciones masivas → Bitácora de cortes</strong></td></tr>
                    </tbody>
                </table></div>

                <div class="note stop">
                    <span class="tag">Antes de estrenar el corte automático en un router nuevo</span>
                    <p>Verifica la VPN y aplica las reglas de bloqueo. Si no lo haces, el primer día de
                    corte el sistema marcará a todos como cortados y <strong>ninguno lo estará</strong>.</p>
                </div>
            </section>

            <!-- 10 ─────────────────────────────────────────────────────── -->
            <section id="gastos">
                <span class="sec-num">10</span>
                <h2>Gastos</h2>

                <p><em>Finanzas → Gastos → Nuevo gasto.</em> Elige la <strong>categoría</strong>
                (se crean en <em>Categorías de gasto</em>, que se ven como tarjetas), indica
                <strong>fecha</strong> y <strong>monto</strong>, y opcionalmente el
                <strong>beneficiario</strong> — el empleado o técnico a cuyo nombre va el gasto; déjalo
                vacío en gastos como arriendo o servicios públicos. La lista de beneficiarios muestra
                <strong>sólo personal del ISP</strong>: los clientes no aparecen ahí.</p>

                <div class="note">
                    <span class="tag">Los gastos no se borran</span>
                    <p>Si te equivocaste, edítalo y cámbialo a estado <strong>anulado</strong>. Así
                    queda el rastro de la corrección.</p>
                </div>

                <p>El buscador busca por <strong>descripción, observaciones y beneficiario</strong> —
                útil cuando no recuerdas la fecha exacta — y se combina con los filtros de fecha,
                categoría y estado. Las tarjetas de <em>Total del período filtrado</em> y <em>Por
                categoría</em> siempre suman <strong>todos</strong> los gastos que cumplen el filtro,
                no sólo los de la página. Los anulados siguen apareciendo en la lista, pero no se suman
                en esos totales.</p>
            </section>

            <!-- 11 ─────────────────────────────────────────────────────── -->
            <section id="routers">
                <span class="sec-num">11</span>
                <h2>Routers y red</h2>

                <h3>Agregar un router</h3>
                <p>Los datos mínimos son nombre, IP, usuario y contraseña de administración, versión de
                firmware y estado. Por defecto los puertos son API 8728 y web 80. <strong>Si el SSH del
                equipo no está en el 22, tienes que indicarlo en el campo de puerto SSH</strong>, o el
                sistema no podrá conectarse.</p>

                <h3>El método de control</h3>
                <p>Aquí eliges <strong>cómo controla el router a los clientes</strong>. Sólo puede haber
                <strong>uno activo</strong>:</p>
                <div class="tw"><table>
                    <thead><tr><th>Método</th><th>Cuándo usarlo</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Simple Queue</strong></td><td>Control de velocidad por IP. El más común</td></tr>
                        <tr><td><strong>PCQ</strong></td><td>Reparto equitativo de ancho de banda</td></tr>
                        <tr><td><strong>HotSpot</strong></td><td>Clientes que entran con usuario y contraseña en un portal</td></tr>
                        <tr><td><strong>PPPoE</strong></td><td>Clientes con usuario y contraseña de conexión</td></tr>
                        <tr><td><strong>DHCP Leases</strong></td><td>Asignación fija por dirección MAC</td></tr>
                    </tbody>
                </table></div>
                <p>Y dos opciones <strong>adicionales</strong> que se suman al método elegido:
                <strong>IP Bindings</strong> (fija la relación IP–equipo) y <strong>Amarre</strong>
                (bloquea al cliente si cambia de equipo).</p>

                <h3>Configurar la facturación del router</h3>
                <p>En la ficha del router eliges la configuración de facturación y el <strong>tipo de
                corte</strong> (Automático o Manual). <strong>Sin esto, los clientes de ese router no se
                facturan ni se cortan.</strong> El detalle de cada regla está en la
                <a href="#facturacion" @click.prevent="goTo('facturacion')">sección 07</a>.</p>

                <h3>Herramientas de diagnóstico</h3>
                <div class="tw"><table>
                    <thead><tr><th>Botón</th><th>Qué hace</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Probar conexión SSH</strong></td><td>Comprueba que el sistema llega al equipo</td></tr>
                        <tr><td><strong>Probar conexión al CORE</strong></td><td>Comprueba el equipo central</td></tr>
                        <tr><td><strong>Ver interfaces</strong></td><td>Lee las interfaces del router</td></tr>
                        <tr><td><strong>Fijar interfaz WAN</strong></td><td>Indica cuál es la salida a internet</td></tr>
                        <tr><td><strong>Aplicar reglas de bloqueo</strong></td><td>Instala en el equipo las reglas necesarias para cortar morosos</td></tr>
                        <tr><td><strong>Verificar reglas de bloqueo</strong></td><td>Comprueba que las reglas siguen puestas</td></tr>
                        <tr><td><strong>Generar script VPN</strong></td><td>Genera el texto para configurar el túnel del equipo</td></tr>
                        <tr><td><strong>Verificar VPN</strong></td><td>Comprueba que el túnel está arriba</td></tr>
                    </tbody>
                </table></div>

                <h3>Historial de tráfico</h3>
                <p>Si lo activas, el sistema mide el tráfico de la salida a internet
                <strong>cada 5 minutos</strong>. El detalle de 5 en 5 minutos se conserva
                <strong>30 días</strong> (para ver el pico de ayer o la caída de anoche); el
                <strong>consumo diario se guarda para siempre</strong> (para comparar meses o años).</p>
                <div class="note">
                    <span class="tag">Empieza a medir desde que lo activas</span>
                    <p>No hay historial de antes: si acabas de prenderlo, la gráfica sale vacía hasta la
                    siguiente medición. Y para que mida hace falta tener fijada la <strong>interfaz
                    WAN</strong>.</p>
                </div>

                <h3>Falla masiva</h3>
                <p>Cuando un nodo se cae y afecta a muchos clientes, entra a <em>Gestión → Lista de
                Routers</em> y pulsa <strong>Reportar falla masiva</strong> en el router afectado.
                Cuando se restablezca, pulsa <strong>Marcar como resuelta</strong>. El sistema marca el
                router, lo resalta en el Dashboard y cuenta cuántos clientes activos quedan afectados.
                Ambos avisos quedan guardados con la hora y el usuario que los reportó; ese registro no
                se puede editar ni borrar.</p>
                <div class="note warn">
                    <span class="tag">Sobre el aviso por WhatsApp</span>
                    <p>ISPWatch <strong>no envía los mensajes</strong>. Lo que hace es dejar el aviso
                    registrado para que el sistema de mensajería conectado lo lea y lo difunda. Si esa
                    conexión todavía no está montada en tu empresa, el botón sigue siendo útil, pero
                    <strong>a los clientes no les llega nada</strong>.</p>
                </div>

                <h3>La VPN: cómo llega ISPWatch a tus equipos</h3>
                <p>ISPWatch no habla directo con cada router tuyo. Habla con un <strong>equipo
                central</strong> (el CORE), y ese equipo llega a tus routers por un <strong>túnel
                privado</strong> que se monta una sola vez. Todo lo que hace el sistema sobre la red
                —cargar un cliente, cortarlo, reconectarlo, leer las interfaces, medir el tráfico— pasa
                por ese túnel.</p>

                <!-- white-space: pre — el contenido va a ras de margen a propósito:
                     cualquier sangría del template se vería en pantalla. -->
<div class="flow">ISPWatch  ──►  CORE  ──►  túnel VPN  ──►  tu router  ──►  cliente
                               │
                    si el túnel se cae, todo lo que
                    está a la derecha deja de recibir
                    órdenes, pero la pantalla no se entera</div>

                <p><strong>Si el túnel se cae, ISPWatch se queda ciego con ese router.</strong> Sigue
                mostrando sus clientes, sigue facturándoles y sigue marcando cortes en pantalla, pero
                <strong>ninguna orden llega al equipo</strong>. Por eso el primer paso de casi todo
                diagnóstico de red es <em>Verificar VPN</em>.</p>

                <div class="tw"><table>
                    <thead><tr><th>Tipo</th><th>Para qué equipos</th><th>Cómo se sabe si está vivo</th></tr></thead>
                    <tbody>
                        <tr><td><strong>WireGuard</strong></td><td>RouterOS <strong>v7</strong> en adelante</td><td>Por el último saludo del equipo, que se renueva cada pocos minutos</td></tr>
                        <tr><td><strong>L2TP</strong></td><td>RouterOS <strong>v6</strong>, que no soporta WireGuard</td><td>Por la sesión activa contra el central</td></tr>
                    </tbody>
                </table></div>
                <p>No tienes que elegir nada: al generar el script VPN el sistema mira la versión del
                equipo y arma el que corresponde.</p>

                <div class="note">
                    <span class="tag">Por qué se cambió a WireGuard en los equipos nuevos</span>
                    <p>Un router con dos salidas a internet podía mandar media conversación por una y
                    media por la otra, y el túnel L2TP se caía en bucle. Pasó de verdad: un equipo
                    estuvo <strong>8 días caído con 212 clientes sin gestión</strong> y nadie se dio
                    cuenta, porque el sistema sólo avisaba de fallos cliente por cliente, nunca de
                    «este router no está». Desde entonces hay una revisión automática cada 30 minutos
                    que <strong>avisa por correo</strong> los túneles caídos.</p>
                </div>

                <p><strong>Cuándo hay que volver a generar el script VPN:</strong> cuando el equipo se
                formateó o se reemplazó, o cuando <em>Verificar VPN</em> da caído y el equipo sí tiene
                internet. Ojo: el script se aplica <strong>en el router</strong>, no desde ISPWatch — el
                botón sólo te da el texto para pegarlo.</p>
            </section>

            <!-- 12 ─────────────────────────────────────────────────────── -->
            <section id="sectoriales">
                <span class="sec-num">12</span>
                <h2>Sectoriales y fibra óptica</h2>

                <p><em>Gestión → Sectoriales.</em> Aquí registras los elementos físicos de tu red. Cada
                uno tiene un tipo:</p>
                <div class="tw"><table>
                    <thead><tr><th>Tipo</th><th>Qué es</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Sectorial</strong></td><td>Antena que da cobertura a una zona</td></tr>
                        <tr><td><strong>Nodo</strong></td><td>Punto de concentración</td></tr>
                        <tr><td><strong>Switch</strong></td><td>Conmutador</td></tr>
                        <tr><td><strong>OLT</strong></td><td>Cabecera de fibra óptica</td></tr>
                        <tr><td><strong>Splitter</strong></td><td>Divisor óptico</td></tr>
                        <tr><td><strong>NAP</strong></td><td>Caja de distribución donde se conectan los clientes</td></tr>
                        <tr><td><strong>Mufa</strong></td><td>Empalme</td></tr>
                    </tbody>
                </table></div>

                <h3>Topología de fibra</h3>
                <p><em>Gestión → Topología FTTH</em> muestra el árbol completo:</p>
                <div class="flow">OLT  ──►  splitter  ──►  NAP  ──►  cliente</div>
                <p>Cada elemento indica cuántos puertos tiene y cuántos están ocupados; los ocupados
                <strong>se calculan solos</strong> a partir de lo que cuelga de él. Para armar el árbol,
                al crear un elemento indica cuál es su <strong>elemento padre</strong>.</p>
                <ul>
                    <li>En un <strong>splitter</strong> no escribes el número de puertos: lo saca de la <strong>relación de división</strong> que le pongas (<code>1:8</code> son 8 salidas).</li>
                    <li>En el resto de elementos sí indicas el total a mano.</li>
                    <li>Los puertos ocupados <strong>nunca se editan</strong>: si el número no cuadra, lo que está mal es lo que cuelga de ese elemento, no el contador.</li>
                </ul>

                <div class="note">
                    <span class="tag">Un cliente de fibra tiene que estar marcado como fibra</span>
                    <p>Si le asignas OLT y puerto NAP pero la casilla <em>Es fibra</em> quedó apagada, al
                    abrir <em>Editar</em> verás los campos de fibra vacíos y parecerá que se perdió la
                    información. Hoy el formulario <strong>lo detecta solo</strong> al cargar el cliente;
                    si ves un cliente así, ábrelo y guárdalo para dejarlo consistente.</p>
                </div>

                <p>Cada elemento tiene tres pestañas: <strong>Fotos</strong> (para documentar la
                instalación en campo), <strong>Notas</strong> (observaciones de mantenimiento) e
                <strong>Historial</strong> (registro automático de cambios).</p>
            </section>

            <!-- 13 ─────────────────────────────────────────────────────── -->
            <section id="planes">
                <span class="sec-num">13</span>
                <h2>Planes de internet</h2>

                <p><em>Gestión → Plan de Internet.</em> Al crear un plan indicas nombre, velocidad de
                bajada y subida, precio mensual y tipo. Según el tipo aparecen campos específicos (pool
                PPPoE, usuarios compartidos de HotSpot, tasa PCQ, ráfaga…).</p>

                <div class="tw"><table>
                    <thead><tr><th>Opción</th><th>Qué hace</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Plan de cortesía</strong></td><td>Los clientes con este plan <strong>nunca se facturan</strong></td></tr>
                        <tr><td><strong>Primera factura</strong></td><td>Define para todos los clientes del plan qué se cobra el mes de instalación y cuántos meses de cortesía siguen</td></tr>
                    </tbody>
                </table></div>

                <div class="note">
                    <span class="tag">Ejemplo real</span>
                    <p>El plan «Hogar 100M — instalación con mes de regalo» se configura como
                    <em>Prorrateado + 1 mes de cortesía</em>. Todo cliente que lo contrate hereda esa
                    promoción sin que haya que configurarlo uno por uno.</p>
                </div>

                <p>El plan llamado <strong>«Gratis»</strong> está bloqueado para uso exclusivo de
                cortesía.</p>
            </section>

            <!-- 14 ─────────────────────────────────────────────────────── -->
            <section id="soporte">
                <span class="sec-num">14</span>
                <h2>Soporte técnico</h2>

                <h3>Crear un ticket</h3>
                <p>En <em>Soporte → Nuevo Ticket</em>: elige el cliente, escribe el asunto y la
                descripción, elige <strong>categoría</strong> (Técnico, Facturación, Servicios, General)
                y <strong>prioridad</strong> (Baja, Media, Alta, Urgente). Si el problema es de un
                elemento de red concreto, selecciona el <strong>sectorial</strong> afectado.</p>

                <h3>Trabajar el ticket</h3>
                <ul>
                    <li><strong>Añadir mensajes.</strong> Puedes marcarlos como <strong>internos</strong>: esos no los ve el cliente.</li>
                    <li><strong>Cambiar el estado:</strong> Abierto → En progreso → Resuelto → Cerrado.</li>
                    <li><strong>Adjuntar archivos.</strong></li>
                    <li><strong>Generar un cargo:</strong> si la visita se cobra, esto crea una factura ligada al ticket.</li>
                </ul>

                <p><em>Soporte → Estadísticas</em> muestra tickets por estado, por prioridad y por
                categoría.</p>
            </section>

            <!-- 15 ─────────────────────────────────────────────────────── -->
            <section id="inventario">
                <span class="sec-num">15</span>
                <h2>Inventario</h2>

                <div class="tw"><table>
                    <thead><tr><th>Sección</th><th>Qué guarda</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Stock / Modelos</strong></td><td>Los modelos de equipo que manejas (marca, modelo, precio)</td></tr>
                        <tr><td><strong>Proveedores</strong></td><td>A quién le compras, con datos del asesor comercial</td></tr>
                        <tr><td><strong>Sucursales</strong></td><td>Dónde están físicamente los equipos</td></tr>
                        <tr><td><strong>Lista de equipos</strong></td><td><strong>Cada equipo individual</strong>, con su serial y su MAC</td></tr>
                        <tr><td><strong>Entregas y traspasos</strong></td><td>Pasar equipos de la bodega a un técnico y recibirlos de vuelta</td></tr>
                        <tr><td><strong>Movimientos</strong></td><td>El historial: quién recibió cada equipo y en qué instalación se usó</td></tr>
                    </tbody>
                </table></div>

                <h3>Por serial o por cantidad</h3>
                <ul>
                    <li><strong>Por serial</strong> — antenas, routers, ONU. Cada unidad se registra aparte con su serial y su MAC, y el sistema sabe en todo momento quién la tiene.</li>
                    <li><strong>Por cantidad</strong> — RJ45, cable, platos, cinta. Se lleva un saldo («a Juan le quedan 37 RJ45»), con su unidad de medida: unidad, metro, rollo.</li>
                </ul>
                <p>Esto no se puede cambiar a la ligera una vez el modelo tiene existencias, porque las
                dos formas de contar no se mezclan.</p>

                <h3>Entregar equipos a un técnico</h3>
                <p><em>Inventarios → Entregas y traspasos.</em> Eliges de dónde sale (una bodega o una
                persona), marcas los equipos y las cantidades de material, eliges a quién entra y
                registras. Sirve en los dos sentidos: entregar el lunes y recibir los sobrantes el
                viernes es el mismo formulario, cambiando origen por destino.</p>
                <div class="note">
                    <span class="tag">Nada se borra nunca</span>
                    <p>Un movimiento equivocado se corrige con el movimiento contrario, y los dos quedan
                    en el historial.</p>
                </div>

                <h3>Qué equipos puede usar cada quien</h3>
                <p>Al llenar la hoja de una instalación, el técnico <strong>sólo ve lo que tiene
                asignado</strong>. No puede usar un equipo que carga otro técnico: primero se lo tienen
                que traspasar. Quien administre inventario ve además las bodegas, y en una orden concreta
                cualquiera puede descargar lo que lleva el técnico asignado — así la secretaria puede
                capturar en oficina una visita ya hecha.</p>
                <p>Cada equipo o material que se carga a una instalación <strong>se descuenta de quien lo
                aportó</strong>. Si te equivocaste, el botón <strong>Devolver</strong> lo regresa a su
                dueño. Un equipo instalado queda ligado al cliente y ya no aparece como disponible para
                nadie.</p>
                <p>Para cargar muchos equipos de golpe, ve a
                <a href="#masivas" @click.prevent="goTo('masivas')">Acciones masivas → Importar
                inventario</a>.</p>
            </section>

            <!-- 16 ─────────────────────────────────────────────────────── -->
            <section id="personal">
                <span class="sec-num">16</span>
                <h2>Personal y roles</h2>

                <p><em>Personal → Nuevo.</em> Llena nombre, correo, contraseña y <strong>rol</strong>.
                El rol es lo que determina qué podrá ver y hacer.</p>

                <h3>Los permisos, agrupados como aparecen en pantalla</h3>
                <div class="tw"><table>
                    <thead><tr><th>Grupo</th><th>Permisos</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Clientes</strong></td><td>Lista de Clientes · Agregar Clientes · Editar Servicio Internet · Activar y Desactivar Clientes · Editar Descuento · Editar Saldo Pendiente · Eliminar Instalaciones · Tráfico Clientes</td></tr>
                        <tr><td><strong>Facturas</strong></td><td>Dashboard / Estadísticas · Buscar Facturas · Registrar Pagos · Eliminar Factura · Editar Total a Pagar · Agregar Gasto · Promesas de Pago</td></tr>
                        <tr><td><strong>Contabilidad</strong></td><td>Lista de Gastos · Editar Gasto · Lista de Facturas · Registrar Pagos · Editar Fecha de Pago · Registrar Pago Mayor 3 Días · Agregar Transferencia · Eliminar Transferencia</td></tr>
                        <tr><td><strong>Infraestructura</strong></td><td>Gestionar Routers · Ver Planes de Internet · Ver Sectoriales</td></tr>
                        <tr><td><strong>Inventario</strong></td><td>Ver Inventario</td></tr>
                        <tr><td><strong>Soporte</strong></td><td>Ver Soporte Técnico</td></tr>
                        <tr><td><strong>Facturación</strong></td><td>Ver Facturación</td></tr>
                        <tr><td><strong>Sistema</strong></td><td>Ver Personal · Gestionar Roles · Gestionar Configuración de Empresa · Gestionar Plantillas de Documentos · Ver Ajustes del Sistema · Ejecutar Acciones Masivas</td></tr>
                    </tbody>
                </table></div>

                <h3>Los roles que trae el sistema</h3>
                <div class="tw"><table>
                    <thead><tr><th>Rol</th><th>Alcance</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Administrador</strong></td><td>Todo, sin excepción</td></tr>
                        <tr><td><strong>Técnico</strong></td><td>Sólo clientes: verlos, agregarlos, editar su servicio, activar/desactivar, ver su tráfico y eliminar instalaciones. <strong>No ve dinero</strong></td></tr>
                        <tr><td><strong>Contabilidad</strong></td><td>Todo lo de plata: facturas, pagos, gastos, transferencias y estadísticas. <strong>No gestiona la red</strong> ni el personal</td></tr>
                        <tr><td><strong>Staff</strong></td><td>El operador de mostrador: clientes, planes, sectoriales, inventario, soporte, ver facturación y registrar pagos. <strong>No borra facturas ni toca configuración</strong></td></tr>
                        <tr><td><strong>Cliente</strong></td><td>Sin permisos de gestión. Es el rol de los clientes finales</td></tr>
                    </tbody>
                </table></div>

                <div class="note warn">
                    <span class="tag">Ojo con «Activar y Desactivar Clientes»</span>
                    <p>Ese permiso no sólo cambia un estado en pantalla: <strong>actúa sobre el router de
                    verdad</strong>. Es también el que habilita cargar clientes al equipo. No se lo des a
                    quien no deba tocar la red.</p>
                </div>

                <div class="note stop">
                    <span class="tag">Un permiso nuevo no llega solo a los roles que ya existían</span>
                    <p>Si tras una actualización una pestaña desaparece para los administradores, ve a
                    <em>Roles</em>, marca el permiso nuevo, guarda, y pide a los usuarios afectados que
                    <strong>cierren sesión y vuelvan a entrar</strong>.</p>
                </div>

                <h3>Cuándo hace falta volver a entrar</h3>
                <p>Si un administrador te acaba de cambiar el rol, <strong>recarga la página</strong>:
                normalmente con eso basta. Si tras recargar sigues sin ver lo que deberías, cierra sesión
                y vuelve a entrar. Y si aun así no aparece, entonces el permiso <strong>no está marcado
                en tu rol</strong> — no es cosa tuya.</p>
            </section>

            <!-- 17 ─────────────────────────────────────────────────────── -->
            <section id="configuracion">
                <span class="sec-num">17</span>
                <h2>Configuración de la empresa</h2>

                <h3>Datos, marca y mapas</h3>
                <ul>
                    <li><strong>Datos de la empresa:</strong> razón social, nombre comercial, NIT y dígito de verificación, régimen tributario, actividad económica, dirección, ciudad, departamento, teléfono y correo de facturación. <strong>Todo esto aparece en las facturas y contratos</strong>, así que revísalo bien.</li>
                    <li><strong>Marca:</strong> logo y color corporativo, que se aplican a los documentos que genera el sistema.</li>
                    <li><strong>Mapas:</strong> clave de Google Maps para el mapa de clientes. Se guarda cifrada y nunca se muestra de vuelta.</li>
                </ul>

                <h3>Plantillas de documentos</h3>
                <p>Pestaña <strong>Plantillas</strong>. Puedes editar el contenido de tres documentos:
                la <strong>Factura</strong> (cuerpo del PDF), el <strong>Contrato</strong> de servicio y
                el acta de <strong>Instalación</strong>. Se editan con un editor de texto enriquecido, e
                insertas <strong>marcadores</strong> que el sistema reemplaza por datos reales.</p>

                <div class="note warn">
                    <span class="tag">Un marcador que no existe no da error: sale en blanco</span>
                    <p>Si escribes un marcador inexistente, o el de otro tipo de documento por error (uno
                    de factura dentro del contrato), simplemente no aparece nada ahí. Revisa bien el
                    nombre exacto antes de guardar.</p>
                </div>

                <p><strong>Bloques de contenido.</strong> Además de los marcadores de texto hay
                marcadores especiales que insertan contenido complejo: la tabla de ítems de la factura,
                la galería de fotos de la instalación, las firmas del cliente y del técnico, y el
                <strong>logo de la empresa</strong>. Se insertan con botones aparte y el sistema los
                coloca en su propio párrafo para que no queden a mitad de una frase.</p>

                <h4>Tamaño y orientación de página</h4>
                <p>Arriba del editor eliges el tamaño del papel (A4, Carta u Oficio) y si el documento
                sale <strong>Vertical</strong> u <strong>Horizontal</strong>. Cada documento tiene su
                propia configuración.</p>
                <p>Usa <strong>Horizontal</strong> si tu diseño es a dos columnas — el caso típico del
                contrato de servicio en Colombia (formato CRC). Ese diseño necesita más ancho del que cabe
                en una hoja vertical, y si lo dejas en vertical el PDF sale con las columnas aplastadas.
                <strong>Si el documento se te ve apretado o cortado por los lados, esto es casi siempre la
                causa.</strong></p>

                <h4>El PDF de verdad, al lado del editor</h4>
                <p>A la derecha del editor tienes un panel titulado <strong>«PDF real»</strong>. No es una
                aproximación: es el mismo PDF que se le va a enviar al cliente, generado con datos de
                ejemplo. Se actualiza solo un par de segundos después de que dejas de escribir.</p>
                <div class="note">
                    <span class="tag">La respuesta definitiva a «en el editor se ve bien pero el PDF sale raro»</span>
                    <p>Si algo se ve distinto entre los dos paneles, <strong>manda el de la derecha</strong>.
                    El editor es una ayuda para escribir cómodo; el PDF es lo que se imprime.</p>
                </div>

                <h4>Empezar desde una plantilla base</h4>
                <p>Arriba del editor hay una fila de botones con plantillas ya armadas: el formato por
                defecto de cada documento y los <strong>formatos regulados de cada país</strong> para el
                contrato.</p>
                <div class="tw"><table>
                    <thead><tr><th>País</th><th>Plantilla</th><th>Qué trae</th></tr></thead>
                    <tbody>
                        <tr><td>—</td><td>Genérico · Contrato básico</td><td>Sin formato regulado de ningún país</td></tr>
                        <tr><td>Colombia</td><td>Contrato único CRC</td><td>Dos columnas, se abre en horizontal</td></tr>
                        <tr><td>México</td><td>Contrato de adhesión (IFT)</td><td>Carta de Derechos del IFT, velocidad mínima garantizada. Se abre en tamaño Carta</td></tr>
                        <tr><td>Argentina</td><td>Servicios TIC (ENACOM)</td><td>Baja por el mismo medio de contratación, bonificación automática, aviso de 30 días</td></tr>
                        <tr><td>Perú</td><td>Contrato de abonado (OSIPTEL)</td><td>Velocidad mínima garantizada del 40 %, apelación ante el TRASU</td></tr>
                        <tr><td>Chile</td><td>Suministro de internet (SUBTEL)</td><td>Velocidad promedio garantizada, descuento de oficio por indisponibilidad</td></tr>
                        <tr><td>Bolivia</td><td>Prestación de internet (ATT)</td><td>Derechos del usuario Ley 164, compensación por interrupciones</td></tr>
                    </tbody>
                </table></div>
                <div class="note warn">
                    <span class="tag">No son asesoría jurídica</span>
                    <p>Son un punto de partida con la <strong>estructura</strong> del formato, no una
                    certificación de cumplimiento. Revísalas y complétalas con las condiciones de tu
                    empresa (tarifas de reconexión, medios de atención, permanencia) antes de usarlas con
                    clientes reales.</p>
                </div>

                <h4>Modo avanzado</h4>
                <p>Un interruptor arriba del editor cambia a un modo donde editas el documento HTML
                completo —incluyendo diseño y colores— en un cuadro de texto plano. El interruptor
                <strong>no borra lo que escribiste</strong>: es la misma plantilla vista de dos formas, y
                puedes ir y volver las veces que quieras.</p>
                <div class="note stop">
                    <span class="tag">Si tu plantilla es un diseño propio, el modo avanzado no es opcional</span>
                    <p>Al generar el PDF, el modo normal <strong>elimina los anchos, los colores, los
                    estilos y las imágenes</strong> y mete lo que queda dentro de la plantilla base del
                    sistema. Medido sobre un contrato real: en modo avanzado sobrevive el 95 % del
                    documento, en modo normal el 51 % — y de ese 51 % se pierde todo lo que sostiene la
                    maquetación.</p>
                </div>

                <h4>Lo que el editor te avisa</h4>
                <ul>
                    <li><strong>La hoja de verdad.</strong> Ves una hoja del tamaño y la orientación elegidos, con <strong>líneas rojas donde va a cortar cada página</strong>. Si tu diseño es más ancho que la hoja, aparece un aviso en rojo con los números exactos («necesita 950 px y A4 vertical sólo deja 703 px») y un botón para cambiar a horizontal.</li>
                    <li><strong>El logo se ve puesto en su sitio</strong>, no como un marcador, del mismo tamaño con el que va a salir impreso. La plantilla sigue guardando el marcador, así que el día que cambies de logo los documentos salen con el nuevo.</li>
                    <li><strong>Las imágenes de internet salen marcadas en rojo.</strong> Una imagen enlazada a una dirección <code>https://</code> se muestra semitransparente y con borde punteado: <strong>en el PDF no va a aparecer</strong>. Sube el logo en Marca y usa el marcador; para otras imágenes (un sello, una firma escaneada), pégala <strong>incrustada</strong> (una imagen <code>data:</code> en PNG, JPG o GIF).</li>
                    <li><strong>Las fuentes que el PDF no tiene.</strong> El generador sólo conoce Times, Helvetica, Courier, DejaVu Sans, DejaVu Serif y las genéricas. Una plantilla copiada de Word suele traer Calibri o Arial, que en el PDF se reemplazan por Times y mueven los cortes de página. El arreglo es de una línea: <code>font-family: Calibri, Arial, sans-serif</code>.</li>
                    <li><strong>Los marcadores que no reconoce.</strong> Al previsualizar o guardar aparece un recuadro amarillo con la lista: cada marcador, por qué no funciona y cuál es el equivalente aquí. No bloquea nada.</li>
                </ul>

                <div class="note warn">
                    <span class="tag">Cuidado con las llaves de los marcadores</span>
                    <p>Tienen que ser exactamente dos a cada lado: <code v-pre>{{plan.valor_mensual}}</code>.
                    Si le falta una o tiene un espacio raro dentro, el sistema no lo reconoce y
                    <strong>lo imprime tal cual en el PDF</strong> — no sale en blanco, sale el texto con
                    las llaves. Los espacios <em>dentro</em> de las llaves sí dan igual:
                    <code v-pre>{{contrato.firma_cliente}}</code> y <code v-pre>{{ contrato.firma_cliente }}</code>
                    funcionan igual.</p>
                </div>

                <h4>Si vienes de WispHub</h4>
                <p>Es el error más común al migrar: el HTML se ve bien pero los datos salen en blanco,
                porque los nombres de marcador no coinciden.</p>
                <div class="tw"><table>
                    <thead><tr><th>WispHub</th><th>ISPWatch</th></tr></thead>
                    <tbody>
                        <tr><td><code v-pre>{{ cliente_nombre }}</code></td><td><code v-pre>{{cliente.nombre}}</code></td></tr>
                        <tr><td><code v-pre>{{ cliente_apellidos }}</code></td><td><code v-pre>{{cliente.apellido}}</code></td></tr>
                        <tr><td><code v-pre>{{ cliente.user.email }}</code></td><td><code v-pre>{{cliente.email}}</code></td></tr>
                        <tr><td><code v-pre>{{ plan_internet.nombre }}</code></td><td><code v-pre>{{plan.nombre}}</code></td></tr>
                        <tr><td><code v-pre>{{ plan_internet.precio }}</code></td><td><code v-pre>{{plan.valor_mensual}}</code></td></tr>
                        <tr><td><code v-pre>{{ fecha_instalacion }}</code></td><td><code v-pre>{{contrato.fecha}}</code></td></tr>
                        <tr><td><code v-pre>{{cliente.localidad}}</code></td><td><code v-pre>{{cliente.departamento}}</code></td></tr>
                        <tr><td><code>CO-NUMERO_CONTRATO_TAG</code></td><td><code v-pre>{{contrato.numero}}</code></td></tr>
                        <tr><td><code>&lt;img src="FIRMA_CLIENTE_NO_BORRAR"&gt;</code></td><td><code v-pre>{{contrato.firma_cliente}}</code></td></tr>
                        <tr><td>Logo con una dirección de internet</td><td><code v-pre>{{empresa.logo}}</code></td></tr>
                    </tbody>
                </table></div>
                <p><strong>El número de contrato ya trae el prefijo.</strong> Si escribes
                <code v-pre>CO-{{contrato.numero}}</code> te va a salir el prefijo dos veces.</p>

                <div class="note stop">
                    <span class="tag">No metas textos largos dentro de una celda de tabla</span>
                    <p>El generador de PDF no sabe partir una celda entre dos páginas: si el texto de una
                    celda no cabe en una hoja, <strong>lo que sobra no se imprime</strong> — sin ningún
                    aviso. En un contrato eso significa perder cláusulas. Para bloques largos usa
                    <code>&lt;div&gt;</code> en lugar de <code>&lt;table&gt;</code>: el texto fluye solo de
                    una página a la siguiente.</p>
                </div>

                <h4>Número consecutivo de los contratos</h4>
                <p>Cada contrato firmado desde el sistema recibe un número irrepetible con el
                <strong>prefijo</strong> que configures (si lo dejas vacío se usa <code>CTR</code>).
                Escribe el prefijo que quieras: letras, números, acentos, barras, puntos, espacios.</p>
                <div class="tw"><table>
                    <thead><tr><th>Si escribes</th><th>Los contratos quedan</th></tr></thead>
                    <tbody>
                        <tr><td><code>CTR</code></td><td><code>CTR-00001</code></td></tr>
                        <tr><td><code>FIBRAX</code></td><td><code>FIBRAX-00001</code></td></tr>
                        <tr><td><code>CNO/</code></td><td><code>CNO/00001</code></td></tr>
                        <tr><td><code>Contrato N° </code></td><td><code>Contrato N° 00001</code></td></tr>
                        <tr><td><code>FIBRA_2026.</code></td><td><code>FIBRA_2026.00001</code></td></tr>
                    </tbody>
                </table></div>
                <p>El guion lo pone el sistema <strong>sólo si tu prefijo termina en letra o número</strong>.
                Cambiar el prefijo <strong>no renumera los contratos ya firmados</strong>. Los contratos que
                subes tú a mano (un PDF escaneado) no reciben número, porque el sistema no puede escribir
                dentro de un archivo que no generó él.</p>
                <div class="note">
                    <span class="tag">Permiso</span>
                    <p>Esta pestaña necesita <em>Gestionar Plantillas de Documentos</em>, que es distinto
                    del de configuración de empresa. Si no la ves, revisa tu rol.</p>
                </div>

                <h3>Llaves de API (integraciones externas)</h3>
                <p>Sirven para que un sistema externo —un CRM, un tablero de indicadores, un proceso de
                conciliación contable— pueda <strong>leer</strong> los datos de un ISP sin que nadie tenga
                que entrar al panel ni compartir una contraseña.</p>
                <div class="note">
                    <span class="tag">Sólo el equipo de ISPWatch ve esta pestaña</span>
                    <p>Un administrador de un ISP no puede emitirse llaves a sí mismo: quién recibe los
                    datos y desde dónde es una decisión que se toma y se registra en un solo sitio.</p>
                </div>
                <p><strong>Qué puede hacer una llave — y qué no.</strong> Sólo <strong>consultar</strong>:
                no crea, no modifica y no borra nada. Sólo ve <strong>el tenant al que se emitió</strong>.
                Nunca devuelve contraseñas PPPoE ni de hotspot, ni las firmas de las actas. No puede tocar
                los routers.</p>
                <ol class="steps">
                    <li><span class="k">1</span><div><b>Crea el cliente de API</b><span>En <em>Configuración → Llaves API</em>, elige el <strong>tenant</strong>, ponle nombre («CRM del ISP») y un correo de contacto.</span></div></li>
                    <li><span class="k">2</span><div><b>Emite la llave</b><span>Nombre de la llave, fecha de vencimiento, <strong>permisos de lectura</strong> (marca sólo las áreas que la integración necesita) e <strong>IPs autorizadas</strong> —obligatorias—, que aceptan una IP suelta (<code>190.24.7.10</code>) o un rango (<code>190.24.8.0/24</code>).</span></div></li>
                </ol>
                <div class="note stop">
                    <span class="tag">La llave se muestra una sola vez</span>
                    <p>Cópiala en ese momento y entrégala por un canal seguro. El sistema no la guarda:
                    sólo guarda una huella para verificarla. Si se pierde, no hay forma de recuperarla —
                    se revoca y se emite otra.</p>
                </div>
                <p>La tabla de cada cliente muestra, por llave, los permisos, las IPs autorizadas, cuándo
                se usó por última vez y desde qué dirección. Si ves un último uso desde una IP que no
                esperabas, o una llave que lleva meses sin usarse, <strong>revócala</strong>: el corte es
                inmediato. El botón <strong>Desactivar</strong> del cliente apaga todas sus llaves a la
                vez sin borrarlas — es lo que hay que usar ante una sospecha.</p>
            </section>

            <!-- 18 ─────────────────────────────────────────────────────── -->
            <section id="masivas">
                <span class="sec-num">18</span>
                <h2>Acciones masivas</h2>

                <p>Reúne las operaciones que afectan a muchos registros a la vez.</p>

                <h3>Carga masiva de clientes</h3>
                <ol class="steps">
                    <li><span class="k">1</span><div><b>Descargar plantilla</b><span>Baja un Excel con varias hojas: clientes, planes, routers y sectoriales.</span></div></li>
                    <li><span class="k">2</span><div><b>Llena el Excel</b><span>La opción <em>Ver documentación de campos</em> explica qué va en cada columna.</span></div></li>
                    <li><span class="k">3</span><div><b>Súbelo con Importar</b><span></span></div></li>
                    <li><span class="k">4</span><div><b>Corrige los errores</b><span>Si los hay, el sistema los lista y puedes <strong>descargarlos en Excel</strong> para corregirlos y volver a subir sólo lo que falló.</span></div></li>
                </ol>
                <div class="note warn">
                    <span class="tag">Cuida las mayúsculas y los espacios</span>
                    <p>Los planes, routers y sectoriales se crean <strong>por nombre</strong>: si escribes
                    un nombre que no existe, se crea; si ya existe, se reutiliza.</p>
                </div>

                <h3>Las demás cargas</h3>
                <ul>
                    <li><strong>Actualización masiva de clientes:</strong> mismo flujo, pero con una plantilla que <strong>modifica</strong> clientes existentes en vez de crearlos.</li>
                    <li><strong>Importar inventario:</strong> igual, para equipos. Cada fila es un equipo con su serial y su MAC.</li>
                    <li><strong>Aprovisionamiento masivo:</strong> carga a los routers a varios clientes de golpe. Como cada cliente tarda alrededor de medio minuto, corre en segundo plano con una <strong>barra de progreso</strong>. Puedes cerrar la pantalla y seguir trabajando: no se cancela.</li>
                    <li><strong>Paneles de reintentos:</strong> las dos bitácoras explicadas en la <a href="#cortes" @click.prevent="goTo('cortes')">sección 09</a>.</li>
                </ul>
                <p>En el aprovisionamiento masivo vale lo mismo que en el alta individual: los routers que
                tengan <strong>apagada</strong> el alta automática se saltan, y los clientes sin router,
                plan o IP no se pueden aprovisionar.</p>
            </section>

            <!-- 19 ─────────────────────────────────────────────────────── -->
            <section id="faq">
                <span class="sec-num">19</span>
                <h2>Preguntas frecuentes</h2>

                <div class="faq">
                    <details>
                        <summary>No veo una sección del menú</summary>
                        <div class="a"><p>Tu rol no tiene el permiso. Pide a un administrador que lo revise en <em>Roles</em>. Si es un permiso recién creado, además tendrás que cerrar sesión y volver a entrar.</p></div>
                    </details>
                    <details>
                        <summary>Un cliente no recibió su factura este mes</summary>
                        <div class="a"><p>Revisa en este orden: (1) ¿el <strong>router</strong> del cliente tiene configuración de facturación asignada?, (2) ¿ya pasó el día y la hora de creación de ese router?, (3) ¿el cliente tiene un servicio activo con un plan que no sea de cortesía?, (4) ¿está marcado como <em>No facturar</em>?, (5) ¿está retirado o cancelado? — a esos no se les factura nunca, al suspendido sí, (6) ¿ya llegó al tope de <em>Dejar de facturar al moroso</em>?, (7) ¿es su primer mes y quedó en <em>No facturar</em> o con meses de cortesía?, (8) ¿alguien <strong>eliminó</strong> esa factura? Si es así, no se regenera.</p></div>
                    </details>
                    <details>
                        <summary>No se generó ninguna factura de ningún cliente</summary>
                        <div class="a"><p>Eso ya no es configuración: es que el proceso automático no corrió. El sistema tiene una revisión diaria que detecta exactamente eso y avisa por correo. Es cosa de soporte técnico.</p></div>
                    </details>
                    <details>
                        <summary>Guardé el cliente pero no se cargó al router</summary>
                        <div class="a"><p>Revisa, en este orden: (1) que el router tenga activada el <strong>alta automática</strong>, (2) que el cliente tenga router, plan e IP, (3) que el <strong>túnel VPN</strong> del equipo esté arriba. Después usa el botón de <strong>aprovisionar</strong> en la ficha del cliente.</p></div>
                    </details>
                    <details>
                        <summary>Corté a un cliente pero sigue navegando</summary>
                        <div class="a"><p>Es el problema más común y tiene tres causas típicas: el <strong>túnel VPN está caído</strong>, las <strong>reglas de bloqueo no están o quedaron muy abajo</strong>, o el cliente tenía conexiones abiertas de antes. Ve a <em>Gestión → Lista de Routers</em>, pulsa <strong>Verificar VPN</strong> y luego <strong>Aplicar reglas de bloqueo</strong>. Después, en <em>Acciones masivas</em>, pulsa <strong>Reconciliar</strong>. El procedimiento completo está en la <a href="#cortes" @click.prevent="goTo('cortes')">sección 09</a>.</p></div>
                    </details>
                    <details>
                        <summary>No se cortó ningún cliente de un router entero</summary>
                        <div class="a"><p>Sospecha del <strong>túnel VPN</strong> antes que de nada. Sin túnel, ISPWatch no le puede dar órdenes al equipo, pero igual marca a los clientes como cortados. Ficha del router → <strong>Verificar VPN</strong>.</p></div>
                    </details>
                    <details>
                        <summary>Las reglas de bloqueo están instaladas y aun así no bloquean</summary>
                        <div class="a"><p>El router lee sus reglas de arriba hacia abajo y se queda con la primera que coincide: si las de ISPWatch quedaron debajo de una que deja pasar el tráfico, nunca se ejecutan. Pulsa <strong>Aplicar reglas de bloqueo</strong> — las sube de nuevo al primer lugar. Es seguro repetirlo.</p></div>
                    </details>
                    <details>
                        <summary>El cliente pagó y sigue cortado</summary>
                        <div class="a"><p>La reconexión automática sólo funciona con cortes por facturación y sólo si el cliente quedó <strong>completamente</strong> al día. Si aún debe una factura anterior, sigue cortado. Revisa su saldo en la pestaña <em>Facturación</em> de su ficha.</p></div>
                    </details>
                    <details>
                        <summary>No encuentro un cliente al buscarlo</summary>
                        <div class="a"><p>Ya no es problema de mayúsculas. Revisa que estés buscando por un campo que esa pantalla mire (nombre, cédula, IP o correo) y que el cliente no esté filtrado por estado. Si lo borraron, no aparece: los clientes eliminados no se recuperan.</p></div>
                    </details>
                    <details>
                        <summary>No puedo subir varias fotos de instalación</summary>
                        <div class="a"><p>Ya puedes seleccionarlas todas juntas; el sistema las comprime y las sube una por una solo. Si aun así falla, casi siempre es una foto que se pasa de <strong>10 MB</strong> o que no es JPG/PNG/WEBP.</p></div>
                    </details>
                    <details>
                        <summary>Creé el cliente y no tiene internet</summary>
                        <div class="a"><p>Lo más probable: el router tiene <strong>apagada el alta automática</strong> (<em>Agregar cliente a MikroTik</em>), así que el cliente se guardó pero nunca se cargó al equipo. Revísalo en la ficha del router y después usa el botón de <strong>aprovisionar</strong>.</p></div>
                    </details>
                    <details>
                        <summary>Borré un cliente y sigue navegando</summary>
                        <div class="a"><p>Eliminarlo del sistema no siempre lo borra del router: si el equipo no respondía en ese momento, la configuración sigue puesta. Hay que sacarla a mano. Para la próxima: suspéndelo primero, confirma que quedó cortado, y después bórralo.</p></div>
                    </details>
                    <details>
                        <summary>El sistema me dice que espere / que hay demasiadas peticiones</summary>
                        <div class="a"><p>Las operaciones que tocan los routers están limitadas a propósito (unas diez por minuto, y menos para las cargas masivas) para no tumbar los equipos. Espera un minuto y sigue.</p></div>
                    </details>
                    <details>
                        <summary>Borré una factura por error</summary>
                        <div class="a"><p>Tendrás que crearla manualmente. El sistema <strong>no la regenera</strong> a propósito, para no resucitar facturas que un administrador decidió eliminar.</p></div>
                    </details>
                    <details>
                        <summary>¿Puedo cobrar medio mes al cliente que entra a mitad de mes?</summary>
                        <div class="a"><p>Sí: en su ficha, opción de primera factura <strong>Prorrateado</strong>. También puedes configurarlo en el plan para que aplique a todos los clientes que lo contraten.</p></div>
                    </details>
                </div>

                <div class="closer">
                    <h3>¿Todavía con dudas?</h3>
                    <p v-if="!props.publicMode">El
                    <RouterLink to="/centro-ayuda">Centro de Ayuda</RouterLink> reúne los artículos que
                    publica el equipo de ISPWatch, y suele tener el detalle de lo más reciente. Si aun
                    así te quedas atascado, escribe a soporte contando <strong>qué hacías</strong>,
                    <strong>qué esperabas</strong> y <strong>qué pasó</strong>: con esas tres frases el
                    diagnóstico es casi inmediato.</p>
                    <p v-else>Escribe a soporte contando <strong>qué hacías</strong>,
                    <strong>qué esperabas</strong> y <strong>qué pasó</strong>: con esas tres frases el
                    diagnóstico es casi inmediato. Dentro de la aplicación, este mismo manual está
                    siempre disponible en <em>Manual de Usuario</em>, junto al Centro de Ayuda.</p>
                </div>
            </section>

        </main>
    </div>
</div>
</template>

<style>
/* ─────────────────────────────────────────────────────────────────────────
   Manual de usuario · ISPWatch
   Todo va namespaced bajo .manual-doc para no filtrar estilos al resto de
   la app (por eso el bloque no es scoped: necesitamos definir las variables
   en el contenedor y sobreescribirlas desde .dark, que vive en <html>).

   Tipografía: grotesca de sistema en títulos / serif en cuerpo (un manual se
   lee largo) / monoespaciada en metadatos y variables — donde el monoespaciado
   significa algo, no decora.
   ───────────────────────────────────────────────────────────────────────── */

/* Los tokens viven en .manual-theme, no en .manual-doc, para que la página
   pública (/ayuda) pueda vestir su barra y su pie con la MISMA paleta sin
   duplicar los valores: envuelve todo en .manual-theme y hereda. */
.manual-theme {
    --ground:       #F4F5F8;
    --surface:      #FFFFFF;
    --surface-sunk: #EDEFF4;
    --ink:          #14161C;
    --ink-soft:     #414653;
    --ink-mute:     #6B7280;
    --rule:         #E1E4EA;
    --rule-strong:  #C6CBD6;
    --accent:       #4F46E5;
    --accent-soft:  #EEF0FE;
    --accent-ink:   #4338CA;
    --warn:         #A16207;
    --warn-soft:    #FBF3E2;
    --danger:       #B02A24;
    --danger-soft:  #FCECEA;
    --ok:           #0E7C5A;
    --shadow:       0 1px 2px rgba(20, 22, 28, .05), 0 8px 24px -12px rgba(20, 22, 28, .12);

    --f-display: "Helvetica Neue", Helvetica, -apple-system, "Segoe UI", system-ui, sans-serif;
    --f-body:    Georgia, "Iowan Old Style", "Palatino Linotype", ui-serif, serif;
    --f-mono:    ui-monospace, "SF Mono", "Cascadia Code", "Roboto Mono", Menlo, monospace;

    --measure: 68ch;
}

.manual-doc {
    background: var(--ground);
    color: var(--ink);
    font-family: var(--f-body);
    font-size: 17px;
    line-height: 1.68;
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}

.dark .manual-theme {
    --ground:       #0F1117;
    --surface:      #161A23;
    --surface-sunk: #1C212C;
    --ink:          #E6E8ED;
    --ink-soft:     #B6BCC8;
    --ink-mute:     #7E8697;
    --rule:         #262C38;
    --rule-strong:  #38404F;
    --accent:       #818CF8;
    --accent-soft:  #1B1E36;
    --accent-ink:   #A5B4FC;
    --warn:         #D9A44E;
    --warn-soft:    #2A2113;
    --danger:       #E08078;
    --danger-soft:  #2C1917;
    --ok:           #3FBF8F;
    --shadow:       0 1px 2px rgba(0, 0, 0, .4), 0 8px 24px -12px rgba(0, 0, 0, .6);
}

.manual-doc * { box-sizing: border-box; }

/* ── Masthead ────────────────────────────────────────────────────────── */

.manual-doc .masthead {
    border-bottom: 1px solid var(--rule);
    background: var(--surface);
}
.manual-doc .masthead-in {
    max-width: 1220px;
    margin: 0 auto;
    padding: 34px 28px 30px;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 20px 40px;
}
.manual-doc .wordmark { display: flex; align-items: center; gap: 13px; }
.manual-doc .mark {
    width: 38px; height: 38px;
    border-radius: 10px;
    background: var(--accent);
    display: grid; place-items: center;
    flex: none;
}
.manual-doc .mark svg { width: 21px; height: 21px; display: block; }
.manual-doc .wordmark h1 {
    font-family: var(--f-display);
    font-size: 25px;
    font-weight: 700;
    letter-spacing: -.024em;
    margin: 0;
    line-height: 1.1;
    color: var(--ink);
}
.manual-doc .wordmark p {
    font-family: var(--f-mono);
    font-size: 10.5px;
    letter-spacing: .13em;
    text-transform: uppercase;
    color: var(--ink-mute);
    margin: 3px 0 0;
}
.manual-doc .masthead-meta {
    margin-left: auto;
    font-family: var(--f-mono);
    font-size: 11.5px;
    color: var(--ink-mute);
    line-height: 1.9;
    text-align: right;
}
.manual-doc .masthead-meta b { color: var(--ink-soft); font-weight: 500; }

/* ── Shell ───────────────────────────────────────────────────────────── */

.manual-doc .shell {
    max-width: 1220px;
    margin: 0 auto;
    padding: 0 28px 96px;
    display: grid;
    grid-template-columns: 232px minmax(0, 1fr);
    gap: 56px;
    align-items: start;
}

/* ── Índice ──────────────────────────────────────────────────────────── */

.manual-doc .toc {
    position: sticky;
    top: 24px;
    padding-top: 40px;
    max-height: calc(100vh - 48px);
    overflow-y: auto;
}
.manual-doc .toc-label {
    font-family: var(--f-mono);
    font-size: 10px;
    letter-spacing: .15em;
    text-transform: uppercase;
    color: var(--ink-mute);
    margin: 0 0 12px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--rule);
}
.manual-doc .toc-search {
    width: 100%;
    margin: 0 0 12px;
    padding: 6px 10px;
    font-family: var(--f-display);
    font-size: 13px;
    color: var(--ink);
    background: var(--surface);
    border: 1px solid var(--rule);
    border-radius: 6px;
}
.manual-doc .toc-search::placeholder { color: var(--ink-mute); }
.manual-doc .toc-search:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px var(--accent-soft);
}
.manual-doc .toc ol {
    list-style: none;
    margin: 0; padding: 0;
    display: flex;
    flex-direction: column;
    gap: 1px;
}
.manual-doc .toc a {
    display: grid;
    grid-template-columns: 22px 1fr;
    gap: 8px;
    padding: 5px 8px 5px 4px;
    border-radius: 5px;
    text-decoration: none;
    color: var(--ink-soft);
    font-family: var(--f-display);
    font-size: 13.5px;
    line-height: 1.4;
    border-left: 2px solid transparent;
    transition: background .15s, color .15s, border-color .15s;
}
.manual-doc .toc a .n {
    font-family: var(--f-mono);
    font-size: 10.5px;
    color: var(--ink-mute);
    padding-top: 2px;
    font-variant-numeric: tabular-nums;
}
.manual-doc .toc a:hover { background: var(--surface-sunk); color: var(--ink); }
.manual-doc .toc a.on {
    color: var(--accent-ink);
    border-left-color: var(--accent);
    background: var(--accent-soft);
    font-weight: 600;
}
.manual-doc .toc a.on .n { color: var(--accent); }
.manual-doc .toc-empty {
    font-family: var(--f-display);
    font-size: 13px;
    color: var(--ink-mute);
    padding: 8px 4px;
}
.manual-doc .toc-select { display: none; }

/* ── Contenido ───────────────────────────────────────────────────────── */

.manual-doc .doc { padding-top: 40px; min-width: 0; }

.manual-doc .lede {
    font-size: 20px;
    line-height: 1.6;
    color: var(--ink-soft);
    max-width: var(--measure);
    margin: 0 0 40px;
    padding-bottom: 36px;
    border-bottom: 1px solid var(--rule);
}
.manual-doc .lede strong { color: var(--ink); font-weight: 600; }

.manual-doc section {
    scroll-margin-top: 24px;
    padding: 46px 0;
    border-bottom: 1px solid var(--rule);
}
.manual-doc section:last-of-type { border-bottom: 0; }

.manual-doc .sec-num {
    font-family: var(--f-mono);
    font-size: 11px;
    letter-spacing: .14em;
    color: var(--accent);
    display: block;
    margin-bottom: 9px;
    font-variant-numeric: tabular-nums;
}
.manual-doc h2 {
    font-family: var(--f-display);
    font-size: 29px;
    font-weight: 700;
    letter-spacing: -.026em;
    line-height: 1.18;
    margin: 0 0 20px;
    max-width: var(--measure);
    color: var(--ink);
}
.manual-doc h3 {
    font-family: var(--f-display);
    font-size: 17.5px;
    font-weight: 700;
    letter-spacing: -.012em;
    margin: 34px 0 12px;
    max-width: var(--measure);
    color: var(--ink);
}
.manual-doc h4 {
    font-family: var(--f-mono);
    font-size: 11px;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: var(--ink-mute);
    font-weight: 500;
    margin: 26px 0 10px;
}
.manual-doc p,
.manual-doc ul,
.manual-doc ol { max-width: var(--measure); }
.manual-doc p { margin: 0 0 16px; }
.manual-doc ul,
.manual-doc ol { margin: 0 0 16px; padding-left: 22px; }
.manual-doc li { margin-bottom: 7px; }
.manual-doc li::marker { color: var(--ink-mute); }
.manual-doc strong { font-weight: 600; }
.manual-doc a {
    color: var(--accent-ink);
    text-decoration-thickness: 1px;
    text-underline-offset: 2px;
}
.manual-doc a:focus-visible,
.manual-doc .toc a:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
    border-radius: 3px;
}

.manual-doc code {
    font-family: var(--f-mono);
    font-size: .84em;
    background: var(--surface-sunk);
    border: 1px solid var(--rule);
    border-radius: 4px;
    padding: 1px 5px;
    color: var(--ink-soft);
    white-space: nowrap;
}

/* ── Tablas ──────────────────────────────────────────────────────────── */

.manual-doc .tw {
    overflow-x: auto;
    margin: 0 0 22px;
    border: 1px solid var(--rule);
    border-radius: 8px;
    background: var(--surface);
}
.manual-doc table {
    border-collapse: collapse;
    width: 100%;
    font-family: var(--f-display);
    font-size: 14.5px;
    line-height: 1.5;
}
.manual-doc th,
.manual-doc td {
    text-align: left;
    padding: 10px 15px;
    border-bottom: 1px solid var(--rule);
    vertical-align: top;
}
.manual-doc thead th {
    font-family: var(--f-mono);
    font-size: 10px;
    letter-spacing: .11em;
    text-transform: uppercase;
    color: var(--ink-mute);
    font-weight: 500;
    background: var(--surface-sunk);
    white-space: nowrap;
}
.manual-doc tbody tr:last-child td { border-bottom: 0; }
.manual-doc td.ctr,
.manual-doc th.ctr { text-align: center; font-variant-numeric: tabular-nums; }
.manual-doc .yes { color: var(--ok); font-weight: 700; }
.manual-doc .no  { color: var(--ink-mute); }

/* ── Avisos ──────────────────────────────────────────────────────────── */

.manual-doc .note {
    border-left: 3px solid var(--accent);
    background: var(--accent-soft);
    padding: 15px 18px;
    border-radius: 0 7px 7px 0;
    margin: 0 0 22px;
    max-width: var(--measure);
    font-size: 15.5px;
    line-height: 1.6;
}
.manual-doc .note > :last-child { margin-bottom: 0; }
.manual-doc .note .tag {
    display: block;
    font-family: var(--f-mono);
    font-size: 10px;
    letter-spacing: .13em;
    text-transform: uppercase;
    color: var(--accent-ink);
    margin-bottom: 5px;
}
.manual-doc .note.warn { border-left-color: var(--warn); background: var(--warn-soft); }
.manual-doc .note.warn .tag { color: var(--warn); }
.manual-doc .note.stop { border-left-color: var(--danger); background: var(--danger-soft); }
.manual-doc .note.stop .tag { color: var(--danger); }
.dark .manual-doc .note.warn,
.dark .manual-doc .note.stop { color: var(--ink); }

/* La regla que gobierna un módulo entero se trata distinto: es la tesis. */
.manual-doc .thesis {
    background: var(--ink);
    color: var(--ground);
    border-radius: 11px;
    padding: 30px 32px;
    margin: 0 0 28px;
    max-width: var(--measure);
    box-shadow: var(--shadow);
}
.manual-doc .thesis .tag {
    font-family: var(--f-mono);
    font-size: 10px;
    letter-spacing: .16em;
    text-transform: uppercase;
    color: var(--accent);
    display: block;
    margin-bottom: 12px;
}
.dark .manual-doc .thesis .tag { color: var(--accent-ink); }
.manual-doc .thesis p {
    font-family: var(--f-display);
    font-size: 20px;
    line-height: 1.45;
    font-weight: 500;
    letter-spacing: -.014em;
    margin: 0 0 14px;
    max-width: none;
}
.manual-doc .thesis p:last-child {
    font-family: var(--f-body);
    font-size: 15.5px;
    font-weight: 400;
    line-height: 1.62;
    letter-spacing: 0;
    opacity: .8;
    margin: 0;
}

/* ── Pasos ───────────────────────────────────────────────────────────── */

.manual-doc .steps {
    list-style: none;
    padding: 0;
    margin: 0 0 22px;
    max-width: var(--measure);
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.manual-doc .steps li {
    display: grid;
    grid-template-columns: 27px 1fr;
    gap: 14px;
    margin: 0;
}
.manual-doc .steps .k {
    font-family: var(--f-mono);
    font-size: 11px;
    font-weight: 500;
    width: 27px; height: 27px;
    border-radius: 50%;
    border: 1px solid var(--rule-strong);
    color: var(--ink-mute);
    display: grid; place-items: center;
    margin-top: 2px;
    font-variant-numeric: tabular-nums;
}
.manual-doc .steps b {
    font-family: var(--f-display);
    font-size: 15.5px;
    display: block;
    margin-bottom: 2px;
}
.manual-doc .steps span { font-size: 15.5px; color: var(--ink-soft); }

/* ── FAQ ─────────────────────────────────────────────────────────────── */

.manual-doc .faq {
    display: flex;
    flex-direction: column;
    max-width: var(--measure);
}
.manual-doc .faq details {
    border-bottom: 1px solid var(--rule);
    padding: 3px 0;
}
.manual-doc .faq summary {
    cursor: pointer;
    padding: 13px 26px 13px 0;
    font-family: var(--f-display);
    font-size: 15.5px;
    font-weight: 600;
    list-style: none;
    position: relative;
    color: var(--ink);
}
.manual-doc .faq summary::-webkit-details-marker { display: none; }
.manual-doc .faq summary::after {
    content: "+";
    position: absolute; right: 4px; top: 12px;
    font-family: var(--f-mono);
    font-size: 17px;
    color: var(--ink-mute);
}
.manual-doc .faq details[open] summary::after { content: "−"; }
.manual-doc .faq summary:hover { color: var(--accent-ink); }
.manual-doc .faq summary:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
    border-radius: 4px;
}
.manual-doc .faq .a { padding: 0 26px 15px 0; font-size: 15.5px; color: var(--ink-soft); }
.manual-doc .faq .a p { margin: 0; max-width: none; }

/* ── Diagramas de flujo ──────────────────────────────────────────────── */

.manual-doc .flow {
    font-family: var(--f-mono);
    font-size: 12.5px;
    line-height: 1.85;
    background: var(--surface-sunk);
    border: 1px solid var(--rule);
    border-radius: 8px;
    padding: 18px 20px;
    overflow-x: auto;
    margin: 0 0 22px;
    color: var(--ink-soft);
    white-space: pre;
    max-width: var(--measure);
}

/* ── Cierre ──────────────────────────────────────────────────────────── */

.manual-doc .closer {
    margin-top: 46px;
    padding: 26px 28px;
    border: 1px solid var(--rule);
    border-radius: 10px;
    background: var(--surface);
    max-width: var(--measure);
    box-shadow: var(--shadow);
}
.manual-doc .closer h3 { margin-top: 0; }
.manual-doc .closer p:last-child { margin-bottom: 0; }

/* ── Responsive ──────────────────────────────────────────────────────── */

@media (max-width: 900px) {
    .manual-doc { font-size: 16px; }
    .manual-doc .shell { grid-template-columns: minmax(0, 1fr); gap: 0; padding: 0 20px 72px; }
    .manual-doc .masthead-in { padding: 26px 20px 22px; }
    .manual-doc .masthead-meta { margin-left: 0; text-align: left; width: 100%; }
    .manual-doc .toc { position: static; max-height: none; padding-top: 26px; }
    .manual-doc .toc ol,
    .manual-doc .toc-search,
    .manual-doc .toc-empty { display: none; }
    .manual-doc .toc-select {
        display: block;
        width: 100%;
        padding: 11px 13px;
        font-family: var(--f-display);
        font-size: 15px;
        color: var(--ink);
        background: var(--surface);
        border: 1px solid var(--rule-strong);
        border-radius: 8px;
    }
    .manual-doc .doc { padding-top: 8px; }
    .manual-doc section { scroll-margin-top: 80px; }
    .manual-doc h2 { font-size: 25px; }
    .manual-doc .lede { font-size: 18px; }
    .manual-doc .thesis { padding: 24px 22px; }
    .manual-doc .thesis p { font-size: 18px; }
}

@media (prefers-reduced-motion: reduce) {
    .manual-doc * { transition: none !important; animation: none !important; }
}
</style>
