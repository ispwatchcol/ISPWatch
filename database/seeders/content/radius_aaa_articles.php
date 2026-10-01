<?php

/**
 * Artículos del Centro de Ayuda sobre el método de control RADIUS (AAA).
 *
 * FUENTE ÚNICA, igual que `api_publica_articles.php`. Lo consumen dos caminos:
 *
 *  - `HelpCenterSeeder`, que en desarrollo BORRA y vuelve a sembrar todo;
 *  - la migración `2026_09_22_100000_seed_help_center_radius_aaa`, que es la
 *    que lleva el contenido a producción (los seeders nunca corren allí), y
 *    `2026_10_01_100000_update_help_center_integracion_aaa`, que lleva las
 *    correcciones posteriores sin pisar lo editado desde el panel.
 *
 * POR QUÉ TAMBIÉN VIAJA AQUÍ EL ARTÍCULO DEL MÉTODO DE CONTROL
 * -------------------------------------------------------------
 * Ese artículo ya existía, pero listaba CINCO métodos: RADIUS (AAA) no
 * aparecía por ningún lado, aunque el formulario del router lo ofrece desde
 * 2026-08-14. No es un artículo nuevo que agregar, es uno viejo que quedó
 * incompleto, así que su texto corregido tiene que vivir en el mismo lugar
 * que el resto — si no, el seeder y producción volverían a divergir.
 *
 * POR QUÉ LA CATEGORÍA VIAJA CON SUS DATOS
 * -----------------------------------------
 * «Routers y Red» la crea `HelpCenterSeeder`, que NO corre en producción: allí
 * el Centro de Ayuda sólo tiene lo que alguna migración haya sembrado. Una
 * migración que diera la categoría por existente no haría nada en el único
 * entorno donde alguien lee el manual, y sin dar ningún error. Por eso los
 * datos de la categoría van aquí y la migración la crea si falta.
 *
 * @return array{category: array<string, mixed>, control_mode_article: array<string, mixed>, articles: array<int, array<string, mixed>>}
 */

