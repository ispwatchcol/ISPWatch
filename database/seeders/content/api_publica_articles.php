<?php

/**
 * Artículos del Centro de Ayuda sobre la API pública.
 *
 * FUENTE ÚNICA. Lo consumen dos caminos que no se pueden mezclar:
 *
 *  - `HelpCenterSeeder`, que en desarrollo BORRA y vuelve a sembrar todo el
 *    Centro de Ayuda;
 *  - la migración `2026_08_19_100000_seed_help_center_api_publica`, que es la
 *    que lleva este contenido a producción (los seeders nunca corren allí), y
 *    `2026_10_01_100000_update_help_center_integracion_aaa`, que lleva las
 *    correcciones posteriores sin pisar lo editado desde el panel.
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
  <tr><td><strong>Cambios</strong></td><td>El listado de novedades: altas (también las de carga masiva), cortes, reconexiones, cambios de plan, bajas, clientes eliminados, cambios de router, de IP o de usuario PPPoE, y la activación de RADIUS en un router.</td></tr>
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
    [
        'title'   => 'Probar la API: primeros comandos, Postman y curl',
        'display_order' => 6,
        'is_published'  => true,
        'content' => <<<'HTML'
<h2>Lo mínimo para hacer la primera llamada</h2>
<p>Necesitas tres cosas: la <strong>dirección base</strong>, la <strong>llave</strong> y una <strong>cabecera</strong> que casi todo el mundo olvida.</p>

<h3>1. La dirección base</h3>
<p>Es la misma dirección con la que entras al panel, seguida de la ruta de la API:</p>
<pre>https://TU-DIRECCION/api/v1/partner</pre>
<p>Si entras a ISPWatch por <code>https://ispwatch-crm.app</code>, la base es <code>https://ispwatch-crm.app/api/v1/partner</code>.</p>

<h3>2. La llave</h3>
<p>Va en una cabecera, no en la dirección. Se manda <strong>completa</strong>, incluida la parte antes de la barra vertical:</p>
<pre>Authorization: Bearer 42|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</pre>
<p>Nunca la pongas en la URL: quedaría escrita en el historial del navegador, en los registros de cualquier equipo intermedio y en cualquier captura de pantalla.</p>

<h3>3. La cabecera que evita una hora perdida</h3>
<pre>Accept: application/json</pre>
<p><strong>No es opcional en la práctica.</strong> Sin ella, una llamada con la llave mal copiada no responde "no autorizado": responde con una redirección a la pantalla de inicio de sesión. Tu programa la sigue, recibe el HTML del panel, y el error que ves no menciona la llave por ningún lado. Con la cabecera, el error dice exactamente qué pasó.</p>

<h2>La primera llamada</h2>
<p>Empieza <strong>siempre</strong> por <code>/ping</code>. Es el único punto que no exige permisos y responde tres cosas de una sola vez: si la llave sirve, qué permisos tiene y <strong>desde qué dirección IP te ve el servidor</strong>.</p>
<pre>curl -H "Authorization: Bearer TU-LLAVE" \
     -H "Accept: application/json" \
     https://TU-DIRECCION/api/v1/partner/ping</pre>
<p>Al emitir una llave nueva, el panel te muestra este mismo comando ya armado con tu llave: sólo hay que copiarlo y pegarlo.</p>

<h2>Desde Postman (o Insomnia)</h2>
<p>No armes la colección a mano. La API publica su propio <strong>contrato</strong> y esos programas lo leen solos:</p>
<ol>
  <li>Descarga el contrato con tu llave, desde <code>https://TU-DIRECCION/api/v1/partner/openapi.yaml</code></li>
  <li>En Postman: <strong>File → Import</strong> y suelta ese archivo.</li>
  <li>Te crea la colección entera con todas las consultas, sus filtros y sus respuestas de ejemplo.</li>
  <li>En la colección → <strong>Authorization</strong> → tipo <em>Bearer Token</em> → pega la llave. La heredan todas las peticiones.</li>
  <li>En la colección → <strong>Headers</strong> → agrega <code>Accept: application/json</code>.</li>
</ol>

<h2>Orden recomendado de prueba</h2>
<ol>
  <li><code>GET /ping</code> — confirma llave, permisos e IP.</li>
  <li><code>GET /customers?after_id=0&amp;per_page=5</code> — primera página real. Para seguir, se manda <code>after_id</code> con el valor <code>next_after_id</code> de la respuesta, mientras <code>has_more</code> sea <code>true</code>.</li>
  <li><code>GET /customers/{id}</code> — el detalle de uno de los anteriores.</li>
  <li><code>GET /services?customer_id={id}</code> — sus servicios.</li>
  <li><code>GET /events?since=0&amp;limit=50</code> — el listado de cambios. Guarda el valor <code>next_since</code> que viene en la respuesta.</li>
  <li><code>GET /events?since=NEXT_SINCE</code> — debe volver vacío y devolverte el mismo número.</li>
</ol>
<p>Ese último paso es el que de verdad vale: así se comprueba que el integrador puede pedir sólo lo que cambió, en vez de descargarse toda tu base de clientes cada vez.</p>

<h2>Si algo responde con error</h2>
<table>
  <tr><td><strong>IP no autorizada</strong></td><td>Estás llamando desde una dirección que no está en la lista de la llave. <strong>La lista de una llave ya emitida no se puede cambiar</strong>: hay que revocarla y emitir otra. Para saber qué IP llegó de verdad, entra a <em>Ver peticiones</em> en la integración: la bitácora muestra la dirección exacta y el motivo del rechazo.</td></tr>
  <tr><td><strong>Sin permiso</strong></td><td>La llave es válida pero le falta el permiso de esa área. Los permisos tampoco se editan: se emite una llave nueva.</td></tr>
  <tr><td><strong>Llave no válida</strong></td><td>Vencida, revocada, o copiada con un espacio de más. Revisa en el panel si sigue activa.</td></tr>
  <tr><td><strong>Sólo se admite GET</strong></td><td>Se intentó crear o modificar algo. Esta API únicamente lee, y no hay permiso que lo cambie.</td></tr>
  <tr><td><strong>Demasiadas peticiones</strong></td><td>Se superaron las 60 por minuto o las 5.000 por hora de esa llave. Suele significar que el programa pide toda la base cada pocos minutos en vez de pedir sólo los cambios.</td></tr>
</table>

<h2>Cuidado al probar con herramientas gráficas</h2>
<p>La función de "ejecutar toda la colección" de Postman lanza decenas de peticiones seguidas y choca contra el límite por minuto enseguida. Para probar, lanza las consultas de una en una.</p>
HTML,
        'tips'    => 'Si la llave la vas a pegar en un chat, un correo o un ticket para pasársela a alguien, dala por comprometida: revócala y emite otra. Se muestra una sola vez justamente para que no ande circulando.',
    ],
    [
        'title'   => 'Guía técnica para integradores AAA: sincronizar sin perder cambios',
        'display_order' => 7,
        'is_published'  => true,
        'content' => <<<'HTML'
<h2>Para quién es esta guía</h2>
<p>Para el equipo técnico que conecta su <strong>servidor de autenticación</strong> (RADIUS, AAA) a ISPWatch y decide, con estos datos, a quién deja navegar. Si tu sistema trabaja en modo <em>fail-closed</em> —si no puede demostrar que tiene el estado completo y vigente, no aplica la decisión—, aquí está exactamente lo que ISPWatch garantiza y lo que no. El contrato formal (OpenAPI 1.1.0) dice lo mismo campo por campo y se descarga en <code>/api/v1/partner/openapi.yaml</code>.</p>

<h2>Reparto de responsabilidades</h2>
<ul>
  <li><strong>ISPWatch decide</strong>: factura, calcula la mora y fija el estado comercial de cada cliente.</li>
  <li><strong>Tu servidor ejecuta</strong>: autentica, corta y reconecta en la red.</li>
  <li>La API es de <strong>sólo lectura</strong>. ISPWatch da por cumplida su parte al cambiar el estado y publicar el evento; hoy no existe un canal para que tu sistema confirme que aplicó el corte.</li>
</ul>

<h2>Con qué se decide el acceso</h2>
<p>El campo que manda es <code>service_status</code> (está en <code>/services/{id}</code> y en <code>/customers/{id}</code>):</p>
<table>
  <tr><td><code>activo</code>, <code>gratis</code></td><td>Hay servicio: permitir.</td></tr>
  <tr><td><code>suspendido</code></td><td>Corte por mora o manual: denegar.</td></tr>
  <tr><td><code>cancelado</code>, <code>retirado</code></td><td>Baja definitiva: denegar. Un pago posterior no la revierte.</td></tr>
</table>
<p>Una integración fail-closed debe exigir además <code>is_enabled = true</code> (en <code>/customers</code>). Los dos se mueven juntos —la auditoría de producción del 2026-10-01 encontró cero fichas desalineadas—, pero exigir ambos no cuesta nada. Ante cualquier valor que no reconozcas, deniega.</p>
<p><strong>No sirven para decidir acceso:</strong> <code>status</code> de <code>/services</code> (es el contrato de servicio, no el estado comercial), <code>excluded_from_billing</code> y <code>plan.is_courtesy</code> (afectan el cobro, no la conexión).</p>

<h2>Qué avisa el listado de cambios</h2>
<table>
  <tr><td><code>SERVICE_CREATED</code></td><td>Alta de un servicio, desde el panel o por carga masiva.</td></tr>
  <tr><td><code>SERVICE_ACTIVATED</code> / <code>SERVICE_REACTIVATED</code></td><td>Pasa a <code>activo</code>/<code>gratis</code> (la segunda, saliendo de <code>suspendido</code>).</td></tr>
  <tr><td><code>SERVICE_SUSPENDED</code></td><td>Pasa a <code>suspendido</code>.</td></tr>
  <tr><td><code>SERVICE_CANCELLED</code></td><td>Pasa a <code>retirado</code> o <code>cancelado</code>.</td></tr>
  <tr><td><code>PLAN_CHANGED</code></td><td>Cambio de plan (velocidades). Puede llegar dos veces.</td></tr>
  <tr><td><code>CUSTOMER_UPDATED</code></td><td>Datos de identidad, o <code>is_enabled</code> cuando cambia solo.</td></tr>
  <tr><td><code>ROUTER_CHANGED</code></td><td>El cliente cambió de router (trae el anterior y el nuevo), o el ISP activó o desactivó RADIUS en su router (mismo router en los dos).</td></tr>
  <tr><td><code>NETWORK_CHANGED</code></td><td>Cambió la IP o el usuario PPPoE. Trae qué campos, no los valores.</td></tr>
  <tr><td><code>CUSTOMER_DELETED</code></td><td>El cliente se eliminó del todo: ya no existe en la API (responde 404). El evento trae sus <code>service_ids</code>, su router y si ese router era AAA, para que puedas revocar sin consultar nada.</td></tr>
</table>
<p>El evento es <strong>delgado</strong>: dice qué cambió, no trae el estado. Tómalo como disparador, vuelve a consultar el recurso y decide con lo que diga <strong>ahora</strong>, aunque sea más nuevo que el evento. Si en el futuro aparecen tipos nuevos, los existentes no cambian de nombre: ignora los que no conozcas.</p>

<h2>Las garantías del listado de cambios</h2>
<ul>
  <li><strong>En orden y sin huecos para quien lo sigue bien.</strong> Pide <code>/events?since=N</code> y manda en la siguiente llamada el <code>next_since</code> que te devolvió. El número de cada evento se asigna cuando el cambio ya está guardado, en serie: nunca aparece un evento con número menor que otro que ya recibiste.</li>
  <li><strong>Al menos una vez.</strong> Un mismo cambio puede llegar dos veces. Deduplica por <code>event_id</code> y procesa de forma idempotente (volver a consultar y aplicar el estado actual).</li>
  <li><strong>La numeración tiene saltos.</strong> Es compartida entre empresas. Un salto no es un evento perdido.</li>
  <li><strong>Retención.</strong> Hoy los eventos no se borran. Si algún día se introduce un límite, se avisará antes y un cursor más viejo que lo conservado recibirá un error explícito, nunca un lote incompleto en silencio.</li>
</ul>

<h2>Procedimiento recomendado</h2>
<ol>
  <li>Recorre <code>/events</code> hasta que <code>has_more</code> sea <code>false</code> y guarda el <code>next_since</code>.</li>
  <li>Barre <code>/customers</code> y <code>/services</code> completos con <strong><code>after_id</code></strong>, no con <code>page</code>. Para un solo NAS, ambos aceptan <code>router_id</code>.</li>
  <li>Vuelve a pedir <code>/events</code> desde el cursor del paso 1: lo que cambió mientras barrías llega ahí.</li>
  <li>Sigue <code>/events</code> en ciclo.</li>
  <li>Repite el barrido completo de vez en cuando como reconciliación.</li>
</ol>
<p>Si pierdes el cursor, vuelve al paso 1.</p>

<h3>Por qué <code>after_id</code> y no <code>page</code></h3>
<p>Los listados no son una foto congelada: cada página es una consulta aparte. Con <code>page</code>, si se elimina un cliente anterior a la página en la que vas, todo se corre un lugar y se salta un cliente que no cambió, sin ningún error. Con <code>after_id</code> eso no pasa. Se recorre así: <code>after_id=0</code>, y luego el <code>next_after_id</code> de cada respuesta mientras <code>has_more</code> sea <code>true</code>. No se puede mezclar con <code>page</code>.</p>

<h3>Lo que no hay que usar para decidir acceso</h3>
<p><code>updated_since</code> sirve para listados baratos, no para esto: en <code>/customers</code> mira la fecha del usuario y en <code>/services</code> la del contrato, y ninguna de las dos cambia cuando se corta o se reconecta a alguien. Para eso está <code>/events</code>.</p>

<h2>Cambios de router</h2>
<p>Cuando el ISP mueve un cliente de router, el campo <code>router_id</code> y el indicador <code>managed_by_external_aaa</code> cambian juntos en la misma respuesta (el indicador sale del router asignado, no se guarda por cliente). Te llega un <code>ROUTER_CHANGED</code> con el router anterior: es el que necesitas para revocar en ese NAS. Si el router anterior lo gestionaba ISPWatch, ISPWatch retira además la configuración del cliente de ese equipo.</p>

<h2>IP y usuario PPPoE</h2>
<ul>
  <li><strong>IP</strong>: única por router (la misma puede repetirse en otro router). La base de datos lo impide. En routers RADIUS ISPWatch no la usa para nada técnico.</li>
  <li><strong>Usuario PPPoE</strong>: único por router, no global. También lo impide la base de datos.</li>
  <li>La contraseña PPPoE <strong>no sale nunca</strong> por la API. Tu servidor es la autoridad técnica sobre los atributos de red; ISPWatch los conserva como dato administrativo.</li>
</ul>

<h2>Límites</h2>
<p>60 peticiones por minuto y 5.000 por hora por llave. Hasta 100 filas por página en los listados y 500 eventos por llamada. Al pasarse, la respuesta es <code>429</code> con <code>Retry-After</code>. Un barrido completo cuesta más o menos una petición por cada 100 clientes más una por cada 100 servicios: espácialas.</p>
HTML,
        'tips'    => 'Antes de pasar clientes reales, prueba el ciclo completo con un solo cliente en un router aparte: alta, factura, mora, corte, pago, reconexión y cambio de router. Cada paso tiene que llegarte por el listado de cambios.',
    ],
    ],
];
