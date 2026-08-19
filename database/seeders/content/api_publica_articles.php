<?php

/**
 * Artículos del Centro de Ayuda sobre la API pública.
 *
 * FUENTE ÚNICA. Lo consumen dos caminos que no se pueden mezclar:
 *
 *  - `HelpCenterSeeder`, que en desarrollo BORRA y vuelve a sembrar todo el
 *    Centro de Ayuda;
 *  - la migración `2026_08_19_100000_seed_help_center_api_publica`, que es la
 *    que lleva este contenido a producción (los seeders nunca corren allí).
 *
 * Sin este archivo compartido habría dos copias del mismo texto, y la que se
 * quedaría vieja sería siempre la de producción — que es la única que alguien
 * lee de verdad.
 *
 * @return array{name: string, icon: string, description: string, display_order: int, articles: array<int, array<string, mixed>>}
 */

return [
    'name'          => 'Integraciones y API',
    'icon'          => 'bi-plug',
    'description'   => 'Conectar ISPWatch con otros sistemas mediante llaves de API de solo lectura.',
    // Al final del menú: es la función más avanzada y la menos usada en el día a día.
    'display_order' => 12,
    'articles' => [
    [
        'title'   => 'Qué es la API pública y para qué sirve',
        'display_order' => 1,
        'is_published'  => true,
        'content' => <<<'HTML'
<h2>Conectar ISPWatch con otro sistema</h2>
<p>La <strong>API pública</strong> permite que otro programa —un CRM, un sistema de facturación electrónica, una plataforma de radio— <strong>lea</strong> los datos de tu empresa en ISPWatch de forma automática, sin que nadie tenga que exportar archivos a mano.</p>

<h3>Qué puede leer</h3>
<ul>
  <li><strong>Clientes</strong>: datos, plan, estado del servicio, router al que pertenecen.</li>
  <li><strong>Servicios</strong> contratados por cada cliente.</li>
  <li><strong>Cambios</strong>: un listado de todo lo que cambió (altas, cortes, reconexiones, cambios de plan), para que el otro sistema se mantenga al día sin volver a pedir todo.</li>
  <li><strong>Cartera</strong>: facturas y pagos.</li>
  <li><strong>Soporte</strong>: tickets e instalaciones.</li>
</ul>

<h3>Qué NO puede hacer</h3>
<p>Esto es lo más importante y conviene decirlo antes que nada:</p>
<ul>
  <li><strong>No escribe nada.</strong> Un sistema conectado por API no puede crear clientes, registrar pagos, cortar ni reconectar. Sólo lee.</li>
  <li><strong>No entrega contraseñas.</strong> Las claves PPPoE, las de hotspot y las direcciones MAC de los equipos nunca salen por la API, aunque estén en la misma ficha.</li>
  <li><strong>No cruza empresas.</strong> Cada llave está atada a tu empresa y sólo ve tus datos. No hay forma de pedirle los de otra.</li>
</ul>

<h3>Cómo se controla el acceso</h3>
<p>El acceso se da con una <strong>llave</strong> (una clave larga) que tú emites desde el panel. Cada llave lleva tres candados:</p>
<ul>
  <li><strong>Permisos</strong>: eliges qué áreas puede leer. Una llave para un CRM no tiene por qué ver la cartera.</li>
  <li><strong>Direcciones IP autorizadas</strong>: la llave sólo funciona desde el servidor del integrador. Si alguien se la roba, desde otro lado no sirve.</li>
  <li><strong>Fecha de vencimiento</strong>: la llave caduca sola y hay que renovarla.</li>
</ul>
HTML,
        'tips'    => 'Si un proveedor te pide "acceso a la base de datos" para integrarse, no hace falta: eso es exactamente lo que resuelve la API pública, y sin darle permiso de modificar nada.',
    ],
    [
        'title'   => 'Emitir una llave de API para un integrador',
        'display_order' => 2,
        'is_published'  => true,
        'content' => <<<'HTML'
<h2>Paso a paso</h2>
<p>Necesitas el permiso <strong>Gestionar mis llaves de API</strong>. Si no ves la opción, pídesela al administrador de tu empresa.</p>

<h3>1. Registra la integración</h3>
<p>Primero se da de alta <strong>quién</strong> se va a conectar (por ejemplo «Facturación electrónica Acme»). Una integración puede tener varias llaves: la del entorno de pruebas y la de producción, o una nueva mientras se rota la vieja.</p>

<h3>2. Pídele al integrador su IP pública</h3>
<p>Es el dato que más se equivoca. No es la IP de la oficina ni la del computador de quien programa: es la <strong>IP desde la que sale el servidor</strong> que va a llamar a ISPWatch.</p>
<p>Si hay dudas, que el integrador llame primero al chequeo de la API: la respuesta le dice exactamente con qué IP lo está viendo ISPWatch. Esa es la que va en la lista.</p>

<h3>3. Elige los permisos</h3>
<p>Sólo los que necesite. Si el integrador pide todo «por si acaso», la respuesta correcta es preguntarle qué pantalla de su sistema usa cada área.</p>

<h3>4. Guarda la llave</h3>
<p><strong>La llave se muestra una sola vez.</strong> Al cerrar esa ventana ya no se puede volver a ver: ISPWatch no la guarda en texto, sólo guarda una huella para poder verificarla. Si se pierde, no se recupera — se revoca y se emite otra.</p>
<p>Mándasela al integrador por un medio seguro. No por WhatsApp ni por correo sin cifrar.</p>

<h3>Vencimiento</h3>
<p>Cuando tú mismo emites la llave, el vencimiento es <strong>obligatorio</strong> y no puede pasar de 90 días. No es un capricho: una llave sin fecha es una llave que nadie revisa nunca. Antes de que venza hay que emitir la nueva y avisarle al integrador, porque el día que caduca su sistema deja de recibir datos.</p>

<h3>Revocar</h3>
<p>Revocar una llave la deja inservible al instante. El registro de la llave se conserva para que quede la constancia de quién tuvo acceso y hasta cuándo.</p>
HTML,
        'tips'    => 'Antes de emitir la llave, pregúntate: si esta clave se filtrara mañana, ¿qué podría ver quien la tenga? La respuesta la decides tú al elegir los permisos.',
    ],
    [
        'title'   => 'Qué ve cada permiso de la llave',
        'display_order' => 3,
        'is_published'  => true,
        'content' => <<<'HTML'
<h2>Los permisos, uno por uno</h2>
<table>
  <tr><td><strong>Clientes</strong></td><td>Datos del abonado, plan, estado del servicio, router y sector. Sin contraseñas de red.</td></tr>
  <tr><td><strong>Servicios</strong></td><td>Los servicios contratados y su configuración de red (IP, usuario PPPoE, router).</td></tr>
  <tr><td><strong>Cambios</strong></td><td>El listado de novedades: altas, cortes, reconexiones, cambios de plan y bajas.</td></tr>
  <tr><td><strong>Cartera</strong></td><td>Facturas y pagos. <strong>Es el dato más sensible de la plataforma.</strong></td></tr>
  <tr><td><strong>Soporte</strong></td><td>Tickets e instalaciones.</td></tr>
</table>

<h3>Por qué «Clientes» y «Servicios» están separados</h3>
<p>Porque son necesidades distintas. Un sistema de cobranza necesita saber quién es el cliente y qué debe, pero no tiene por qué ver la configuración de red de cada punto. Separarlos permite dar exactamente lo que hace falta.</p>

<h3>Cartera no se puede dar desde el auto-servicio</h3>
<p>El permiso de facturas y pagos <strong>no aparece</strong> en la pantalla donde tú emites tus propias llaves. Es deliberado: son tus datos y puedes tenerlos, pero esa conversación pasa por el equipo de ISPWatch para que quede claro qué se está entregando y a quién. Escríbenos y se emite por el otro camino.</p>
HTML,
    ],
    [
        'title'   => 'El integrador dice que le da error: qué revisar',
        'display_order' => 4,
        'is_published'  => true,
        'content' => <<<'HTML'
<h2>Los tres errores de siempre</h2>
<p>Casi todos los problemas de una integración nueva son uno de estos tres, y se distinguen en un minuto.</p>

<h3>«Me responde que la IP no está autorizada»</h3>
<p>La llave está bien; el problema es desde dónde llama. Pídele que consulte el chequeo de la API: la respuesta incluye la IP con la que ISPWatch lo está viendo. Casi siempre es distinta de la que él creía. Esa es la que hay que agregar a la lista de la llave.</p>
<p>Ojo: si su servidor sale por varias conexiones, la IP puede cambiar sola de un día para otro. En ese caso hay que autorizar todas.</p>

<h3>«Me responde que no tengo permiso»</h3>
<p>La llave es válida pero le falta el permiso de esa área. El chequeo de la API le dice qué permisos tiene la llave: si el área que está pidiendo no aparece ahí, hay que emitirle una llave nueva con ese permiso. Los permisos de una llave existente no se editan.</p>

<h3>«Me responde que la llave no vale»</h3>
<p>O está vencida, o fue revocada, o se copió mal (les pasa mucho: un espacio de más al pegarla). Revisa en el panel si la llave sigue activa y cuándo vence.</p>

<h3>«Funcionaba y de un día para otro dejó de funcionar»</h3>
<p>Casi siempre es el vencimiento. Es lo primero que hay que mirar.</p>

<h3>«Va lento o le rechaza peticiones»</h3>
<p>Cada llave tiene un tope de peticiones por minuto y por hora. Existe para que la integración no se coma la capacidad que tu personal necesita para cobrar y reconectar. Si el integrador choca contra el tope, normalmente está pidiendo toda la base cada pocos minutos en vez de pedir sólo los cambios.</p>
HTML,
        'tips'    => 'El chequeo de la API responde tres cosas de una sola vez: si la llave sirve, desde qué IP te ven y qué permisos tiene. Que el integrador empiece siempre por ahí antes de reportar nada.',
    ],
    [
        'title'   => 'Entregarle la documentación técnica al integrador',
        'display_order' => 5,
        'is_published'  => true,
        'content' => <<<'HTML'
<h2>El contrato de la API</h2>
<p>Cuando contratas a alguien para que conecte su sistema con ISPWatch, lo primero que te va a pedir es «la documentación de la API». Existe y se le entrega tal cual.</p>

<h3>Qué es lo que se le entrega</h3>
<p>Un archivo llamado <strong>OpenAPI</strong>. No es un programa ni una inteligencia artificial: es una <strong>ficha técnica</strong> en un formato estándar que describe la API entera —qué se puede pedir, con qué filtros, qué devuelve cada campo y qué significa cada error.</p>
<p>Su valor es que las herramientas del integrador lo leen solas: con ese archivo su sistema genera la conexión automáticamente, en vez de que alguien vaya adivinando campo por campo. Le ahorra días de trabajo y evita los errores de interpretación, que son los que aparecen semanas después.</p>

<h3>Cómo lo obtiene</h3>
<p>Una vez que tiene su llave, el archivo se descarga desde la propia API. Así siempre recibe la versión que está funcionando de verdad, y no una copia vieja que alguien le reenvió por correo.</p>

<h3>Lo que conviene acordar antes de empezar</h3>
<ul>
  <li><strong>Qué áreas necesita leer</strong>, para emitir la llave con esos permisos y no más.</li>
  <li><strong>Desde qué IP va a llamar</strong>, para autorizarla.</li>
  <li><strong>Cada cuánto va a sincronizar.</strong> Lo correcto es pedir sólo los cambios, no la base completa cada vez.</li>
  <li><strong>Quién avisa cuando la llave esté por vencer</strong>, para que la integración no se caiga sin previo aviso.</li>
</ul>
HTML,
        'tips'    => 'Si el integrador insiste en que necesita escribir en ISPWatch (crear clientes, marcar pagos, cortar), eso hoy no existe en la API pública y no es un olvido: es una decisión de diseño. Consúltanos antes de comprometer una fecha.',
    ],
    ],
];