return [
    'category' => [
        'name'          => 'Routers y Red',
        'icon'          => 'bi-router',
        'description'   => 'Conectar y controlar los equipos de tu red.',
        'display_order' => 5,
    ],

    // ─────────────────────────────────────────────────────────────
    // Artículo EXISTENTE, corregido: le faltaba el sexto método.
    // ─────────────────────────────────────────────────────────────
    'control_mode_article' => [
        'title'         => 'El método de control del router',
        'display_order' => 2,
        'is_published'  => true,
        'tips'          => 'Sólo puede haber UN método de control activo por router. IP Bindings y Amarre son adicionales: se suman al método elegido.',
        'content' => <<<'HTML'
<h2>Cómo controla el router a sus clientes</h2>
<p>En la ficha del router eliges el método. <strong>Sólo puede haber uno activo:</strong></p>
<ul>
  <li><strong>Simple Queue</strong>: control de velocidad por IP. El más común.</li>
  <li><strong>PCQ</strong>: reparto equitativo de ancho de banda.</li>
  <li><strong>HotSpot</strong>: clientes que entran con usuario y contraseña en un portal.</li>
  <li><strong>PPPoE</strong>: clientes con usuario y contraseña de conexión.</li>
  <li><strong>DHCP Leases</strong>: asignación fija por dirección MAC.</li>
  <li><strong>RADIUS (AAA)</strong>: tienes tu propio servidor de autenticación y es él quien gestiona la red. ISPWatch deja de escribir en el equipo.</li>
</ul>
<p>Al activar uno, los demás se apagan solos: no hace falta desmarcarlos a mano.</p>
<h3>RADIUS funciona distinto a los otros cinco</h3>
<p>Con los cinco primeros, ISPWatch entra al router y escribe la configuración de cada cliente. Con <strong>RADIUS (AAA)</strong> no entra: la gestión pasa a tu servidor. Es un cambio de fondo, no una variante más, y tiene su propio artículo: <em>«RADIUS (AAA): cuando otro sistema gestiona la red»</em>.</p>
<h3>Opciones adicionales</h3>
<p>Estas dos <strong>se suman</strong> al método elegido, no lo reemplazan:</p>
<ul>
  <li><strong>IP Bindings</strong>: fija la relación IP–equipo.</li>
  <li><strong>Amarre</strong>: bloquea al cliente si cambia de equipo.</li>
</ul>
<p>El método elegido determina qué credenciales te pide el formulario del cliente: usuario y contraseña PPPoE, usuario y contraseña HotSpot, o la dirección MAC.</p>
HTML,
    ],

    // ─────────────────────────────────────────────────────────────
    // Artículo NUEVO.
    // ─────────────────────────────────────────────────────────────
    'articles' => [
        [
            'title'         => 'RADIUS (AAA): cuando otro sistema gestiona la red',
            'display_order' => 3,
            'is_published'  => true,
            'tips'          => 'ISPWatch decide a quién cortar y cuándo, pero no lo ejecuta: eso lo hace tu servidor. Antes de pasar clientes reales, prueba el ciclo completo con uno solo.',
            'content' => <<<'HTML'
<h2>Cuándo se usa este método</h2>
<p>Elige <strong>RADIUS (AAA)</strong> cuando ya tienes <strong>tu propio servidor de autenticación</strong> (normalmente FreeRADIUS) y quieres que sea él —y no ISPWatch— quien gestione el acceso de los clientes a la red.</p>
<p>Al activarlo le estás diciendo a ISPWatch: <em>«de este router me encargo yo»</em>. A partir de ahí, ISPWatch <strong>no vuelve a escribir nada en ese equipo</strong>.</p>

<h3>Funciona al revés que los demás métodos</h3>
<p>Con Simple Queue, PCQ, HotSpot, PPPoE o DHCP, ISPWatch <strong>entra</strong> al router y le escribe la configuración de cada cliente. Con RADIUS es el router el que <strong>pregunta</strong> a tu servidor en cada conexión.</p>
<p>Esto tiene una ventaja que se nota enseguida: <strong>dar de alta a un cliente es instantáneo</strong>. No hay que esperar a que el sistema se conecte al equipo, y las cargas masivas dejan de tardar o de fallar por demora.</p>

<h2>Qué deja de hacer ISPWatch</h2>
<p>En un router con RADIUS activo, ISPWatch <strong>ya no</strong>:</p>
<ul>
  <li>Carga los clientes en el MikroTik.</li>
  <li>Crea Simple Queues, listas PCQ, usuarios de HotSpot, secrets PPPoE ni leases DHCP.</li>
  <li>Instala las reglas de bloqueo de morosos en el equipo.</li>
  <li><strong>Corta ni reconecta</strong> directamente en el router.</li>
</ul>
<p>No lo hace «a medias» ni lo intenta por si acaso: ni siquiera trata de conectarse al equipo. Por eso un router en modo RADIUS puede estar apagado, o no existir físicamente, sin que aparezcan errores de conexión.</p>

<h2>Qué sigue haciendo ISPWatch</h2>
<p>Todo lo comercial, que es lo importante:</p>
<ul>
  <li>Genera las facturas y aplica los pagos.</li>
  <li>Calcula la mora y <strong>decide a quién hay que cortar y cuándo</strong>.</li>
  <li>Decide cuándo hay que reconectar, en cuanto el cliente paga.</li>
  <li>Publica esas decisiones para que tu sistema las aplique.</li>
</ul>

<h3>Cómo funciona un corte por mora</h3>
<ol>
  <li>ISPWatch detecta la mora según la configuración de facturación de ese router.</li>
  <li>Cambia el estado del servicio a <strong>suspendido</strong>. Lo ves en el panel igual que siempre.</li>
  <li><strong>Publica el cambio</strong> para que tu servidor lo recoja.</li>
  <li><strong>Tu sistema ejecuta el corte.</strong></li>
  <li>Cuando el cliente paga, ISPWatch lo reactiva y publica la reconexión. Tu sistema la aplica.</li>
</ol>
<p><strong>Ten esto presente:</strong> ISPWatch da la orden, pero <strong>no puede comprobar que el corte se aplicó de verdad</strong> — eso ocurre en tu servidor, fuera de su alcance. En los otros métodos ISPWatch verifica y reintenta; aquí la verificación queda de tu lado.</p>
<p>El corte por mora sólo se hace automáticamente si el router tiene configuración de facturación y su tipo de corte es <strong>Corte Automático</strong>, con día y hora de corte. Con <strong>Corte Manual</strong> ISPWatch no suspende por su cuenta: lo hace alguien desde el panel, y en ese momento se publica igual.</p>

<h2>Qué necesita cada cliente</h2>
<ul>
  <li><strong>Usuario y contraseña PPPoE</strong>. Sin ellos el sistema te avisa de que el alta quedó incompleta. El usuario no se puede repetir dentro del mismo router.</li>
  <li><strong>La IP es opcional.</strong> En un router RADIUS ISPWatch no la usa para nada técnico. Si la cargas, no se puede repetir dentro del mismo router (sí en otro).</li>
</ul>
<p>Si tu servidor tiene sus propios valores de red para un cliente, esos mandan en la red: ISPWatch conserva los suyos como dato administrativo y no intenta corregir diferencias.</p>

<h2>Mover un cliente de router</h2>
<ul>
  <li>El cliente toma de inmediato la configuración de facturación del router nuevo: día de factura, recordatorio, tipo y hora de corte y canal de aviso. Sus facturas y saldos no cambian.</li>
  <li>Si el router anterior lo gestionaba ISPWatch (no RADIUS), ISPWatch <strong>retira en segundo plano la configuración del cliente de ese equipo</strong>. El resultado queda en la bitácora de <strong>Auditoría</strong>; si dice que no se pudo, hay que retirarla a mano.</li>
  <li>Tu servidor se entera por el listado de cambios, con el router anterior y el nuevo.</li>
</ul>

<h2>Eliminar un cliente</h2>
<p>Al eliminar a un cliente de un router RADIUS, ISPWatch no intenta conectarse al equipo (no hay nada suyo que borrar allí). Tu servidor recibe un aviso de eliminación con los servicios y el router del cliente, para que lo revoque.</p>

<h2>Qué datos del router hacen falta</h2>
<p>Con RADIUS activo, estos campos <strong>puedes dejarlos vacíos</strong>: interfaz LAN y WAN, rangos de IP, puertos API/web/SSH y los datos de VPN.</p>
<p>En cambio, el formulario <strong>todavía te va a exigir</strong> nombre, IP, usuario y contraseña del equipo, versión de firmware y estado. Aunque en este modo el sistema nunca los usa, hoy siguen siendo obligatorios para poder guardar. Si el router es sólo un agrupador y no tienes esos datos, puedes poner valores de relleno: no se conectan a ningún lado.</p>

<h2>Usar routers como agrupadores</h2>
<p>Como en este modo ISPWatch no se conecta a ningún equipo, <strong>un router puede ser sólo una agrupación</strong> y no un MikroTik real. Es útil para separar clientes por criterio propio —por ejemplo, los de facturación electrónica de los de cuenta de cobro—, porque <strong>la configuración de facturación se define por router</strong>: cada grupo puede tener su propio día de facturación, su hora de aviso y su hora de corte.</p>
<p><strong>Antes de mover muchos clientes a un mismo grupo:</strong> dentro de un router, dos clientes no pueden tener la misma IP ni el mismo usuario PPPoE. Si vienen de routers distintos donde se repetían, el sistema rechazará el duplicado. Conviene revisarlo antes de una migración grande.</p>
<p>Para un router que es sólo un agrupador: no le configures VPN ni historial de tráfico (así ISPWatch no lo vigila ni avisa que está caído), y no uses los botones del panel que se conectan al equipo — fallarán, y es lo esperado.</p>

<h2>Cómo lo ve el sistema que conectas</h2>
<p>Si el proveedor de tu servidor AAA se conecta por la <strong>API pública</strong>, ISPWatch le indica en cada cliente y en cada servicio <strong>si su router está gestionado externamente</strong>, para que sepa de cuáles se tiene que hacer cargo. Por el <strong>listado de cambios</strong> le llegan las suspensiones y reconexiones, las bajas, los clientes eliminados, los cambios de router, de IP y de usuario PPPoE, y la activación o desactivación de RADIUS en un router.</p>
<p>Todo lo que ese equipo técnico necesita —qué campo decide el acceso, qué garantiza el listado de cambios y cómo sincronizar sin perder nada— está en <em>Integraciones y API → «Guía técnica para integradores AAA: sincronizar sin perder cambios»</em>.</p>
<p>Recuerda que la API pública es de <strong>sólo lectura</strong>: tu sistema puede enterarse de lo que ISPWatch decidió, pero no escribir de vuelta.</p>

<h2>Recomendación antes de activarlo</h2>
<p>El método de control es <strong>por router</strong>, no por cliente: no se puede activar RADIUS para unos clientes sí y otros no dentro del mismo router. Al activarlo, afecta de inmediato a todos los clientes de ese equipo.</p>
<p>Por eso, para probar: <strong>crea un router aparte</strong> con RADIUS activo, mueve <strong>un solo cliente</strong>, y valida el ciclo completo —alta, factura, mora, corte, pago, reconexión y cambio de router— antes de tocar el resto. Si quieres probar el corte automático, ese router necesita configuración de facturación y tipo de corte <strong>Corte Automático</strong>.</p>
HTML,
        ],
    ],
];
