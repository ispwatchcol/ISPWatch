# MANUAL DE USUARIO — ISPWatch

> Guía paso a paso para el uso diario del sistema. Escrita en lenguaje sencillo,
> sin conocimientos técnicos previos.
> Si eres desarrollador, busca [`MANUAL_DESARROLLADOR.md`](MANUAL_DESARROLLADOR.md).
>
> **Este documento es la fuente de verdad del manual.** Lo que el usuario lee dentro de la
> aplicación (**Manual → Centro de Ayuda**) es su espejo, y sale de
> `database/seeders/HelpCenterSeeder.php`. **Si corriges algo aquí, corrígelo también allí**:
> los dos ya se separaron una vez y el de la app acumuló información falsa durante meses.

**Última actualización:** 2026-08-03

---

## Índice

1. [Qué es ISPWatch](#1-qué-es-ispwatch)
2. [Iniciar sesión](#2-iniciar-sesión)
3. [Cómo moverse por el sistema](#3-cómo-moverse-por-el-sistema)
4. [El Panel (Dashboard)](#4-el-panel-dashboard)
5. [Clientes](#5-clientes)
6. [Prospectos e instalaciones](#6-prospectos-e-instalaciones)
7. [Facturación](#7-facturación)
8. [Pagos y recaudos](#8-pagos-y-recaudos)
9. [Corte y reconexión de morosos](#9-corte-y-reconexión-de-morosos)
10. [Gastos](#10-gastos)
11. [Routers y red](#11-routers-y-red)
12. [Sectoriales y fibra óptica](#12-sectoriales-y-fibra-óptica)
13. [Planes de internet](#13-planes-de-internet)
14. [Soporte técnico](#14-soporte-técnico)
15. [Inventario](#15-inventario)
16. [Personal y roles](#16-personal-y-roles)
17. [Configuración de la empresa](#17-configuración-de-la-empresa)
18. [Acciones masivas](#18-acciones-masivas)
19. [Preguntas frecuentes](#19-preguntas-frecuentes)

---

## 1. Qué es ISPWatch

ISPWatch es el sistema donde tu empresa de internet lleva **todo**: los clientes, lo que
cada uno paga, los equipos de la red y el soporte técnico.

Lo que hace diferente a ISPWatch es que **está conectado con los routers de verdad**.
Cuando das de alta a un cliente, el sistema lo configura solo en el equipo. Cuando un
cliente se atrasa en el pago, el sistema le corta el internet solo. Y cuando paga, se lo
devuelve solo. No hay que entrar a ningún equipo a mano.

---

## 2. Iniciar sesión

### Paso a paso

1. Abre el navegador y entra a la dirección de tu empresa (por ejemplo,
   `https://ispwatch-crm.app`).
2. Verás la pantalla de acceso.
3. En **Usuario**, escribe tu **correo de acceso**. Ojo: **no es tu correo personal**.
   Es un correo especial que crea el sistema, con la forma
   `nombre.apellido@nombre-de-tu-empresa`.
   Por ejemplo: `maria.gomez@mi-isp`.
4. Escribe tu contraseña.
5. Si quieres que el sistema te recuerde en este computador, marca **Recordarme**.
6. Pulsa **Ingresar**.

### Si algo sale mal

| Mensaje | Qué significa | Qué hacer |
|---|---|---|
| *"Credenciales incorrectas"* | El usuario o la contraseña no coinciden | Revisa que uses el correo **de acceso**, no el personal |
| *"Por favor verifica tu correo electrónico..."* | Tu cuenta existe pero nunca confirmaste el correo | Busca el correo de confirmación en tu bandeja (revisa también correo no deseado). Si no llegó, usa **Reenviar verificación** |
| *"Demasiados intentos. Espera N segundos"* | Fallaste la contraseña 5 veces en un minuto | Espera el tiempo que indica e inténtalo de nuevo |
| *"Entrada no válida detectada"* | Escribiste caracteres que el sistema bloquea por seguridad (comillas, punto y coma) | Escribe sólo el correo, sin símbolos raros |

### Si te expulsa solo del sistema

Si estabas trabajando y de repente vuelves a la pantalla de acceso, tu sesión caducó.
Vuelve a entrar; no se pierde nada de lo que ya habías guardado.

---

## 3. Cómo moverse por el sistema

A la izquierda está el **menú lateral**. Los grupos que ves dependen de tu rol:
**si no ves una sección, es porque tu usuario no tiene permiso para ella.**

| Grupo | Contiene | Se muestra si tienes |
|---|---|---|
| **Dashboard** | Resumen general | Permiso de estadísticas |
| **Usuarios** | Lista de clientes, agregar cliente, estadísticas, mapa | Ver clientes |
| **Soporte** | Tickets, nuevo ticket, instalaciones, estadísticas | Ver soporte |
| **Gestión** | Routers, planes de internet, sectoriales, topología FTTH | Cualquiera de los tres |
| **Inventarios** | Equipos, stock/modelos, proveedores, sucursales | Ver inventario |
| **Finanzas** | Resumen, facturación, pagos, formas de pago, tipos de factura, servicios adicionales, gastos, categorías | Ver facturación **o** ver gastos |
| **Personal** | Empleados y técnicos | Ver personal |
| **Acciones masivas** | Cargas por Excel y paneles de reintentos | Ejecutar acciones masivas |
| **Configuración** | Datos de la empresa, plantillas, ajustes | Ver ajustes |
| **Manual** | Centro de ayuda | Todos |

> **Importante:** si necesitas ver una sección y no aparece, no es un error del sistema.
> Pídele a un administrador que revise tu rol en **Personal → Roles**.

---

## 4. El Panel (Dashboard)

Es la primera pantalla al entrar. Muestra de un vistazo:

| Tarjeta | Qué cuenta exactamente |
|---|---|
| **Clientes** | **Totales**: clientes habilitados en el sistema. **Activos**: los que además tienen el servicio prendido. **Suspendidos**: la resta de los dos |
| **Ingresos del mes** | El **dinero que entró este mes**: la suma de los pagos registrados con fecha de este mes. No es lo facturado |
| **Saldo pendiente** | Lo que el conjunto de clientes debe: suma de los saldos de las facturas emitidas y vencidas, de **todos** los meses, no sólo del actual |
| **Tasa de recaudo** | Qué porcentaje de lo facturado este mes ya se cobró. Si es 0 % puede ser que aún no se haya emitido nada este mes |
| **Tickets** | Abiertos (incluye los que están en progreso) y urgentes |
| **Infraestructura** | Sectoriales y routers registrados |
| **Actividad reciente** | Últimos movimientos del sistema |

> ⚠️ **La alerta más importante del panel son los routers con falla general.** Si un equipo
> está marcado en falla masiva aparece resaltado con su nombre e IP. Ver [11.6](#116-falla-masiva).

Dos cosas que suelen confundir:

- **"Ingresos del mes" cuenta pagos, no facturas.** Un cliente que paga en agosto una factura
  de julio suma a agosto. Si quieres ver lo *facturado*, ve a **Finanzas → Facturación**.
- **"Saldo pendiente" arrastra deuda vieja.** No mide el mes: mide todo lo que está sin pagar.
  Por eso puede subir aunque este mes se haya cobrado bien.

---

## 5. Clientes

### 5.1 Ver la lista de clientes

**Usuarios → Lista de usuarios.**

Verás la tabla con todos tus clientes. Puedes:

- **Buscar** por nombre, cédula, IP o correo usando la barra de búsqueda.
- **Ordenar** haciendo clic en el encabezado de una columna.
- **Pasar de página** con los controles de abajo.

> **La búsqueda ya no distingue mayúsculas.** Buscar `eliud` encuentra a *Eliud*. Antes no era
> así y podía parecer que un cliente no existía; se corrigió en julio de 2026 en todas las
> pantallas (clientes, facturación, prospectos y tickets).

### 5.2 Crear un cliente

**Usuarios → Agregar usuario.**

El formulario está dividido en bloques. Los campos con asterisco son obligatorios.

**Bloque 1 — Datos personales**

| Campo | Qué poner |
|---|---|
| Nombre * | Nombre del cliente. Si es una empresa, marca **Es empresa** y el apellido queda opcional |
| Apellido | Apellido |
| Cédula * | Documento de identidad |
| Teléfono | Celular de contacto |
| Correo personal * | Correo real del cliente. Debe ser único |
| Correo de acceso | El que usará para entrar. **Si lo dejas vacío, el sistema lo crea solo** |
| Contraseña * | Mínimo 6 caracteres |

> **Sobre las tildes y la ñ:** el nombre se guarda tal cual lo escribes (José Muñoz).
> El correo de acceso se convierte automáticamente a letras sin tilde
> (`jose.munoz@...`), porque los equipos de red no aceptan esos caracteres.

**Bloque 2 — Ubicación**

Dirección, ciudad, departamento, estrato (1 a 6) y precinto del equipo.
También puedes marcar la ubicación en el mapa para que aparezca en **Mapa de usuarios**.

**Bloque 3 — Servicio**

| Campo | Qué poner |
|---|---|
| Plan de internet | El plan que contrató |
| Router | El equipo al que se conecta |
| Sectorial | La antena o elemento que lo atiende |
| IP | La dirección IP que se le asigna |
| Fecha de instalación | **Muy importante**: de aquí sale el cobro proporcional |
| Es fibra | Marca si el cliente es FTTH. Si eliges OLT y puerto NAP, se detecta solo |

> **Regla de la IP:** dos clientes del **mismo router** no pueden tener la misma IP.
> La misma IP sí puede repetirse en **otro** router. Si te da error, revisa que no la
> tenga ya otro cliente de ese equipo.

**Bloque 4 — Primera factura**

Aquí decides qué se le cobra al cliente que entra a mitad de mes:

| Opción | Qué hace |
|---|---|
| **No facturar** | No se le cobra el mes en curso. Su primera factura sale el mes siguiente |
| **Prorrateado** | Se le cobran sólo los días que faltan del mes |
| **Mes completo** | Se le cobra el mes entero, como a un cliente antiguo |

Y además: **meses de cortesía**, que son meses **posteriores** al de instalación que salen
en cero. Por ejemplo: instalado el 16 de julio con *Prorrateado + 1 mes de cortesía*
⇒ paga del 16 al 31 de julio, **agosto sale gratis**, y septiembre vuelve a la tarifa normal.

Si dejas estas casillas vacías, el cliente **hereda** lo que tenga configurado su plan, y si
el plan tampoco lo define, lo del router.

> **El sistema te muestra el cálculo antes de guardar.** Al llenar la fecha de instalación
> y el plan, aparece una vista previa con el monto exacto. **No cobra nada todavía**, sólo
> te enseña el resultado.

**Bloque 5 — Credenciales del equipo**

Según cómo esté configurado el router, te pedirá:
usuario y contraseña **PPPoE**, o usuario y contraseña **HotSpot**, o la **dirección MAC**.
Si no aplica, el bloque no aparece.

**Bloque 6 — Opciones**

- **No facturar a este cliente**: lo saca de **todo** el ciclo automático. No recibe factura,
  ni recordatorio, ni notificación, ni corte. Úsalo para casos especiales (cortesías
  institucionales, pruebas).
- **No enviar notificaciones de factura**: a diferencia de la anterior, **no** afecta la
  facturación — la factura se sigue generando cada mes y la mora/corte funcionan igual.
  Sólo apaga el aviso de correo/WhatsApp de factura nueva y los recordatorios de pago.
  Úsalo para clientes que piden explícitamente no recibir esos mensajes.

**Guardar**

Tienes dos botones:

| Botón | Qué hace |
|---|---|
| **Guardar** | Registra al cliente **sólo en el sistema**. No toca el router |
| **Guardar y cargar a la RB** | Registra al cliente **y lo configura en el equipo de red** |

**Cómo funciona "Guardar y cargar a la RB".** El cliente se guarda **de inmediato** y la carga
al equipo se hace **en segundo plano**. No tienes que esperar con la pantalla abierta ni te va
a salir un "tiempo de espera agotado": son dos cosas separadas. La parte del router tarda
alrededor de medio minuto por cliente.

> ⚠️ **El router tiene que tener activada el alta automática** (*Agregar cliente a MikroTik*,
> en su ficha). **Viene apagada de fábrica.** Si está apagada, el cliente se guarda
> perfectamente pero **nunca se carga al equipo**, y el aviso de esto sólo queda en la bitácora
> del sistema — en pantalla no se ve nada raro. Es la causa más común de "creé el cliente y no
> tiene internet".

Si la carga en segundo plano falla (o el router la tenía apagada), entra a la ficha del cliente
y usa el botón de **aprovisionar**. Ese botón exige que el cliente tenga **router**, **plan** e
**IP** asignados; si le falta alguno te lo dice y no hace nada.

### 5.3 Editar un cliente

En la lista, pulsa el icono de **editar**. Verás el mismo formulario con los datos actuales,
más unas pestañas adicionales:

| Pestaña | Contenido |
|---|---|
| **Facturación** | Facturas del cliente, saldo, saldo a favor y sus **servicios adicionales** (ver 5.3.1) |
| **Documentos** | Cédula, contrato y otros archivos |
| **Instalaciones** | Historial de instalaciones |
| **Tickets** | Tickets de soporte del cliente |

#### 5.3.1 Servicios adicionales del cliente

Al final de la pestaña **Facturación** está lo que el cliente paga **además de su plan**:
el alquiler de un router extra, un punto de TV, soporte premium… Arriba del listado ves
**cuánto se le suma cada mes** a su factura.

Los servicios salen del catálogo de **Finanzas → Servicios adicionales** ([7.5](#75-servicios-adicionales)).
Para asignar uno pulsa **Asignar servicio** y elige:

| Campo | Qué hace |
|---|---|
| **Precio para este cliente** | Déjalo **vacío** para usar el del catálogo — así, si algún día subes el precio de lista, a este cliente también le sube. Ponle un valor para **congelárselo** (aparece la etiqueta *Precio propio*) |
| **Cantidad** | Para cobrar el mismo servicio más de una vez, por ejemplo dos routers extra |
| **Desde** | A partir de qué fecha se cobra. Si es a mitad de mes, lo que pase depende del *cobro del primer mes* configurado en el catálogo |
| **Hasta** | Opcional: baja programada |

> **Dar de baja vs. eliminar.** *Dar de baja* deja de cobrarlo desde la próxima factura y
> conserva todo el historial — es lo que quieres casi siempre. *Eliminar* sólo funciona si
> el servicio **nunca llegó a cobrarse** (un alta por error); si ya salió en una factura, el
> sistema no deja borrarlo.

Un cliente no puede tener el mismo servicio activo dos veces: si lo intentas, el sistema te
pide subir la **cantidad** en la asignación que ya existe. Así el cobro doble se ve en la
factura en vez de esconderse en dos líneas iguales.

##### Cómo aparece en la factura

El servicio adicional **no genera una factura aparte**: sale como una línea más dentro de
la mensualidad del cliente, junto al plan. Si el cliente paga $50.000 de plan y alquila un
router de $20.000, recibe **una sola factura de $70.000** con las dos líneas.

Se cobra en la factura del mes según el ciclo de su router — el mismo día en que se le
factura todo lo demás.

| Situación | Qué pasa con el adicional |
|---|---|
| El cliente está en un **mes de cortesía** por instalación | Se cobra o no según la casilla *Cobrar en meses de cortesía* del catálogo. Si se cobra, la factura deja de ser de $0 y el cliente **sí** recibe el aviso de pago, por el monto del adicional |
| El servicio se **desactiva en el catálogo** | Los clientes que ya lo tienen **lo siguen pagando**. Desactivar sólo lo quita de la lista al asignar. Para dejar de cobrárselo a alguien, dale de baja *su* asignación |
| Se **da de baja** la asignación | Deja de cobrarse desde la siguiente factura. Las facturas anteriores no cambian |
| Se **borra la factura** del mes | Al regenerarla, el adicional se vuelve a cobrar. No se pierde |
| La generación mensual corre **dos veces** | El adicional se cobra **una sola vez**. No hay riesgo de duplicar |

##### ¿Y si el cliente no paga plan?

Hay clientes que no reciben factura mensual porque no tienen un plan que cobrarles: un
**empleado con plan de cortesía**, o alguien vigente a quien todavía no se le asignó plan. Si
ese cliente tiene servicios adicionales, el sistema le emite **una factura sólo con ellos**,
con el mismo vencimiento que el resto de facturas de su router.

Tiene sentido: no paga internet, pero sí está alquilando el equipo.

> **No confundir con "no facturar".** Si el cliente está marcado como *excluido de
> facturación*, retirado, o **llegó al tope de facturas pendientes**, no se le emite nada —
> tampoco de adicionales. En esos casos alguien ya decidió que a ese cliente no se le cobra, y
> el sistema lo respeta. El tope es el más importante: existe para dejar de acumularle deuda a
> quien ya no está pagando.

##### El aviso de "sin cobrar este mes"

Justo por lo anterior, un servicio puede quedarse **activo pero sin facturarse** durante meses
sin que nadie lo note. Para evitarlo:

- En **Finanzas → Servicios adicionales → Catálogo**, un aviso ámbar arriba te dice cuántos
  servicios activos no se cobraron este mes, por cuánto dinero y a qué clientes.
- En la ficha del cliente, la asignación afectada lleva la etiqueta **Sin cobrar este mes**.

El aviso sólo aparece cuando el cliente **ya recibió su factura del mes** y el servicio no está
en ella. Durante los primeros días, antes de que el router facture, no dice nada — no habría
nada que reportar.

Si aparece, revisa el estado del cliente: casi siempre está excluido de facturación, retirado,
o llegó al tope de facturas pendientes.
**Firmar el contrato.** En la pestaña **Documentos**, abajo, está el contrato de servicio:
se arma solo con los datos del cliente, el cliente firma en pantalla y al guardar se genera
el PDF firmado. Antes de firmar verás **con qué número quedará** (por ejemplo `CTR-00042`);
ese consecutivo va impreso dentro del documento y no se repite nunca. El prefijo lo
configuras en **Configuración → Plantillas** (ver [17.4](#174-plantillas-de-documentos)).

**Que lo firme el cliente desde su celular.** En esa misma zona, arriba del recuadro de
firma, tienes tres botones para mandarle un **enlace personal** y que firme por su cuenta,
sin que nadie tenga que desplazarse:

| Botón | Qué hace |
|---|---|
| **Enviar por correo** | Le llega el enlace al correo de contacto del cliente. El sistema lo envía solo |
| **Enviar por WhatsApp** | Abre WhatsApp con el mensaje ya escrito, listo para enviar desde tu teléfono |
| **Solo copiar enlace** | Genera el enlace y lo copia; lo mandas por donde quieras |

El cliente abre el enlace, le pedimos los **últimos 4 dígitos de su cédula**, lee el
contrato completo en la pantalla, firma con el dedo, marca que acepta y listo: el PDF queda
guardado en su ficha igual que si lo hubiera firmado en la oficina.

Cosas que conviene saber:

- **El enlace vence a las 72 horas y sirve una sola vez.**
- **No se puede reenviar el mismo enlace.** Si el cliente lo perdió, genera uno nuevo — eso
  anula el anterior automáticamente. Por eso, cuando lo generes, **cópialo si lo vas a
  mandar por otro medio**: después no se puede volver a consultar.
- **Puedes ver si ya lo abrió.** Debajo de los botones queda el historial de enlaces
  enviados con su estado (Pendiente, Firmado, Vencido, Anulado) y la marca de *abierto*.
  Sirve para saber si insistir sin tener que llamar.
- **Puedes anularlo** con el botón *Anular*, si te equivocaste de número o el cliente
  cambió de teléfono.
- **A las 24 horas el sistema le manda un recordatorio** por correo, una sola vez, si el
  enlace se envió por correo y sigue sin usarse.
- **El contrato firmado a distancia lleva una constancia** al pie con la fecha, la hora y la
  dirección IP desde la que se firmó. El firmado en la oficina no la lleva: ahí estabas tú.
- Si el cliente **no tiene cédula registrada**, no se le piden los 4 dígitos (si no,
  quedaría sin forma de entrar). Vale la pena registrarla antes de mandar el enlace.

**Un solo contrato firmado por cliente.** Si el cliente ya tiene contrato, la zona de firma
se reemplaza por un aviso: para generar uno nuevo hay que **eliminar primero el anterior**
en *Documentos del cliente*. Es a propósito — así no se acumulan dos contratos casi iguales
sin saber cuál es el que vale. Lo mismo aplica a la hoja de instalación de cada orden.

Los documentos se ven pulsando sobre ellos: se abren en una pestaña nueva. Si alguno no
abre, avísale al soporte técnico — puede ser un archivo de antes de la migración al
almacenamiento actual.

### 5.4 Estados del cliente: suspender, retirar, cancelar

Desde la ficha del cliente, con los botones **Suspender** y **Activar**.
Esto actúa **de verdad sobre el router**: al suspender, el cliente deja de navegar.

> Necesitas el permiso *Activar y Desactivar Clientes*.

**Los estados no son lo mismo, y la diferencia se paga en la facturación.** Esto es lo que
más se confunde:

| Estado | Navega | ¿Se le sigue facturando? |
|---|---|---|
| **Activo** | Sí | Sí |
| **Gratis** | Sí | No — es un plan de cortesía |
| **Suspendido** | No | **Sí.** Es un corte temporal: puede volver pagando, así que esos meses se cobran |
| **Retirado** | No | **No.** Es una baja definitiva |
| **Cancelado** | No | **No.** Es una baja definitiva |

> ⚠️ **Suspender no es dar de baja.** Al suspendido se le siguen emitiendo facturas mes a mes,
> a propósito: si se reconecta, esos meses existen. Lo único que frena la acumulación es el
> tope de *"Dejar de facturar al moroso"* del router (ver [7.1](#71-cómo-funciona-esto-es-lo-más-importante-del-sistema)).
>
> **Al cliente que se fue de verdad hay que ponerlo en Retirado o Cancelado**, no dejarlo
> suspendido. Si lo dejas suspendido, seguirá generando deuda que nadie va a pagar y ensuciará
> tus reportes de cartera.

El corte automático por mora deja al cliente en **Suspendido**, igual que si lo suspendieras a
mano. La diferencia es que del corte por mora el sistema **lo reconecta solo cuando paga**;
del manual, no (ver [8.2](#82-qué-pasa-automáticamente-al-guardar)).

### 5.5 Eliminar un cliente

En la lista, icono de **eliminar**. El sistema pedirá confirmación escribiendo `ELIMINAR`.

**Se borra todo, sin dejar rastro** (desde el 2026-08-06):

- Su perfil, facturas, pagos, servicios, tickets e instalaciones.
- Sus **archivos**: contrato firmado, actas y fotos de instalación, documentos subidos. Se
  borran del almacenamiento, no sólo de la pantalla.
- Su **configuración en el router**: usuario PPPoE (cortándole la sesión activa en el momento),
  cola de velocidad, usuario de HotSpot, reserva DHCP, listas de acceso y amarre de IP/MAC.

> ⚠️ **Si el router no responde, el cliente se borra igual pero te avisa.** Verás un mensaje
> naranja diciendo que no se pudo limpiar el equipo. En ese caso **la configuración sigue en el
> router y el cliente sigue navegando**: hay que quitarla a mano. No se hace de otra forma
> porque, si un router caído bloqueara el borrado, tendrías clientes imposibles de eliminar.

> **Piénsalo dos veces.** Si el cliente sólo se retiró, es mejor desactivarlo que borrarlo:
> así conservas su historial de pagos y el sistema deja de facturarle igual. **El borrado es
> definitivo y no se puede deshacer** — incluidas las facturas pagadas, que son historia
> contable.

### 5.6 Ver el mapa y las estadísticas

- **Usuarios → Mapa de usuarios**: cada cliente aparece como un punto. Se ven también las
  antenas con su radio de cobertura.
- **Usuarios → Estadísticas**: totales, distribución por plan y por estado.

#### Buscar un cliente en el mapa

Sobre el mapa hay una casilla **Buscar cliente**. Escribe lo que tengas a mano —nombre,
apellido, **cédula**, dirección, ciudad, IP, precinto o correo— y aparecerá una lista con
las coincidencias, cada una con su estado del servicio.

Al elegir una, **el mapa se acerca hasta ese cliente**, abre su ficha y el pin rebota un
instante para que lo distingas de los que tiene alrededor.

Algunos detalles útiles:

- **No hace falta escribir tildes**: «gomez» encuentra a «Gómez».
- Puedes escribir varias palabras en cualquier orden: «gomez juan» y «juan gomez» dan lo mismo.
- **El buscador no esconde a los demás clientes.** Sólo te lleva hasta el que elegiste; el
  resto del mapa sigue igual, que es lo que te deja ver en qué zona y junto a qué antena está.
- Si el cliente que buscas no aparece, mira los filtros de **nodo** y **estado del servicio**:
  el buscador sólo ofrece clientes que el mapa esté dibujando. Cuando la causa es un filtro, la
  propia lista te avisa («N coincidencias están ocultas por los filtros») y te deja quitarlos
  con un clic.
- Un cliente **sin coordenadas guardadas** no puede aparecer en el mapa ni en el buscador.
  Edítalo y márcale la ubicación (ver § 5.1).
- Con el teclado: **↓** y **↑** recorren la lista, **Enter** la confirma y **Esc** la cierra.

---

## 6. Prospectos e instalaciones

Un **prospecto** es alguien interesado que todavía no es cliente.

### 6.1 Registrar un prospecto y agendar la instalación

1. Ve a **Soporte → Instalaciones**.
2. Pulsa **Nueva instalación**.
3. Llena los datos de la persona (nombre, cédula, teléfono, dirección, estrato).
4. Elige **fecha** y **técnico**.
5. Guarda.

El prospecto queda en estado **agendado**.

### 6.2 El día de la instalación

El técnico abre la instalación desde **Soporte → Instalaciones** y allí:

1. **Llena los datos técnicos**: en *Conexión / Red* elige sectorial, core, plan y la
   **IP del cliente**; en *Hoja técnica de instalación* van equipos, mediciones y
   observaciones. Al terminar pulsa **Guardar datos técnicos** (el botón guarda las dos
   partes de una vez).
   > **La IP del cliente no es la IP local del PPPoE.** La primera es la que queda
   > asignada al abonado y es la que viaja al alta cuando conviertes el prospecto en
   > cliente; la *IP local* es la punta del router dentro del secret PPPoE. Cada campo
   > tiene su propio desplegable de **IPs libres** del core, con el título del analizador
   > indicando a cuál de las dos pertenece.
   >
   > En cores con PPPoE la IP del cliente antes ni se pedía ni se guardaba, así que el
   > técnico llenaba la IP local creyendo que era la del abonado y esa parte se perdía.
2. **Carga los equipos y materiales** que usó, en *Equipos y materiales usados*. Los equipos con
   serial se eligen de una lista —agrupada por quién los tiene— y los materiales se agregan con
   su cantidad ("4 RJ45"). Puedes cargar **todos los que hagan falta**: la antena, el router, el
   plato y los conectores.
   > Sólo aparece lo que **tú** tienes asignado, más lo del técnico de esa orden. Si no ves nada,
   > pide que te entreguen equipos en *Inventarios → Entregas y traspasos*.
   >
   > Cada línea **se descuenta del inventario** y queda en el historial del equipo. El botón
   > **Devolver** deshace la carga y regresa la existencia a su dueño.
   >
   > El primer equipo que cargues rellena solo marca, modelo, MAC y serial de la hoja. Ya no hay
   > campo *Modelo de antena*: ese dato sale del equipo que cargaste.
3. **Sube fotos**. Puedes seleccionarlas **todas juntas**: el sistema las comprime en el
   teléfono y las va enviando **una por una** por su cuenta, para que no se caiga la subida.
   Verás el progreso mientras trabaja.
   > Antes había que subirlas de a una a mano porque varias juntas hacían fallar la subida.
   > Eso se corrigió; ya no hace falta.
   >
   > Cada foto puede pesar hasta **10 MB** y ser **JPG, PNG o WEBP**. Si una foto no cumple,
   > el sistema la rechaza y te lo dice.
4. **Registra el cobro**: costo de instalación, cargos adicionales, descuento (con motivo),
   forma de pago y cuánto recibió.
   > El desplegable **Cobrar equipo de la instalación** trae los equipos que ya cargaste, con su
   > precio. Sólo ofrece lo que de verdad se descargó, para que la factura y el acta no digan
   > cosas distintas.
5. **Muestra la hoja antes de firmar**: en el bloque *Firmas y cierre de orden* está el botón
   **Ver hoja antes de firmar**. Abre el documento tal como va a quedar —todavía sin firmas—
   para que el cliente lea lo que está firmando. Incluye lo que acabas de escribir aunque no
   hayas pulsado *Guardar hoja*, y **no guarda ni cierra nada**: puedes abrirlo las veces que
   necesites.
   > Esto importa sobre todo con un **prospecto**, que aún no es cliente y no tiene ficha
   > donde consultar el documento después.
   >
   > Si tu teléfono no muestra el PDF dentro de la ventana, usa **Abrir en pestaña** o
   > **Descargar**, en la misma barra.
   >
   > La hoja incluye la lista de **equipos y materiales instalados con su serial**: es lo que el
   > cliente firma que recibió, y lo que sirve para reclamar un equipo que no vuelva.
6. **Recoge las firmas**: la del cliente y la del técnico, dibujadas en pantalla.
   > El trazo se ve a medida que se firma. Si al firmar el recuadro se quedara en blanco,
   > vuelve a trazar la firma: el sistema **no deja cerrar la orden con una firma vacía**.

Al firmar, el sistema genera la **hoja de instalación en PDF** y la orden queda cerrada. El
PDF aparece en el bloque **Documentos de la orden**, en esa misma pantalla, con los botones
**Ver PDF** y **Eliminar**; además queda guardado en la pestaña **Documentos** del cliente.

> **Una orden, una hoja firmada.** Una vez firmada, el botón de firmar desaparece. Si la
> hoja quedó mal, **elimínala** en *Documentos de la orden* y vuelve a firmar — así no
> quedan dos hojas casi iguales sin saber cuál vale.
>
> La hoja **no incluye las fotos** de la instalación: esas se consultan en los documentos,
> que es donde se guardan.

> Antes ese PDF no se mostraba en la pantalla de la instalación —sólo en la ficha del
> cliente—, así que después de firmar parecía que no se había generado nada. Ya se ve donde
> se firma.
>
> Si la instalación es de un **prospecto** que todavía no has convertido en cliente, el PDF
> y las fotos se ven aquí, pero no en ninguna ficha de cliente: no existe todavía. Al
> marcar el prospecto como convertido, todo se traslada solo a la ficha del cliente nuevo.

Al completar la instalación se genera automáticamente la **factura de instalación**.

### 6.3 Convertir el prospecto en cliente

1. En la instalación **completada**, pulsa **Convertir prospecto en cliente** (o el botón
   *Convertir a cliente* del listado de instalaciones).
2. Se abre el alta de cliente **ya rellenada** con lo que hay en la orden:
   - datos personales del prospecto (nombre, cédula, teléfono, dirección, estrato);
   - la **fecha de instalación**, que decide el prorrateo de la primera factura;
   - los **datos técnicos** de la orden: core, plan, sectorial (o caja NAP), **IP del
     cliente**, credenciales PPPoE y MAC del módem. Si la caja es una NAP, el alta entra
     sola en modo **fibra** y deduce la OLT.
3. Revisa, completa lo que falte y guarda.

Al guardar, el prospecto queda enlazado al cliente, su estado pasa a **convertido** y sus
instalaciones y documentos (fotos, hoja firmada) se trasladan a la ficha del cliente nuevo.

> **Si algún dato técnico sale vacío**, es que no quedó guardado en la orden: vuelve a la
> instalación, complétalo y pulsa **Guardar datos técnicos**. Antes esos campos no se
> traían y había que digitarlos de nuevo a mano; ahora se cargan solos, y lo que escribas
> tú manda sobre lo que traiga la orden.

---

## 7. Facturación

### 7.1 Cómo funciona (esto es lo más importante del sistema)

**Las facturas se generan solas.** No hay que emitirlas a mano cada mes.

Pero hay algo que sorprende a casi todo el mundo la primera vez:

> ⚠️ **La facturación se configura POR ROUTER, no por empresa ni por cliente.**

Cada router tiene asociada una configuración con:

| Campo en pantalla | Qué significa |
|---|---|
| **Se emite la factura — Día / Hora** | Día y hora del mes en que se *genera* la factura de los clientes de ese router |
| **Vence la factura — Día límite de pago** | Último día para pagar. Pasado ese día la factura queda *vencida*, pero el servicio sigue activo |
| **Recordatorio de pago — Día / Hora** | Cuándo se avisa al cliente de lo que tiene pendiente |
| **Se corta el servicio — Día / Hora** | Desde qué día del mes se empieza a suspender morosos |
| **Suspender tras X facturas vencidas** | Cuántas facturas sin pagar tolera antes de cortar. **Es la condición real del corte** |
| **Dejar de facturar al moroso** | A partir de cuántas facturas pendientes se le deja de emitir la mensualidad. Por defecto: el umbral de corte **+ 2** |
| **Modo de facturación** | *Anticipado* (se cobra el mes que empieza) o *Vencido* (se cobra el mes que terminó) |

Esto significa que **si un cliente no recibe factura, lo primero que hay que mirar es la
configuración de su router**, no la del cliente.

> Si configuras el día 31 y el mes tiene 30 días, el sistema factura el día 30. No se salta.

#### Qué periodo cubre la factura, y por qué el corte no cae el día que uno espera

Los cuatro días se configuran por separado y es fácil confundirlos. Estas son las reglas:

- **El periodo facturado es siempre el mes calendario completo** (del 1 al último día), sin
  importar qué día se emita. Emitir el 1 o el 15 no mueve el periodo; sólo cambia la fecha de
  emisión. La única excepción es el prorrateo de la primera factura de un cliente nuevo, que
  arranca el día de la instalación.
- **La primera factura sale al guardar el cliente**, no el día de facturación del router.
  Es exactamente el prorrateo que el formulario te mostró antes de guardar, y el aviso de
  "Cliente creado" te dice con qué número salió. Si el cliente quedó en **No facturar**,
  con plan de cortesía, con la opción **Sin cobro** o el router cobra **vencido**, no sale
  ninguna: eso es a propósito.
- **Anticipado** = el periodo es el mes en que se emite. **Vencido** = el mes anterior.
- **El día límite de pago no corta nada.** Sólo marca desde cuándo la factura cuenta como
  vencida. Si ese día es anterior al de emisión, el vencimiento se corre al mes siguiente.
- **El día de corte es una ventana, no una fecha exacta.** Desde ese día y hasta fin de mes el
  sistema revisa cada hora; suspende únicamente a quien haya llegado al número de
  **facturas vencidas** configurado. Con el umbral en 2, el cliente arrastra un ciclo entero
  antes de que lo corten: por eso el corte "real" suele caer un mes después del primer impago.
- **El recordatorio** se envía por día del mes, una sola vez por ciclo, sobre las facturas
  pendientes que tenga el cliente en ese momento. Es **un solo mensaje** con todas ellas y el
  total adeudado, no uno por factura.
- **Al cliente cortado se le sigue facturando.** El corte no congela la deuda: puede reconectarse
  pagando y esos meses de servicio existen. Lo que frena la acumulación es el tope
  **«Dejar de facturar al moroso»**: al llegar a ese número de facturas pendientes no se le
  genera ninguna más y la deuda queda quieta. Con corte en 2 y tope +2, el cliente acumula 4
  facturas y ahí para. Los clientes **retirados** o **cancelados** no facturan nunca.

El panel de facturación del router muestra un recuadro **«Así queda el ciclo»** que traduce la
configuración a fechas reales del mes en curso (emisión, periodo cubierto, recordatorio,
vencimiento y corte) y avisa de combinaciones sospechosas — recordatorio después del
vencimiento, o día de corte anterior al vencimiento. Es sólo informativo: no cambia nada.

### 7.1.1 El Panel de Finanzas

**Finanzas → Resumen.** Arriba a la derecha hay un **selector de mes** con flechas: todo lo que
ves debajo es de ese mes. No puedes avanzar más allá del mes actual.

| Tarjeta | Qué cuenta exactamente |
|---|---|
| **Facturado del mes** | Lo emitido en el mes elegido. **No incluye facturas anuladas** |
| **Recaudado del mes** | Todo el dinero que entró en el mes, aunque sea de facturas viejas |
| **Gastos del mes** | Gastos con fecha en el mes, sin los anulados |
| **Balance del mes** | Recaudado **−** gastos. Verde si te sobró, rojo si no |
| **Cartera total** | Lo que te deben **en total**, de todos los meses. Es la única cifra acumulada |
| **Tasa de cobro** | De lo facturado **en ese mes**, qué porcentaje ya está pagado |

Tres cosas que conviene entender para no leer mal los números:

**El balance es de caja, no de papel.** Resta los gastos de lo que *cobraste*, no de lo que
*facturaste*. Una factura emitida que nadie pagó no sirve para pagar la nómina, así que no entra.

**La cartera es la única cifra acumulada, y es a propósito.** Si sólo mostrara la mora del mes,
escondería la deuda vieja — que es justamente la que hay que perseguir.

**La tasa de cobro no compara recaudado contra facturado.** Compara lo pagado *de las facturas de
ese mes* contra lo facturado ese mes. Si cobras mora de hace tres meses, ese dinero suma en
*Recaudado* pero no sube la tasa: pertenece a otro mes.

> **Gastos y Balance sólo se ven con permiso de gastos.** Un rol de sólo facturación ve las otras
> cuatro tarjetas y estas dos no aparecen — no salen en cero, desaparecen.

### 7.2 Ver y buscar facturas

**Finanzas → Facturación.**

Los estados son:

| Estado | Significa |
|---|---|
| **Borrador** | Creada pero no emitida |
| **Emitida** | Enviada al cliente, pendiente de pago |
| **Parcial** | Tiene un abono, falta saldo |
| **Pagada** | Cancelada por completo |
| **Vencida** | Pasó la fecha de pago sin pagarse |
| **Anulada** | Sin efecto |

El buscador de arriba busca a la vez por **número de factura**, **nombre**, **apellido**,
**cédula** y **correo** del cliente. No distingue mayúsculas: `eliud` encuentra a *Eliud*.

**Una casilla debajo de cada título**, igual que en Recaudos, para revisar las emitidas sin
depender del buscador general:

| Columna | Qué acepta |
|---|---|
| Número | Parte del número de factura (`0042` encuentra la `FAC-2026-0042`) |
| Cliente | Nombre, apellido, **nombre completo**, cédula o correo |
| Tipo | Lista con tus tipos de factura (incluye los desactivados: hay facturas viejas con ese tipo) |
| Total | Dos casillas: **mínimo** y **máximo** |
| Saldo | Dos casillas: **mínimo** y **máximo**. Con `Mín. 1` ves sólo las que aún deben algo |
| Estado | Emitidas, parciales, pendientes, vencidas, pagadas, canceladas o anuladas |
| Vencimiento | **Desde** y **hasta**: te deja ver todo lo que vence esta semana |

**Limpiar** (al final de la fila de casillas, o arriba junto al selector de mes) quita todos
los filtros de golpe. El **mes** no se borra con ese botón: tiene su propio selector.

Los títulos **Número, Tipo, Total, Saldo, Estado y Vencimiento** ordenan la tabla: púlsalos
una vez para ordenar y otra para invertir el sentido. La flecha indica por cuál estás
ordenando.

> Al escribir en el buscador, en **Número** o en **Cliente**, el selector de mes se apaga y
> la búsqueda recorre **todos los meses**. Si no, el mes actual escondería las facturas del
> cliente en cualquier otro periodo y la tabla parecería vacía.

En el pie de la tabla eliges cuántas facturas ver por página (20, 50, 100 o 200).

Arriba de la tabla verás dos totales: **Total facturado** y **Saldo pendiente** (lo que falta
por cobrar). Ambos suman **todas** las facturas que cumplen el filtro, no sólo las de la página
que estás viendo, así que puedes filtrar por mes o por estado y leer la cifra directamente sin
sumar a mano. Las facturas **anuladas** no se cuentan en esos totales.

En **Finanzas → Pagos / Recaudos** hay un total equivalente: **Total recaudado** con los filtros
que tengas puestos.

**Exportar a Excel.** El botón **Exportar CSV** (en Facturación, Recaudos y Gastos) descarga
**todo lo que cumple el filtro que tengas puesto**, no sólo la página que estás viendo. Es decir:
filtra primero por mes, estado, cliente o lo que necesites, y luego exporta. El archivo se abre
directamente en Excel con las tildes y los importes bien puestos.

### 7.3 Ver el detalle y descargar el PDF

Pulsa sobre una factura. Verás los ítems, los pagos aplicados y el saldo.
El botón **Descargar PDF** genera la factura con el diseño y los datos de tu empresa.

### 7.4 Crear una factura manual

**Finanzas → Facturación → Nueva factura.** Necesitas indicar cliente, **tipo de
factura**, **concepto**, fecha de emisión, fecha de vencimiento, periodo y total. El número
lo asigna el sistema.

El **concepto** es lo que el cliente verá como línea de detalle en la factura y en el PDF
("Mensualidad agosto con descuento pactado"). Si lo dejas vacío se usa el nombre del tipo de
factura y el mes.

Si el cliente tiene **saldo a favor**, se aplica solo a esta factura, igual que en la
facturación automática: la factura puede quedar como *Pago parcial* o directamente *Pagada*.

### 7.5 Servicios adicionales

**Finanzas → Servicios adicionales.** La pantalla tiene **dos pestañas**, porque hay dos
cosas distintas que se cobran fuera del plan:

| Pestaña | Para qué | Cómo se cobra |
|---|---|---|
| **Catálogo** | Algo que se cobra **todos los meses**: alquiler de un router extra, soporte técnico mensual, un punto de TV adicional | Se suma a la **factura mensual** del cliente. No genera factura aparte |
| **Cargo puntual** | Algo que se cobra **una sola vez**: un traslado, un cambio de equipo, una reconexión | Genera **su propia factura**, con su tipo y su fecha de vencimiento |

#### El catálogo

Es la lista de servicios que puedes asignar a tus clientes. Se crea **una vez** y se le
asigna a todos los que lo tengan, en vez de escribir la misma descripción cliente por
cliente.

Para crear uno pulsa **Nuevo servicio** y llena:

1. **Nombre** y **descripción** (ej. *Alquiler de router extra*).
2. **Precio mensual** — es el precio de lista. Al asignarlo a un cliente concreto puedes
   dejarle otro precio sin afectar a los demás.
3. **Cobro del primer mes** — qué pasa si el servicio se activa a mitad de mes:

   | Opción | Qué hace |
   |---|---|
   | *Mes completo* | Si se activa el 20, se cobra el mes entero. Lo habitual cuando ya entregaste el equipo |
   | *Proporcional a los días* | Si se activa el 20 de un mes de 30, se cobran los 10 días restantes |
   | *No cobrar el primer mes* | Empieza a facturarse en el ciclo siguiente |

4. **Cobrar en meses de cortesía** — si el cliente está en un mes de cortesía por
   instalación, ¿este servicio se cobra igual? Viene activado: lo normal es que la
   promoción cubra el internet, no el alquiler del equipo.

Cada tarjeta muestra a **cuántos clientes** se le está cobrando.

> **Desactivar en vez de eliminar.** Un servicio que ya está asignado a algún cliente no se
> puede borrar — el sistema te pedirá desactivarlo. Es a propósito: las facturas que ya lo
> cobraron tienen que poder seguir explicando qué se cobró. Desactivarlo lo saca de la
> lista al asignar y conserva todo el historial.

#### El cargo puntual

Para cobrar algo que no viene de un ticket. Eliges cliente, **tipo de factura**, ítems y
fecha de vencimiento.

> **¿Dónde veo los cargos que ya generé?** El botón **Ver cargos generados** abre
> Facturación mostrando sólo las facturas de ese tipo, de todos los meses. Un cargo
> puntual es una factura como cualquier otra, así que desde ahí puedes filtrarla, ver sus
> totales y exportarla igual que el resto.

### 7.5.1 Tipos de factura (equipos, TV, reconexión…)

**Finanzas → Tipos de factura.** Aquí decides con qué nombres facturas. No estás limitado
a los cuatro de fábrica: crea "Factura de Equipos", "Factura de TV", "Reconexión", lo que
uses.

Para crear uno:

1. Pulsa **Nuevo tipo**.
2. Escribe el **nombre** (ej. *Factura de Equipos*).
3. Elige un **color** para la etiqueta — es como se verá en el listado de facturas.
4. Guarda. El tipo aparece de inmediato en **Nueva factura** y en **Servicios adicionales**.

| | |
|---|---|
| **Tipos del sistema** | *Plan Mensual*, *Instalación*, *Servicio Adicional* y *Cargo de Ticket*. No se editan ni se borran: la facturación automática depende de ellos |
| **Desactivar un tipo** | Deja de ofrecerse al facturar, pero las facturas que ya lo usan conservan su etiqueta. Es lo que hay que hacer cuando ya no se usa un tipo |
| **Eliminar un tipo** | Sólo si **nunca** se ha emitido una factura con él. Si ya tiene facturas, el sistema no deja borrarlo y te pide desactivarlo |

> El nombre se puede cambiar cuando quieras (las facturas viejas se ven con el nombre
> nuevo). Lo que **no** cambia es el identificador interno que se creó al principio.

### 7.6 Corregir una factura: descuentos, cambios de precio y anulaciones

Es la duda más frecuente del módulo, y la respuesta corta es: **no borres la factura para
crear otra.** Casi nunca es lo que hace falta y es la única acción que no se puede deshacer.

| Lo que quieres | Cómo se hace |
|---|---|
| **Hacer un descuento** | Abre la factura → recuadro *Ajuste manual / Adicional* → concepto (ej. `Descuento acordado`) y monto **en negativo** (`-10000`). El total y el saldo se recalculan solos y el descuento queda **como una línea visible** en el PDF |
| **Cobrar algo extra** | Igual, pero con monto positivo |
| **Cambiarle el precio** | El mismo ajuste: la diferencia, positiva o negativa. Así queda escrito **por qué** cambió |
| **Dejarla sin efecto** | *Editar* → estado **Cancelada**. Sale de los totales y de la mora, conserva su número, y el mes sigue "ocupado" para que la automática no la duplique |
| **Corregir fechas o notas** | *Editar factura* |
| **Deshacer un pago mal registrado** | *Marcar como no pagada*: revierte los pagos y restaura el saldo |

**Qué pasa realmente si eliminas una factura:**

- El mes de esa factura **queda bloqueado**: la facturación automática **no la volverá a
  generar nunca**. Los meses siguientes siguen normales.
- Si tenía pagos, ese dinero **vuelve como saldo a favor** del cliente. El recaudo no se
  borra, pero deja de estar aplicado a nada hasta que alguien lo use.
- El **número se pierde**: el consecutivo salta y no se reutiliza.
- Se borran también los ítems del detalle. **No hay papelera.**

> ⚠️ Antes de confirmar, el aviso te dice **cuánto dinero** tiene esa factura ya aplicado.
> **Si ahí aparece una cifra, párate**: casi siempre significa que estás borrando una factura
> ya pagada — probablemente la de un mes anterior en vez de la del mes en curso.

### 7.7 Recordatorios de pago

Se envían solos en el día y la hora configurados en el router, por correo, por WhatsApp o
por ambos. También puedes enviarlos manualmente:

- **Individual**: desde el detalle de la factura, botón **Enviar recordatorio**.
- **Masivo**: desde la lista, botón **Recordatorios masivos**.

El sistema **no duplica** recordatorios: si ya se envió uno en ese ciclo, no lo repite.

**Un cliente = un mensaje.** Si debe varias facturas recibe **un solo** correo/WhatsApp con el
listado de todas y el total adeudado, no uno por factura. Los recordatorios automáticos van
sólo a clientes **activos**: al que ya está cortado el aviso le llega tarde (el corte fue el
aviso), aunque sus facturas se sigan emitiendo hasta el tope.

### 7.7.1 El aviso de "nueva factura"

Cuando se genera la mensualidad, el cliente recibe el aviso configurado en el router
(correo / WhatsApp / ambos / ninguno). Dos detalles:

- Si el cliente **ya debía facturas anteriores**, el correo lo dice: muestra cuántas tiene
  pendientes, el saldo anterior y la **deuda total**. Antes sólo veía el valor del mes y pagaba
  de menos.
- Si la factura **nace saldada** porque el saldo a favor del cliente la cubrió entera, **no se
  envía ningún aviso**: avisar de una factura ya pagada confunde al cliente.

### 7.8 Clientes que no se facturan

Hay dos formas de dejar a un cliente fuera de la facturación:

| Forma | Efecto |
|---|---|
| **Plan de cortesía** | Se le asigna un plan marcado como cortesía. Su servicio queda en "gratis" y no se factura |
| **No facturar a este cliente** | Casilla en la ficha del cliente. Lo saca de todo: factura, recordatorio, notificación y corte |

Si en cambio lo que quieres es que **la factura se siga generando y cobrando normalmente** pero
el cliente deje de recibir los avisos por correo/WhatsApp, usa la casilla **"No enviar
notificaciones de factura"** (misma ficha del cliente, pestaña **Datos del Cliente**). No afecta
mora ni corte automático.

---

## 8. Pagos y recaudos

### 8.1 Registrar un pago

1. **Finanzas → Pagos / Recaudos → Registrar pago.**
2. Elige el **cliente**.
3. Escribe el **monto**.
4. Elige la **fecha** del pago.
5. Elige la **forma de pago** (Efectivo, Tarjeta, Corresponsal, Transacción, o las que
   hayas creado).
6. Opcional: **referencia** (número de consignación) y **notas**.
7. Pulsa **Guardar**.

### 8.2 Qué pasa automáticamente al guardar

Esto ocurre solo, sin que hagas nada más:

1. El pago se aplica a las facturas pendientes, **empezando por la más antigua**.
2. Si sobra dinero, queda como **saldo a favor** del cliente y se usará en la próxima factura.
3. Si **falta** dinero (abono parcial), la factura **queda pagada igual** y lo que faltó
   pasa a la próxima factura. Ver 8.2.1.
4. **Si el cliente queda al día y estaba cortado por mora, el sistema le devuelve el
   internet automáticamente.**

> La reconexión automática **sólo aplica a cortes por facturación**. Si el cliente fue
> suspendido a mano, hay que reactivarlo a mano.

### 8.2.1 Abonos parciales: el saldo pasa a la próxima factura

Cuando el cliente paga **menos** de lo que debe:

- La factura se marca como **Pagada** y su saldo queda en cero.
- Lo que faltó queda como **saldo pendiente** del cliente.
- La **próxima factura mensual** lo cobra automáticamente: sale una línea
  *"Saldo pendiente de facturas anteriores (#…)"* sumada al plan del mes.

**Ejemplo.** El cliente debe $50.000 y abona $30.000. Esa factura queda pagada y quedan
$20.000 pendientes. El mes siguiente, si el plan vale $50.000, su factura será de
**$70.000**.

> ⚠️ **Importante:** al quedar la factura pagada, el cliente **sale de mora**. Si estaba
> cortado, se le devuelve el internet, y **no se le vuelve a cortar hasta que se venza la
> factura nueva** (la que ya trae la deuda vieja sumada). Al registrar un abono parcial el
> sistema te avisa de esto y te pide confirmar.

Dónde ver el saldo arrastrado:

| Dónde | Qué se ve |
|---|---|
| Al registrar un pago | Bloque ámbar *"Saldo pendiente arrastrado"* junto al saldo del cliente |
| Ficha del cliente → Facturación | Aviso ámbar con el total arrastrado |
| Lista de facturas, columna Saldo | *"↷ $X a la próxima"* en la factura que abonó, y *"incluye $Y de saldo anterior"* en la que lo cobra |
| Detalle de la factura | De qué factura vino el saldo y a cuál se fue |

Si registraste el abono por error: **eliminar el pago** o **Marcar como no pagada**
devuelve el saldo a la factura original — siempre que la próxima factura no lo haya
cobrado todavía. Si ya lo cobró, el saldo se queda en esa factura nueva (no se cobra dos
veces).

### 8.3 Buscar en la lista de recaudos

**Finanzas → Pagos / Recaudos** muestra **todos** los recaudos, de más nuevo a más
viejo, repartidos en páginas (los botones de página están al final de la tabla).
No hace falta buscar nada para verlos.

Para acotar la lista:

- **Buscador de arriba:** busca a la vez por **cliente** y por **referencia**.
- **Cada columna tiene su propio buscador**, en la casilla que hay justo debajo del
  título. Se combinan entre sí:

| Columna | Qué acepta |
|---|---|
| Fecha | Dos casillas: **desde** y **hasta** (ambas fechas incluidas). Puedes usar solo una |
| Cliente | Nombre, apellido, nombre completo o cédula |
| Monto | Dos casillas: **mínimo** y **máximo** |
| Método | Lista con tus formas de pago |
| Referencia | Parte del número de comprobante |
| Registrado por | Nombre del usuario que lo registró. Escribe `sistema` para ver los pagos automáticos (los de instalación, que no los registró una persona) |
| Facturas afectadas | Número (o parte) de una factura cubierta por el recaudo |

**Limpiar** (al final de la fila de casillas, o arriba junto al buscador) quita todos
los filtros de golpe.

También puedes **ordenar** pulsando en los títulos **Fecha**, **Monto**, **Método** y
**Referencia**; el segundo clic invierte el orden.

**Los colores de la lista:** cada forma de pago tiene su color fijo, para distinguirlas
de un vistazo. Los números de **Facturas afectadas** usan el mismo código de color que
la columna *Tipo* de Facturación (azul = Plan Mensual, verde = Instalación, morado =
Adicional, ámbar = Cargo Ticket); pasando el mouse por encima sale el tipo y cuánto se
aplicó a esa factura. Si un recaudo no cubrió ninguna factura, dice **Saldo a favor**.

En el pie de la tabla eliges cuántos recaudos ver por página (15, 25, 50, 100 o 200).

> En el celular no hay tabla, así que los mismos filtros salen con el botón
> **Filtros** que aparece al lado del buscador.

### 8.4 Corregir o eliminar un pago

Desde la lista de pagos. Al eliminarlo, las facturas que había cubierto vuelven a quedar
con saldo.

### 8.5 Saldo a favor

En la ficha del cliente, pestaña **Facturación**, verás su saldo a favor. Un administrador
puede ajustarlo manualmente si hace falta.

No confundir con el **saldo pendiente arrastrado** (8.2.1): el saldo a favor es plata que
el cliente pagó de más y se le descuenta; el arrastrado es plata que le falta por pagar y
se le sumará.

#### 8.5.1 «La factura dice $60.000 pero me cobran $36.000»

Es la pregunta más común en el mostrador y casi siempre tiene la misma respuesta: el cliente
tiene saldo a favor y el sistema se lo está descontando de esa factura.

Pasa sobre todo con clientes que **pagan siempre la misma cifra redonda** aunque su plan valga
otra cosa. Alguien con plan de $60.000 que paga $70.000 todos los meses acumula $10.000 cada mes,
y el monto a cobrar le va bajando: primero $50.000, después $40.000, y así.

Para verlo, botón **Ver movimientos** en el recuadro de Saldo a Favor. El extracto muestra de
dónde salió cada peso y en qué factura se gastó, con fecha y saldo resultante.

Si el extracto muestra un aviso de **descuadre**, no es un tema del mostrador: repórtalo al
administrador. Significa que la suma de los movimientos no coincide con el saldo guardado.

Cuando el cliente lleva meses pagando de más, lo que hay que resolver no es la factura sino el
acuerdo: o el plan tiene el precio equivocado, o hay que devolverle o avisarle.

### 8.6 Formas de pago

**Finanzas → Formas de pago.** Puedes crear las tuyas, editarlas o desactivarlas.
El sistema trae por defecto: Efectivo, Tarjeta, Corresponsal y Transacción.

---

## 9. Corte y reconexión de morosos

### 9.1 Cómo funciona

El sistema revisa **cada hora** si hay que cortar a alguien. Corta a un cliente cuando se
cumplen **todas** estas condiciones:

1. Su router está configurado como **Corte Automático**.
2. Ya llegó el **día de corte** configurado.
3. Ya llegó la **hora de corte** configurada.
4. El cliente acumula al menos **N facturas vencidas** (N lo configuras en el router).

Si el router está en **Corte Manual**, el sistema **no corta**: sólo deja la lista de
pendientes para que alguien decida.

> **Los abonos parciales sacan al cliente de la cuenta de facturas vencidas.** Al abonar,
> esa factura queda pagada y el faltante viaja a la próxima (ver 8.2.1), así que deja de
> contar para el corte hasta que la factura nueva se venza sin pagar.

### 9.2 Qué le pasa al cliente cortado

Deja de navegar, pero **sí puede entrar al portal de pago** de tu empresa. Ese acceso queda
abierto a propósito, para que pueda pagar y reconectarse.

Además, mientras está cortado, **cualquier página que intente abrir lo lleva al portal**. No
ve un "sin conexión" a secas: ve tu página de pago. Es la forma de que entienda por qué se
quedó sin servicio.

> El portal es una dirección que se configura una sola vez al instalar el sistema. **Si esa
> dirección no está configurada, las reglas de bloqueo ni siquiera se pueden aplicar**: el
> sistema te lo dirá al pulsar *Aplicar reglas de bloqueo*. En ese caso es cosa de soporte
> técnico, no algo que se arregle desde las pantallas.

### 9.3 Ver qué se cortó y qué falló

**Acciones masivas** tiene dos paneles:

| Panel | Qué muestra |
|---|---|
| **Bitácora de facturación** | Facturas que no se pudieron crear, con el error y el número de intentos |
| **Bitácora de cortes** | Cortes y reconexiones que fallaron en el equipo |

En ambos puedes pulsar **Reintentar** sobre una fila, o **Reintentar todo**.

Además, en la bitácora de cortes está el botón **Reconciliar**: revisa uno por uno los
clientes que el sistema dio por suspendidos y comprueba que **realmente** estén cortados en
el equipo. Si alguno no lo está, lo vuelve a cortar.

> El sistema hace esta reconciliación solo cada hora. El botón sirve para forzarla.

### 9.4 El cliente aparece cortado pero sigue navegando

Este es **el problema más común del sistema**, y casi siempre es una de tres cosas. El
sistema dice "cortado" porque hizo su parte; lo que falla está en el equipo. Revisa en este
orden — está ordenado de la causa más frecuente a la menos frecuente.

**1. El túnel VPN del router está caído.**

Si el router no tiene túnel contra el equipo central, ISPWatch no puede darle ninguna orden:
ni cortar, ni reconectar, ni cargar clientes nuevos. Y como el corte se registra en la base
de datos antes de llegar al equipo, la pantalla muestra al cliente cortado aunque en la
realidad nunca se tocó nada.

- Entra a **Gestión → Lista de Routers**, abre el equipo y pulsa **Verificar VPN**.
- Si sale caído, el equipo perdió el túnel. Hay que levantarlo desde el router (o volver a
  aplicarle el script con **Generar script VPN**) antes de intentar cualquier otra cosa.
- Señal de alarma: **muchos clientes del mismo router** sin cortar a la vez. Un cliente
  suelto suele ser otra cosa; el router entero es casi siempre la VPN.

> El sistema revisa los túneles solo cada 30 minutos y avisa por correo cuando encuentra uno
> caído. No esperes ese aviso si ya tienes la sospecha: verifica a mano.

**2. Las reglas de bloqueo no están, o quedaron muy abajo en el equipo.**

El router aplica sus reglas **en orden, de arriba hacia abajo**, y se queda con la primera
que coincide. Si las reglas de ISPWatch quedaron por debajo de una regla que deja pasar el
tráfico (muy común: las que trae el equipo de fábrica), nunca llegan a ejecutarse. Para el
router las reglas están ahí; simplemente no se leen nunca.

Esto pasa sobre todo cuando alguien tocó el firewall del equipo a mano después de que
ISPWatch instaló las reglas.

- Pulsa **Verificar reglas de bloqueo** para ver cómo están puestas.
- Pulsa **Aplicar reglas de bloqueo**: además de instalarlas si faltan, **las vuelve a subir
  al primer lugar**. Es la forma normal de arreglar el orden — no hay que borrar nada a mano.
- Puedes pulsarlo las veces que quieras. No duplica reglas ni rompe lo que ya está bien.

> Para poder aplicarlas, el router necesita tener la **interfaz WAN** configurada. Si te dice
> *"Router sin interfaz WAN configurada"*, ve a **Fijar interfaz WAN** primero.

**3. El cliente sigue con conexiones abiertas de antes.**

Un corte solo afecta a las conexiones **nuevas**. Si el cliente estaba con una descarga o un
video ya andando, esa conexión sigue viva por su cuenta. El sistema corta esas conexiones al
suspender, pero si el corte se aplicó tarde (por ejemplo, después de arreglar la VPN) puede
que alguna quede colgada unos minutos.

Espera un par de minutos y vuelve a comprobar antes de seguir buscando.

**Después de arreglar cualquiera de las tres**, entra a **Acciones masivas → Bitácora de
cortes** y pulsa **Reconciliar**. Eso revisa uno por uno a los clientes que figuran como
suspendidos y vuelve a cortar a los que no lo estén de verdad. Sin este paso, los clientes
que ya estaban mal marcados siguen navegando aunque el router ya esté bien configurado.

**Si con esto no se arregla**, revisa lo demás:

| Revisa | Dónde |
|---|---|
| Que el router esté en **Corte Automático**, no Manual | Ficha del router → tipo de corte |
| Que el equipo responda al SSH | Ficha del router → **Probar conexión SSH** |
| Que el cliente no esté marcado **"No facturar"** | Ficha del cliente |
| Que el cliente no haya **abonado** (un abono parcial lo saca de mora, ver [8.2.1](#821-abonos-parciales-el-saldo-pasa-a-la-próxima-factura)) | Ficha del cliente → Facturación |
| El error exacto del intento fallido | **Acciones masivas → Bitácora de cortes** |

> **Antes de estrenar el corte automático en un router nuevo:** verifica la VPN y aplica las
> reglas de bloqueo. Si no lo haces, el primer día de corte el sistema marcará a todos como
> cortados y ninguno lo estará.

---

## 10. Gastos

**Finanzas → Gastos.**

> Las **categorías de gasto** ahora se ven como tarjetas en lugar de una tabla
> (*Finanzas → Categorías de gasto*): cada tarjeta muestra el concepto con sus
> botones de editar y eliminar. Es el mismo formato que *Formas de pago*.

1. Pulsa **Nuevo gasto**.
2. Elige la **categoría** (puedes crearlas en *Categorías de gasto*).
3. Indica **fecha** y **monto**.
4. Opcional: **beneficiario** (el empleado o técnico a cuyo nombre va el gasto; déjalo vacío
   en gastos como arriendo o servicios públicos). La lista muestra **sólo personal del ISP**:
   los clientes no aparecen ahí.
5. Añade descripción y notas.

> **Los gastos no se borran.** Si te equivocaste, edítalo y cámbialo a estado **anulado**.
> Así queda el rastro de la corrección.

**Para encontrar un gasto**, el buscador de arriba busca por **descripción, observaciones y
beneficiario** — útil cuando no recuerdas la fecha exacta. Se combina con los filtros de fecha,
categoría y estado: puedes buscar "energía" *y* acotar a un mes *y* a una categoría a la vez.

La lista viene **paginada** (puedes cambiar cuántos gastos ves por página abajo a la izquierda).
Las tarjetas de **Total del período filtrado** y **Por categoría** siempre suman **todos** los
gastos que cumplen el filtro, no sólo los de la página que estás viendo. Los gastos **anulados**
siguen apareciendo en la lista, pero no se suman en esos totales.

---

## 11. Routers y red

**Gestión → Lista de Routers.**

### 11.1 Agregar un router

Los datos mínimos son: nombre, IP, usuario y contraseña de administración del equipo,
versión de firmware y estado.

**Puertos:** por defecto API 8728 y web 80. **Si el SSH del equipo no está en el 22, tienes
que indicarlo en el campo de puerto SSH**, o el sistema no podrá conectarse.

### 11.2 El método de control

Aquí eliges **cómo controla el router a los clientes**. Sólo puede haber **uno activo**:

| Método | Cuándo usarlo |
|---|---|
| **Simple Queue** | Control de velocidad por IP. El más común |
| **PCQ** | Reparto equitativo de ancho de banda |
| **HotSpot** | Clientes que entran con usuario y contraseña en un portal |
| **PPPoE** | Clientes con usuario y contraseña de conexión |
| **DHCP Leases** | Asignación fija por dirección MAC |
| **RADIUS (AAA)** | Tienes un servidor RADIUS que autentica a los clientes |

> **RADIUS funciona al revés que los demás.** Con los otros métodos, ISPWatch entra al
> router y escribe la configuración de cada cliente. Con RADIUS es el router el que
> pregunta e ISPWatch responde, así que **los clientes ya no se cargan uno por uno en el
> Mikrotik**: dar de alta a alguien es instantáneo y las cargas masivas dejan de fallar
> por demora.
>
> Para usarlo, los clientes de ese router necesitan **usuario y contraseña PPPoE**.
> La configuración del servidor RADIUS (secreto compartido, puertos, perfiles) se hace
> **en ese servidor**, no en ISPWatch: aquí sólo marcas que el router lo usa, para que
> el sistema deje de escribirle configuración por su cuenta.

Y dos opciones **adicionales** que se suman al método elegido:

- **IP Bindings**: fija la relación IP–equipo.
- **Amarre**: bloquea al cliente si cambia de equipo.

### 11.3 Configurar la facturación del router

En la ficha del router eliges la **configuración de facturación** y el **tipo de corte**
(Automático o Manual). **Sin esto, los clientes de ese router no se facturan ni se cortan.**

El bloque *Facturación del Router* lleva los cuatro momentos del ciclo — emisión,
vencimiento, recordatorio y corte — con un recuadro **«Así queda el ciclo»** debajo que los
muestra ya traducidos a fechas del mes en curso. Si algo no cuadra (recordatorio después del
vencimiento, corte antes del vencimiento) aparece un aviso ámbar. El detalle de cada regla
está en [7.1](#71-cómo-funciona-esto-es-lo-más-importante-del-sistema).

### 11.4 Herramientas de diagnóstico

| Botón | Qué hace |
|---|---|
| **Probar conexión SSH** | Comprueba que el sistema llega al equipo |
| **Probar conexión al CORE** | Comprueba el equipo central |
| **Ver interfaces** | Lee las interfaces del router |
| **Fijar interfaz WAN** | Indica cuál es la salida a internet |
| **Aplicar reglas de bloqueo** | Instala en el equipo las reglas necesarias para cortar morosos |
| **Verificar reglas de bloqueo** | Comprueba que las reglas siguen puestas |
| **Generar script VPN** | Genera el texto para configurar el túnel del equipo |
| **Verificar VPN** | Comprueba que el túnel está arriba |

> **"La VPN dice que está conectada y aun así nada funciona en ese router."** Suele ser un
> **túnel duplicado**: dos equipos (o el mismo equipo con una configuración vieja que quedó puesta)
> marcando la VPN desde la **misma conexión a internet**. Se tumban entre sí cada pocos minutos y
> el sistema pierde el control del router aunque lo veas "activo". Ahora **Verificar VPN** te lo
> avisa. La solución es dejar **un solo túnel** por cada conexión a internet.

> **Si "Fijar interfaz WAN" no logra leer las interfaces**, la ventana te explica cuál de los dos
> saltos falló (el sistema al equipo central, o el equipo central al router) y **siempre te deja
> escribir el nombre a mano** — `ether1`, `sfp1`, etc. También hay un botón **Reintentar lectura**:
> vale la pena usarlo si el router acaba de reconectar, porque el fallo suele ser pasajero. Un
> mensaje de *tiempo de espera agotado* significa que el router no alcanzó a contestar; **no**
> significa que la contraseña esté mal.

> **"El túnel está levantado pero el equipo no tiene esa dirección."** Es un mensaje nuevo y es el
> más importante de todos: significa que la VPN del router se conectó, pero el equipo **no se quedó
> con la dirección** que el sistema le asignó, así que quien contesta en esa dirección es otro
> aparato de la red del cliente. Mientras eso siga así **ningún** botón de ese router va a
> funcionar, y no sirve de nada revisar contraseñas ni puertos. Se corrige **en el router del
> cliente** (por Winbox, desde su propia red): comprobar que la interfaz `ISPWatch-VPN-CORE` esté
> corriendo, que aparezca la dirección del túnel en su lista de direcciones IP, y que no haya otro
> equipo usando la misma VPN. Apagar y encender esa interfaz suele bastar.
>
> **La causa más común de eso** es que el túnel del router esté usando el perfil PPP `default`. Si el equipo
> también reparte internet por PPPoE a sus abonados, ese perfil tiene una dirección fija metida y el túnel se
> queda con ella. **Se arregla volviendo a generar el script VPN desde ISPWatch y aplicándolo**: los scripts
> nuevos ya crean un perfil propio para el túnel.

> ⚠️ **Antes de usar el corte automático: verifica la VPN y aplica las reglas de bloqueo.**
> Si el túnel está caído o las reglas no están, el sistema marca al cliente como cortado pero
> el cliente sigue navegando. **Aplicar reglas de bloqueo** sirve también cuando las reglas ya
> existen pero quedaron muy abajo en el equipo: las vuelve a subir al primer lugar. Todo el
> diagnóstico está en [9.4](#94-el-cliente-aparece-cortado-pero-sigue-navegando).

### 11.5 Historial de tráfico

Si activas **Historial de tráfico** en el router, el sistema mide el tráfico de su salida a
internet **cada 5 minutos** y lo guarda. Se consulta desde la ficha del router.

- El **detalle de 5 en 5 minutos** se conserva **30 días**. Sirve para ver el pico de ayer o
  la caída de anoche.
- El **consumo diario** se guarda **para siempre**. Sirve para comparar meses o años.

> Empieza a medir **desde que lo activas**. No hay historial de antes; si acabas de prenderlo,
> la gráfica sale vacía hasta la siguiente medición.
>
> Para que mida hace falta tener fijada la **interfaz WAN** del router (11.4).

### 11.6 Falla masiva

Cuando un nodo se cae y afecta a muchos clientes:

1. Entra a **Gestión → Lista de Routers**.
2. Pulsa **Reportar falla masiva** en el router afectado.
3. Cuando se restablezca, pulsa **Marcar como resuelta**.

Al reportarla, el sistema marca el router, lo resalta en el Dashboard y **cuenta cuántos
clientes activos quedan afectados**. Ambos avisos (la falla y la recuperación) quedan
guardados con la hora y el usuario que los reportó; ese registro no se puede editar ni
borrar, sirve como historial de la caída.

> **Sobre el aviso por WhatsApp:** ISPWatch **no envía los mensajes**. Lo que hace es dejar el
> aviso registrado para que el sistema de mensajería conectado lo lea y lo difunda. Si esa
> conexión todavía no está montada en tu empresa, el botón sigue siendo útil (marca el router,
> alerta en el Dashboard y deja el historial), pero **a los clientes no les llega nada**.
> Confírmalo con tu proveedor antes de contar con el aviso automático.

### 11.7 La VPN: cómo llega ISPWatch a tus equipos

Esto explica **por qué** aparece tanto la palabra "VPN" en el manual. No hace falta entenderlo
para el día a día, pero sí para saber a quién llamar cuando algo no responde.

ISPWatch no habla directo con cada router tuyo. Habla con un **equipo central** (el CORE), y
ese equipo central llega a tus routers por un **túnel privado** que se monta una sola vez,
cuando das de alta el equipo. Todo lo que hace el sistema sobre la red —cargar un cliente,
cortarlo, reconectarlo, leer las interfaces, medir el tráfico— pasa por ese túnel.

**Si el túnel se cae, ISPWatch se queda ciego con ese router.** Sigue mostrando sus clientes,
sigue facturándoles y sigue marcando cortes en pantalla, pero **ninguna orden llega al equipo**.
Por eso el primer paso de casi todo diagnóstico de red es **Verificar VPN**.

**Hay dos tipos de túnel** y el sistema elige solo según la versión del equipo:

| Tipo | Para qué equipos | Cómo se sabe si está vivo |
|---|---|---|
| **WireGuard** | RouterOS **v7** en adelante | Por el último saludo del equipo (se renueva cada pocos minutos) |
| **L2TP** | RouterOS **v6**, que no soporta WireGuard | Por la sesión activa contra el central |

No tienes que elegir nada: al generar el script VPN el sistema mira la versión del equipo y
arma el que corresponde.

> **Por qué se cambió a WireGuard en los equipos nuevos.** Un router con dos salidas a internet
> podía mandar media conversación por una y media por la otra, y el túnel L2TP se caía en bucle.
> Pasó de verdad: un equipo estuvo **8 días caído con 212 clientes sin gestión** y nadie se dio
> cuenta, porque el sistema sólo avisaba de fallos cliente por cliente, nunca de "este router no
> está". WireGuard no tiene ese problema, y desde entonces hay una revisión automática.

**La revisión automática.** Cada 30 minutos el sistema comprueba todos los túneles y **avisa por
correo** los que encuentre caídos. Sólo mira y avisa: no toca nada ni intenta arreglarlo.

> Los routers que nunca se dieron de alta por el central no se revisan (no tienen túnel que
> mirar) y por eso tampoco salen en el aviso. Si un equipo "no aparece nunca en las alertas"
> pero tampoco responde, es probable que sea uno de esos: revísalo con **Probar conexión SSH**.

**Cuándo hay que volver a generar el script VPN:** cuando el equipo se formateó o se reemplazó,
o cuando *Verificar VPN* da caído y el equipo sí tiene internet. Ojo: el script se aplica **en el
router**, no desde ISPWatch — el botón sólo te da el texto para pegarlo.

---

## 12. Sectoriales y fibra óptica

**Gestión → Sectoriales.**

Aquí registras los elementos físicos de tu red. Cada uno tiene un tipo:

| Tipo | Qué es |
|---|---|
| **Sectorial** | Antena que da cobertura a una zona |
| **Nodo** | Punto de concentración |
| **Switch** | Conmutador |
| **OLT** | Cabecera de fibra óptica |
| **Splitter** | Divisor óptico |
| **NAP** | Caja de distribución donde se conectan los clientes |
| **Mufa** | Empalme |

### 12.1 Topología de fibra

**Gestión → Topología FTTH** muestra el árbol completo de la red de fibra:
OLT → splitter → NAP → cliente. Cada elemento indica cuántos puertos tiene y cuántos están
ocupados; los ocupados **se calculan solos** a partir de lo que cuelga de él.

Para armar el árbol, al crear un elemento indica cuál es su **elemento padre**.

- En un **splitter** no escribes el número de puertos: lo saca de la **relación de división**
  que le pongas (`1:8` son 8 salidas).
- En el resto de elementos sí indicas el total de puertos a mano.
- Los puertos ocupados **nunca se editan**: si el número no cuadra, lo que está mal es lo que
  cuelga de ese elemento, no el contador.

> **Un cliente de fibra tiene que estar marcado como fibra.** Si le asignas OLT y puerto NAP
> pero la casilla *Es fibra* quedó apagada, al abrir *Editar* verás los campos de fibra vacíos
> y parecerá que se perdió la información. Hoy el formulario **lo detecta solo** al cargar el
> cliente, así que no debería volver a pasar; si ves un cliente así, ábrelo y guárdalo para
> dejarlo consistente.

### 12.2 Fotos, notas e historial

Cada elemento tiene tres pestañas: **Fotos** (para documentar la instalación en campo),
**Notas** (observaciones de mantenimiento) e **Historial** (registro automático de cambios).

---

## 13. Planes de internet

**Gestión → Plan de Internet.**

Al crear un plan indicas nombre, velocidad de bajada y subida, precio mensual y tipo.
Según el tipo aparecen campos específicos (pool PPPoE, usuarios compartidos de HotSpot,
tasa PCQ, ráfaga...).

Dos opciones importantes:

| Opción | Qué hace |
|---|---|
| **Plan de cortesía** | Los clientes con este plan **nunca se facturan** |
| **Primera factura** | Define para todos los clientes del plan qué se cobra el mes de instalación y cuántos meses de cortesía siguen |

> **Ejemplo real:** el plan "Hogar 100M — instalación con mes de regalo" se configura como
> *Prorrateado + 1 mes de cortesía*. Todo cliente que lo contrate hereda esa promoción sin
> que haya que configurarlo uno por uno.

> El plan llamado **"Gratis"** está bloqueado para uso exclusivo de cortesía.

---

## 14. Soporte técnico

**Soporte → Tickets.**

### 14.1 Crear un ticket

1. **Soporte → Nuevo Ticket.**
2. Elige el **cliente**.
3. Escribe el **asunto** y la **descripción**.
4. Elige **categoría** (Técnico, Facturación, Servicios, General) y **prioridad**
   (Baja, Media, Alta, Urgente).
5. Si el problema es de un elemento de red concreto, selecciona el **sectorial** afectado.
6. Guarda.

### 14.2 Trabajar el ticket

Dentro del ticket puedes:

- **Añadir mensajes**. Puedes marcarlos como **internos**: esos no los ve el cliente.
- **Cambiar el estado**: Abierto → En progreso → Resuelto → Cerrado.
- **Adjuntar archivos**.
- **Generar un cargo**: si la visita se cobra, esto crea una factura ligada al ticket.

### 14.3 Estadísticas

**Soporte → Estadísticas** muestra tickets por estado, por prioridad y por categoría.

---

## 15. Inventario

**Inventarios.** Se organiza en seis secciones:

| Sección | Qué guarda |
|---|---|
| **Stock / Modelos** | Los modelos de equipo que manejas (marca, modelo, precio) |
| **Proveedores** | A quién le compras, con datos del asesor comercial |
| **Sucursales** | Dónde están físicamente los equipos |
| **Lista de equipos** | **Cada equipo individual**, con su serial y su MAC |
| **Entregas y traspasos** | Pasar equipos de la bodega a un técnico y recibirlos de vuelta |
| **Movimientos** | El historial: quién recibió cada equipo y en qué instalación se usó |

### 15.1 Por serial o por cantidad

Al crear un modelo en **Stock / Modelos** eliges cómo se controla:

- **Por serial** — antenas, routers, ONU. Cada unidad se registra aparte con su serial y su MAC,
  y el sistema sabe en todo momento quién la tiene.
- **Por cantidad** — RJ45, cable, platos, cinta. No se registra uno por uno: se lleva un saldo
  ("a Juan le quedan 37 RJ45"). Ahí eliges también la unidad de medida: unidad, metro, rollo.

Esto no se puede cambiar a la ligera una vez el modelo tiene existencias, porque las dos formas
de contar no se mezclan.

### 15.2 Entregar equipos a un técnico

**Inventarios → Entregas y traspasos.** Eliges de dónde sale (una bodega o una persona), marcas
los equipos y escribes las cantidades de material, eliges a quién entra y registras.

Sirve en los dos sentidos: entregar a un técnico el lunes y recibirle los sobrantes el viernes es
el mismo formulario, cambiando origen por destino. Abajo hay además una **Entrada de material**
para dar de alta consumibles comprados.

**Nada se borra nunca.** Un movimiento equivocado se corrige con el movimiento contrario, y los
dos quedan en el historial.

### 15.3 Qué equipos puede usar cada quien

Al llenar la hoja de una instalación, el técnico **sólo ve lo que tiene asignado**. No puede usar
un equipo que carga otro técnico: primero se lo tienen que traspasar. Quien administre inventario
ve además las bodegas, y en una orden concreta cualquiera puede descargar lo que lleva el técnico
asignado a esa orden — así la secretaria puede capturar en oficina una visita ya hecha.

Cada equipo o material que se carga a una instalación **se descuenta de quien lo aportó** y queda
en el historial. Si te equivocaste, el botón **Devolver** lo regresa a su dueño.

Un equipo instalado queda ligado al cliente y ya no aparece como disponible para nadie.

Las cuatro tarjetas de arriba en **Lista de equipos** cuentan cada catálogo por separado:
*Total dispositivos* son los equipos registrados, y *En stock*, *Proveedores* y *Sucursales*
son cuántos modelos, proveedores y sucursales tienes creados — se ven aunque todavía no hayas
registrado ningún equipo.

Para cargar muchos equipos de golpe, ve a **Acciones masivas → Importar inventario**.

---

## 16. Personal y roles

### 16.1 Crear un empleado

**Personal → Nuevo.** Llena nombre, correo, contraseña y **rol**.
El rol es lo que determina qué podrá ver y hacer.

### 16.2 Roles y permisos

En **Roles** puedes crear roles a medida. Estos son **todos** los permisos, agrupados como
aparecen en pantalla:

| Grupo | Permisos |
|---|---|
| **Clientes** | Lista de Clientes · Agregar Clientes · Editar Servicio Internet · Activar y Desactivar Clientes · Editar Descuento · Editar Saldo Pendiente · Eliminar Instalaciones · Tráfico Clientes |
| **Facturas** | Dashboard / Estadísticas · Buscar Facturas · Registrar Pagos · Eliminar Factura · Editar Total a Pagar · Agregar Gasto · Promesas de Pago |
| **Contabilidad** | Lista de Gastos · Editar Gasto · Lista de Facturas · Registrar Pagos · Editar Fecha de Pago · Registrar Pago Mayor 3 Días · Agregar Transferencia · Eliminar Transferencia |
| **Infraestructura** | Gestionar Routers · Ver Planes de Internet · Ver Sectoriales |
| **Inventario** | Ver Inventario |
| **Soporte** | Ver Soporte Técnico |
| **Facturación** | Ver Facturación |
| **Sistema** | Ver Personal · Gestionar Roles · Gestionar Configuración de Empresa · Gestionar Plantillas de Documentos · Ver Ajustes del Sistema · Ejecutar Acciones Masivas |

**Los roles que trae el sistema.** Son un punto de partida; puedes editarlos o crear otros:

| Rol | Alcance |
|---|---|
| **Administrador** | Todo, sin excepción |
| **Técnico** | Sólo clientes: verlos, agregarlos, editar su servicio, activar/desactivar, ver su tráfico y eliminar instalaciones. **No ve dinero**: ni facturas, ni pagos, ni gastos |
| **Contabilidad** | Todo lo de plata: facturas, pagos, gastos, transferencias y estadísticas. **No gestiona la red** ni el personal |
| **Staff** | El operador de mostrador: clientes, planes, sectoriales, inventario, soporte, ver facturación y registrar pagos. **No borra facturas ni toca configuración** |
| **Cliente** | Sin permisos de gestión. Es el rol de los clientes finales |

> **Ojo con "Activar y Desactivar Clientes":** ese permiso no sólo cambia un estado en pantalla,
> **actúa sobre el router de verdad**. Es también el que habilita cargar clientes al equipo. No
> se lo des a quien no deba tocar la red.

> ⚠️ **Muy importante:** cuando el sistema estrena un permiso nuevo, **los roles que ya
> existían no lo reciben solos**. Si tras una actualización una pestaña desaparece para los
> administradores, ve a **Roles**, marca el permiso nuevo, guarda, y pide a los usuarios
> afectados que **cierren sesión y vuelvan a entrar**.

### 16.3 Cuándo hace falta volver a entrar

Si un administrador te acaba de cambiar el rol o de marcarte un permiso, **recarga la página**:
el sistema vuelve a consultar tus permisos y normalmente con eso basta.

Si tras recargar sigues sin ver lo que deberías, cierra sesión y vuelve a entrar. Y si aun así
no aparece, entonces el permiso **no está marcado en tu rol** — no es cosa tuya, hay que
marcarlo en **Roles** (ver el aviso de 16.2).

---

## 17. Configuración de la empresa

**Configuración.**

### 17.1 Datos de la empresa

Razón social, nombre comercial, NIT y dígito de verificación, régimen tributario, actividad
económica, dirección, ciudad, departamento, teléfono y correo de facturación.
**Todo esto aparece en las facturas y contratos**, así que revísalo bien.

### 17.2 Marca

Logo y color corporativo. Se aplican a los documentos que genera el sistema.

### 17.3 Mapas

Clave de Google Maps para el mapa de clientes. La clave se guarda cifrada y nunca se
muestra de vuelta.

### 17.4 Plantillas de documentos

Pestaña **Plantillas**. Puedes editar el contenido de tres documentos:

| Plantilla | Uso |
|---|---|
| **Factura** | Cuerpo de la factura en PDF |
| **Contrato** | Contrato de servicio que firma el cliente |
| **Instalación** | Acta de instalación |

Se editan con un editor de texto enriquecido. Puedes insertar **marcadores** que el sistema
reemplaza por datos reales (nombre del cliente, plan, monto...). Si escribes un marcador que
no existe, o el de otro tipo de documento por error (por ejemplo, uno de factura dentro de la
plantilla de Contrato), simplemente no aparece nada ahí — no da ningún aviso de error, así
que revisa bien el nombre exacto antes de guardar.

**Bloques de contenido.** Además de los marcadores de texto, hay marcadores especiales que
insertan contenido más complejo: la tabla de ítems de la factura, la galería de fotos de la
instalación, las imágenes de firma del cliente y del técnico, y el **logo de la empresa**
(disponible en los tres documentos, incluido el contrato — usa el marcador de logo desde
**Configuración → Marca**). Se insertan con botones aparte (tarjetas más grandes, con ícono) —
al hacer clic, el sistema los coloca automáticamente en su propio párrafo para que no queden a
mitad de una frase. Si por alguna razón uno de estos bloques no se pudo insertar donde lo
pusiste, la **Vista previa** te avisa con un mensaje explícito (a diferencia de los marcadores
de texto simples, que se quedan callados). Si no has subido un logo en **Configuración → Marca**,
el marcador simplemente no muestra nada — no es un error.

**Tamaño y orientación de página.** Arriba del editor eliges el tamaño del papel (A4, Carta
u Oficio) y si el documento sale **Vertical** u **Horizontal**. Cada documento tiene su
propia configuración: puedes dejar la factura en vertical y el contrato en horizontal.

Usa **Horizontal** si tu diseño es a dos columnas. Es el caso típico del contrato de
servicio en Colombia (el formato de la CRC, con las cláusulas repartidas en dos columnas por
página): ese diseño necesita más ancho del que cabe en una hoja vertical, y si lo dejas en
vertical el PDF sale con las columnas aplastadas y el texto descuadrado. Si el documento se
te ve apretado o cortado por los lados, esto es casi siempre la causa.

La vista previa usa lo que tengas seleccionado en ese momento, aunque todavía no hayas
guardado — así puedes probar vertical y horizontal antes de decidir.

**El PDF de verdad, al lado del editor.** A la derecha del editor tienes un panel titulado
**"PDF real"**. No es una aproximación: es el mismo PDF que se le va a enviar al cliente,
generado con datos de ejemplo. Se actualiza solo un par de segundos después de que dejas de
escribir, y también al cambiar el tamaño, la orientación o el modo. Mientras se regenera sigues
viendo el anterior, así que puedes comparar el antes y el después de un cambio.

Ésta es la respuesta definitiva a "en el editor se ve bien pero el PDF sale raro": si algo se ve
distinto entre los dos paneles, **manda el de la derecha**. El editor es una ayuda para escribir
cómodo; el PDF es lo que se imprime. Puedes ocultarlo con **"Ocultar el PDF"** si prefieres el
editor a pantalla completa, y **"Actualizar ahora"** lo regenera sin esperar.

El botón **Abrir el PDF aparte** lo abre en otra pestaña (útil para verlo en grande o
descargarlo), y **Restaurar** vuelve a la plantilla original (tu borrador no se pierde, puedes
reactivarlo guardando de nuevo).

**Empezar desde una plantilla base.** Arriba del editor hay una fila de botones con plantillas
ya armadas: el formato que el sistema usa por defecto para cada documento (factura, contrato,
acta de instalación) y los **formatos regulados de cada país** para el contrato:

| País | Plantilla | Qué trae |
|---|---|---|
| — | Genérico · Contrato básico | Sin formato regulado de ningún país |
| Colombia | Contrato único CRC | Dos columnas, se abre en horizontal |
| México | Contrato de adhesión (IFT) | Carta de Derechos del IFT, velocidad mínima garantizada. Se abre en tamaño Carta |
| Argentina | Servicios TIC (ENACOM) | Baja por el mismo medio de contratación, bonificación automática, aviso de 30 días |
| Perú | Contrato de abonado (OSIPTEL) | Velocidad mínima garantizada del 40 %, apelación ante el TRASU |
| Chile | Suministro de internet (SUBTEL) | Velocidad promedio garantizada, descuento de oficio por indisponibilidad |
| Bolivia | Prestación de internet (ATT) | Derechos del usuario Ley 164, compensación por interrupciones |

Al hacer clic se cargan en el editor con su tamaño y orientación de página correctos,
listas para que las edites. **No se guardan solas**: quedan como borrador hasta que le des a
"Guardar y activar", y si ya tenías contenido escrito te pregunta antes de reemplazarlo.

> Son un punto de partida con la **estructura** del formato, no asesoría jurídica ni una
> certificación de cumplimiento. Revísalas y complétalas con las condiciones de tu empresa
> (tarifas de reconexión, medios de atención, permanencia) antes de usarlas con clientes reales.

**Modo avanzado.** Un interruptor arriba del editor cambia a un modo donde editas el
documento HTML completo (incluyendo el diseño y los colores, no sólo el texto) en un cuadro
de texto plano en vez del editor visual — pensado para quien sabe HTML/CSS y quiere
control total sobre el diseño.

**El editor te muestra la hoja de verdad.** Lo que ves en el editor es una hoja del tamaño y la
orientación que elegiste arriba, sobre fondo gris, con **líneas rojas horizontales donde va a
cortar cada página** del PDF. Si tu diseño es más ancho que la hoja, lo ves salirse ahí mismo y
aparece un aviso en rojo con los números exactos ("necesita 950 px y A4 vertical sólo deja 703
px") y un botón para cambiar a horizontal. Esa es la causa más común de que un PDF salga con
los textos y las cajas montados unos sobre otros: el diseño no cabe a lo ancho y el generador
de PDF, en vez de encogerlo, lo deja desbordarse sobre la columna de al lado.

**El logo se ve puesto en su sitio, no como un marcador.** Si ya subiste un logo en
**Configuración → Marca**, el marcador `{{empresa.logo}}` aparece en el editor como la imagen
real, del mismo tamaño con el que va a salir impresa — así ves si queda demasiado grande, mal
alineado o encima de otra cosa sin tener que abrir el PDF. La plantilla sigue guardando el
marcador, no la imagen: el día que cambies de logo, los documentos salen con el nuevo
automáticamente. Si todavía no has subido ninguno, se queda como texto, que es lo honesto —
en el PDF tampoco saldría nada.

**Las imágenes de internet salen marcadas en rojo.** Si tu plantilla tiene una imagen enlazada
a una dirección `https://`, el editor la muestra semitransparente y con un borde rojo punteado:
es un recordatorio de que **en el PDF no va a aparecer**. Sube el logo en "Marca en los
documentos" y usa `{{empresa.logo}}`. Si necesitas otra imagen (un sello, una firma escaneada),
pégala **incrustada** en el HTML (una imagen `data:` en formato PNG, JPG o GIF): ésas sí se
imprimen.

**Si usas una fuente que el PDF no tiene, te avisa.** El generador de PDF no tiene instaladas
las fuentes de tu computador: sólo conoce unas pocas (Times, Helvetica, Courier, DejaVu Sans,
DejaVu Serif y las genéricas `serif` / `sans-serif` / `monospace`). Una plantilla copiada de
Word suele venir con Calibri o Arial, que en el editor se ven bien y en el PDF se reemplazan
por Times — como es más angosta, el texto ocupa distinto y los cortes de página se mueven. El
arreglo es de una línea: deja tu fuente pero agrega una de las conocidas al final, por ejemplo
`font-family: Calibri, Arial, sans-serif`.

**Si tu plantilla es un diseño propio, el modo avanzado no es opcional.** El editor visual es
un navegador y te muestra tu documento perfecto, pero al generar el PDF el modo normal
**elimina los anchos, los colores, los estilos y las imágenes** y mete lo que queda dentro de la
plantilla base del sistema — sale un PDF con otro diseño. Medido sobre un contrato real: en modo
avanzado sobrevive el 95 % del documento, en modo normal el 51 %, y de ese 51 % se pierde todo lo
que sostiene la maquetación. Por eso, si intentas previsualizar un diseño propio con el modo
avanzado apagado, el sistema te lo advierte y te ofrece activarlo.

> **Cuidado con las llaves de los marcadores.** Tienen que ser exactamente dos a cada lado:
> `{{plan.valor_mensual}}`. Si le falta una (`{{plan.valor_mensual}`) o tiene un espacio raro
> dentro, el sistema no lo reconoce como marcador y **lo imprime tal cual en el PDF** — no sale
> en blanco, sale el texto con las llaves. El editor te lo avisa.

**El interruptor no borra lo que escribiste.** Es la misma plantilla vista de dos formas: en
modo normal la ves como va a quedar en el PDF, en modo avanzado ves su código. Puedes ir y
volver las veces que quieras. Lo que sí cambia es **cómo se guarda**: el modo normal sólo
admite texto con formato básico (negritas, listas, colores, enlaces), porque lo que escribes se
inserta dentro de la plantilla base del sistema; las tablas, imágenes y estilos propios los
elimina al guardar. Si tu contenido los usa, el editor te lo avisa en rojo y te ofrece activar
el modo avanzado para conservarlo tal cual. El sistema sigue revisando el contenido por seguridad (nunca
se guarda código que pueda ejecutar algo en el navegador de quien lo abra), así que no todo lo
que escribas va a sobrevivir tal cual — usa **Vista previa** para confirmar antes de guardar.
Si no sabes HTML/CSS, no actives este modo: no hay ayuda visual todavía, es edición de código.
Admite estilo en línea (`style="..."`) e identificadores (`id="..."`, para selectores CSS del
tipo `#nombre{...}`) en prácticamente cualquier elemento — útil si pegas HTML exportado de otro
sistema. Si vienes de otra plataforma (ej. WispHub), revisa los nombres de marcador: los
marcadores no son compatibles entre sistemas, tienes que reemplazarlos por los de ISPwatch
(los ves en el panel de marcadores disponibles) — un marcador con un nombre que ISPwatch no
reconoce simplemente no muestra nada.

**El sistema te avisa qué marcadores no reconoce.** Al darle a **Vista previa** o a **Guardar
y activar**, si tu plantilla trae marcadores que ISPwatch no entiende aparece un recuadro
amarillo debajo del editor con la lista: cada marcador, por qué no funciona, y cuál es el
equivalente aquí cuando lo hay. No bloquea nada — el documento se genera igual — pero es la
forma rápida de saber por qué un dato sale en blanco. También detecta las imágenes enlazadas
a una dirección de internet, las fuentes que el PDF no tiene y los marcadores de otro tipo de
documento (un `{{factura.…}}` pegado dentro de un contrato, por ejemplo). Con el panel del
**PDF real** abierto se refresca solo cada vez que se regenera, sin que tengas que pulsar nada.

> **Si tu plantilla venía de antes del 6 de agosto de 2026 y se ve con otra letra o con otro
> tamaño de texto:** hasta esa fecha, al guardar se descartaban en silencio las reglas de
> estilo que aplicaban al documento entero (las que empiezan por `body` o `html`), que es donde
> las plantillas exportadas de Word ponen su tipografía base. Ya no pasa, pero lo que se
> descartó entonces no se puede recuperar: vuelve a pegar el HTML original y guarda de nuevo.

**Si pegaste una plantilla de WispHub**, esta es la equivalencia de marcadores. Es el error
más común al migrar: el HTML se ve bien pero los datos salen en blanco, porque los nombres
no coinciden.

| WispHub | ISPwatch |
|---|---|
| `{{ cliente_nombre }}` | `{{cliente.nombre}}` |
| `{{ cliente_apellidos }}` | `{{cliente.apellido}}` |
| `{{ cliente.user.email }}` | `{{cliente.email}}` |
| `{{ plan_internet.nombre }}` | `{{plan.nombre}}` |
| `{{ plan_internet.precio }}` | `{{plan.valor_mensual}}` |
| `{{ fecha_instalacion }}` | `{{contrato.fecha}}` |
| `{{cliente.localidad}}` | `{{cliente.departamento}}` |
| `{{cliente.ciudad}}` | `{{cliente.ciudad}}` (igual) |
| `CO-NUMERO_CONTRATO_TAG` | `{{contrato.numero}}` |
| `<img src="FIRMA_CLIENTE_NO_BORRAR">` | `{{contrato.firma_cliente}}` |
| Logo con una dirección de internet (`https://…`) | `{{empresa.logo}}` |

Dos detalles que no son evidentes:

- **El número de contrato ya trae el prefijo.** `{{contrato.numero}}` incluye el prefijo que
  configuraste en **Configuración → Marca**. Si escribes `CO-{{contrato.numero}}` te va a
  salir el prefijo dos veces.
- **El logo tiene que ser el marcador, no una imagen de internet.** Una imagen enlazada a
  una dirección externa (`https://…`) nunca se descarga al generar el PDF: sale rota. Sube
  el logo en **Configuración → Marca** y usa `{{empresa.logo}}`.

Los espacios dentro de las llaves dan igual en todos los marcadores:
`{{contrato.firma_cliente}}` y `{{ contrato.firma_cliente }}` funcionan igual. (Hasta el
2026-08-06 los bloques —logo y firma— exigían escribirse sin espacios y desaparecían sin
avisar si los tenían; ya no.)

> ⚠️ **Importante si pegas HTML de otro sistema: no metas textos largos dentro de una celda de
> tabla.** El generador de PDF no sabe partir una celda entre dos páginas: si el texto de una
> celda no cabe en una hoja, **lo que sobra no se imprime** — sin ningún aviso. En un contrato
> eso significa perder cláusulas. Para bloques largos (condiciones, tratamiento de datos,
> cláusulas) usa `<div>` en lugar de `<table>`: el texto fluye solo de una página a la siguiente.
> Las tablas están bien para lo que son: filas de datos cortas.
>
> Las alturas fijas (`height="..."`) que dejan algunos editores visuales se descartan
> automáticamente, porque en el PDF sólo producen páginas en blanco. Los anchos (`width="..."`)
> sí se respetan.

**Número consecutivo de los contratos.** En el bloque de marca de esa misma pestaña hay un
campo **Prefijo del consecutivo de contratos**. Cada contrato que se firma desde el sistema
recibe un número irrepetible con ese prefijo. Si lo dejas vacío se usa `CTR`.

**Escribe el prefijo que quieras**: letras, números, acentos, barras, puntos, espacios. Estos
son todos válidos:

| Si escribes | Los contratos quedan |
|---|---|
| `CTR` | `CTR-00001` |
| `FIBRAX` | `FIBRAX-00001` |
| `CNO/` | `CNO/00001` |
| `Contrato N° ` | `Contrato N° 00001` |
| `FIBRA_2026.` | `FIBRA_2026.00001` |

El guion lo pone el sistema **solo si tu prefijo termina en letra o número**. Si terminas en
`/`, `.`, `_` o un espacio, se respeta ese separador tuyo y no se agrega nada más. Así puedes
dejar el formato exactamente como lo usas en papel.

Lo único que no se admite son saltos de línea. Y ojo: el **nombre del archivo** que se descarga
sí se simplifica, porque algunos símbolos no son válidos en un nombre de archivo — un contrato
`Contrato N° 00001` se guarda como `contrato_Contrato-N-00001.pdf`, y `CNO/00001` como
`contrato_CNO-00001.pdf`. Lo que ve el cliente **dentro** del documento es tu formato completo,
sin tocar.

Debajo del campo verás cuál es el **próximo número** que se va a asignar. Ese número:

- Se imprime dentro del PDF, en el encabezado (*Contrato No. …*).
- Da nombre al archivo (`contrato_CTR-00001.pdf`) y aparece en la pestaña **Documentos**
  del cliente.
- Es independiente para cada empresa y no se repite nunca.

Cambiar el prefijo **no renumera los contratos ya firmados**: los anteriores conservan el
número con el que se firmaron, y los nuevos siguen la cuenta desde donde iba. Los contratos
que subes tú a mano (un PDF escaneado, por ejemplo) no reciben número, porque el sistema no
puede escribir dentro de un archivo que no generó él.

> Esta pestaña necesita el permiso *Gestionar Plantillas de Documentos*, que es distinto
> del de configuración de empresa. Si no la ves, revisa tu rol.

### 17.5 Llaves de API (integraciones externas)

Sirve para que un sistema externo —un CRM, un tablero de indicadores, un proceso de
conciliación contable— pueda **leer** los datos de un ISP sin que nadie tenga que entrar
al panel ni compartir una contraseña.

Hay **dos formas** de conseguir una llave, y la pantalla que ves depende de cuál te
corresponde:

- **Si administras un ISP** → *Configuración → Llaves API* te muestra las integraciones
  de **tu empresa** y puedes emitir tú mismo las llaves, dentro de unos límites. Salta a
  la sección 17.5.1.
- **Si eres del equipo de ISPWatch** (tenant operador) → la misma pestaña te muestra las
  integraciones de **todos los ISP**, sin límites. Es lo que se describe a continuación.

**Qué puede hacer una llave — y qué no**

- Sólo **consultar**. No crea, no modifica y no borra nada.
- Sólo ve **el tenant al que se emitió**. Nunca los datos de otro ISP.
- Nunca devuelve contraseñas PPPoE ni de hotspot, ni las firmas de las actas de instalación.
- No puede tocar los routers.

**Emitir una llave**

1. **Configuración → Llaves API**.
2. En *Nuevo cliente de API*, elige el **tenant**, ponle nombre (por ejemplo «CRM del ISP»)
   y un correo de contacto. Pulsa **Crear cliente**.
3. En la ficha del cliente, pulsa **Emitir llave** y completa:
   - **Nombre de la llave**: para saber cuál es cuál si hay varias (`produccion-crm`).
   - **Vence el**: la fecha en que dejará de funcionar. Déjalo vacío sólo si el
     integrador lo exige; una llave que nadie rota nunca sigue viva cuando el contrato
     ya terminó.
   - **Permisos de lectura**: marca sólo las áreas que la integración necesita
     (Clientes, Facturación, Soporte). Si sólo va a conciliar pagos, no le des Soporte.
   - **IPs autorizadas**: obligatorio. Son las direcciones del servidor desde el que se
     va a consumir la API. Acepta una IP suelta (`190.24.7.10`) o un rango
     (`190.24.8.0/24`), separadas por coma o por líneas. Si la llave se filtra, desde
     cualquier otro sitio no sirve.
4. Pulsa **Emitir**.

> ⚠️ **La llave se muestra una sola vez.** Cópiala en ese momento y entrégala por un
> canal seguro. El sistema no la guarda: sólo guarda una huella para poder verificarla.
> Si se pierde, no hay forma de recuperarla — se revoca y se emite otra.

**Vigilar el uso**

La tabla de cada cliente muestra, por llave: los permisos, las IPs autorizadas, cuándo se
usó por última vez y desde qué dirección. Si ves un último uso desde una IP que no
esperabas, o una llave que lleva meses sin usarse, revócala.

**Revocar**

Pulsa **Revocar** en la llave. El corte es inmediato: la siguiente petición del integrador
falla. La llave sigue apareciendo tachada en el listado, porque el registro de quién
consultó qué tiene que poder seguir nombrándola.

**Apagar todo de golpe**

El botón **Desactivar** del cliente apaga **todas** sus llaves a la vez, sin borrarlas.
Es lo que hay que usar ante una sospecha: se corta primero y se investiga después,
y volver a activarlo restablece las llaves que no se revocaron.

**Qué decirle al integrador**

Que la llave viaja en la cabecera `Authorization: Bearer <llave>` y que empiece probando
con `GET /api/v1/partner/ping`, que le confirma que la llave funciona, desde qué IP lo
está viendo el servidor y qué permisos tiene. La referencia completa está en
`docs/API_REFERENCE.md`, sección 22.

#### 17.5.1 Emitir tus propias llaves (auto-servicio)

Si administras un ISP, **Configuración → Llaves API** te deja crear las llaves de tu
empresa sin pedírselas a nadie. Sólo alcanzan a tus datos: no hay forma de emitir una
llave que vea otra empresa, ni siquiera equivocándose.

Arriba de la pantalla verás cuatro contadores —llaves vigentes, integraciones, vigencia
máxima y rango de IP más amplio—. Están ahí para que sepas los límites **antes** de
llenar el formulario, no después de que te lo rechace.

**Paso a paso**

1. En *Nueva integración*, ponle nombre (por ejemplo «Bot de WhatsApp») y un correo de
   contacto. Pulsa **Crear integración**.
2. Pulsa **Emitir llave** y completa:
   - **Nombre de la llave**: para distinguirla si tienes varias (`produccion-bot`).
   - **Vence el**: obligatorio, y como máximo 90 días. Cuando venza, emites otra —es un
     minuto de trabajo y evita que una llave siga viva cuando ya cambiaste de proveedor.
   - **Permisos de lectura**: marca sólo lo que la integración necesite.
   - **IPs autorizadas**: la IP pública del servidor donde corre la integración.
3. **Copia la llave en ese momento.** Sólo se muestra una vez.

**Los límites, y por qué existen**

| Límite | Valor | Motivo |
|---|---|---|
| Vigencia | 90 días máximo | Una llave sin caducidad no la rota nadie |
| Rango de IP | hasta `/24` | Impide dejarla abierta a todo internet |
| Llaves vigentes | 5 | Una llave olvidada se nota al chocar con el tope |
| Integraciones | 3 | Igual que arriba |
| Facturación | no disponible | Ver facturas y pagos se pide al operador |

> **Sobre las IPs.** Es normal pelearse con un `403` al principio, y la tentación es
> ensanchar la lista hasta que funcione. No lo hagas: esa lista es justamente lo que
> hace que una llave filtrada no le sirva a nadie fuera de tu servidor. Si no sabes qué
> IP poner, llama a `GET /api/v1/partner/ping` con la llave: la respuesta te dice desde
> qué IP te está viendo el servidor.

**Ver qué está pasando**

El botón **Ver peticiones** de cada integración muestra las últimas llamadas con su
fecha, ruta, IP, código de estado y motivo del rechazo. Casi cualquier problema se
resuelve ahí: `ip_not_allowed` es una IP que falta en la lista, y `key_expired` es una
llave que hay que reemplazar.

**Si necesitas algo fuera de estos límites** —acceso a facturación, una vigencia más
larga o un rango de IP más ancho— pídeselo al equipo de ISPWatch, que emite esa llave
por el otro camino.

### 17.7 Auditoría

**Configuración → Auditoría.** Requiere el permiso *Ver Bitácora de Auditoría*.

Registra **todo lo que mueve plata**: precio de un plan, cambio de plan de un cliente, pagos,
exclusión de facturación y configuración de facturación (días y horas de factura, corte y
recordatorio). De cada cambio guarda **quién, cuándo, el valor anterior y el nuevo**.

La columna **Origen** es la que más suele hacer falta:

| Origen | Significa |
|---|---|
| Panel | Alguien lo cambió desde la pantalla |
| Carga masiva | Entró por una importación de Excel |
| Automático | Lo hizo el sistema (facturación, cortes) |
| Consola | Un comando de mantenimiento |

Es la diferencia entre «un operador subió el precio» y «lo cambió un archivo que alguien
importó», que es justo lo que no se podía distinguir antes.

Puedes filtrar por tipo de registro, acción, origen y rango de fechas, y buscar texto en la
descripción. **Ver detalle** muestra los valores exactos antes y después.

La bitácora es **solo lectura**: no se puede editar ni borrar desde el sistema, a propósito.
Solo ves lo de tu sede.

---

## 18. Acciones masivas

**Acciones masivas.** Reúne las operaciones que afectan a muchos registros a la vez.

### 18.1 Carga masiva de clientes

1. Pulsa **Descargar plantilla**. Baja un Excel con varias hojas: clientes, planes, routers
   y sectoriales.
2. Llena el Excel. La opción **Ver documentación de campos** explica qué va en cada columna.
3. Súbelo con **Importar**.
4. Si hay errores, el sistema los lista y puedes **descargarlos en Excel** para corregirlos
   y volver a subir sólo lo que falló.

> Los planes, routers y sectoriales se crean **por nombre**: si escribes un nombre que no
> existe, se crea; si ya existe, se reutiliza. **Cuida las mayúsculas y los espacios.**

### 18.2 Actualización masiva de clientes

Mismo flujo, pero con una plantilla que **modifica** clientes existentes en vez de crearlos.

### 18.3 Importar inventario

Igual, para equipos. Cada fila es un equipo con su serial y su MAC.

### 18.4 Aprovisionamiento masivo

Carga a los routers a varios clientes de golpe. Como cada cliente tarda alrededor de medio
minuto, el proceso corre en segundo plano y verás una **barra de progreso**. Puedes cerrar la
pantalla y seguir trabajando: no se cancela.

> Vale lo mismo que en el alta individual (5.2): los routers que tengan **apagada** el alta
> automática se saltan, y los clientes sin router, plan o IP no se pueden aprovisionar.

### 18.5 Paneles de reintentos

Los dos paneles de bitácora explicados en la [sección 9.3](#93-ver-qué-se-cortó-y-qué-falló).

---

## 19. Preguntas frecuentes

**No veo una sección del menú.**
Tu rol no tiene el permiso. Pide a un administrador que lo revise en **Roles**.
Si es un permiso recién creado, además tendrás que cerrar sesión y volver a entrar.

**Un cliente no recibió su factura este mes.**
Revisa en este orden:
1. ¿El **router** del cliente tiene configuración de facturación asignada?
2. ¿Ya pasó el **día y la hora de creación** configurados en ese router?
3. ¿El cliente tiene un **servicio activo** con un plan que no sea de cortesía?
4. ¿Está marcado como **"No facturar a este cliente"**?
5. ¿Está **retirado** o **cancelado**? A esos no se les factura nunca (al *suspendido* sí).
6. ¿Ya llegó al tope de **"Dejar de facturar al moroso"**? Al alcanzarlo la deuda se congela
   y no se emiten facturas nuevas. Ver [7.1](#71-cómo-funciona-esto-es-lo-más-importante-del-sistema).
7. ¿Es su **primer mes** y quedó en *No facturar* o con **meses de cortesía**? Ver [5.2](#52-crear-un-cliente).
8. ¿Alguien **eliminó** esa factura? Si es así, no se regenera.

**No se generó ninguna factura de ningún cliente.**
Eso ya no es configuración, es que el proceso automático no corrió. El sistema tiene una
revisión diaria que detecta exactamente eso y avisa por correo. Es cosa de soporte técnico.

**Guardé el cliente pero no se cargó al router.**
Revisa, en este orden: (1) que el router tenga activada el **alta automática**, (2) que el
cliente tenga **router, plan e IP**, (3) que el **túnel VPN** del equipo esté arriba. Después
entra a la ficha del cliente y usa el botón de **aprovisionar**. Si insiste, pide a soporte
técnico que pruebe la conexión (ficha del router → *Probar conexión SSH*).

**Corté a un cliente pero sigue navegando.**
Es el problema más común y tiene tres causas típicas: el **túnel VPN del router está caído**,
las **reglas de bloqueo no están o quedaron muy abajo** en el equipo, o el cliente tenía
conexiones abiertas de antes. Ve a **Gestión → Lista de Routers**, entra al equipo, pulsa
**Verificar VPN** y luego **Aplicar reglas de bloqueo** (esto último también las vuelve a
subir al primer lugar). Después, en **Acciones masivas**, pulsa **Reconciliar**.
El procedimiento completo está en [9.4](#94-el-cliente-aparece-cortado-pero-sigue-navegando).

**No se cortó ningún cliente de un router entero.**
Sospecha del **túnel VPN** antes que de nada. Sin túnel, ISPWatch no le puede dar órdenes al
equipo, pero igual marca a los clientes como cortados. Ficha del router → **Verificar VPN**.

**Las reglas de bloqueo están instaladas y aun así no bloquean.**
El router lee sus reglas de arriba hacia abajo y se queda con la primera que coincide: si las
de ISPWatch quedaron debajo de una que deja pasar el tráfico, nunca se ejecutan. Pulsa
**Aplicar reglas de bloqueo** — las sube de nuevo al primer lugar. Es seguro repetirlo.

**El cliente pagó y sigue cortado.**
La reconexión automática sólo funciona con cortes por facturación y sólo si el cliente quedó
**completamente** al día. Si aún debe una factura anterior, sigue cortado. Revisa su saldo
en la pestaña **Facturación** de su ficha.

**No encuentro un cliente al buscarlo.**
Ya no es problema de mayúsculas: eso se corrigió. Revisa que estés buscando por un campo que
esa pantalla mire (nombre, cédula, IP o correo en la lista de clientes) y que el cliente no
esté filtrado por estado. Si lo borraron, no aparece: los clientes eliminados no se recuperan.

**No puedo subir varias fotos de instalación.**
Ya puedes seleccionarlas todas juntas; el sistema las comprime y las sube una por una solo.
Si aun así falla, casi siempre es una foto que se pasa de **10 MB** o que no es JPG/PNG/WEBP.

**Creé el cliente y no tiene internet.**
Lo más probable: el router tiene **apagada el alta automática** (*Agregar cliente a MikroTik*),
así que el cliente se guardó pero nunca se cargó al equipo. Revísalo en la ficha del router y
después usa el botón de **aprovisionar** en la ficha del cliente. Ver [5.2](#52-crear-un-cliente).

**Borré un cliente y sigue navegando.**
Eliminarlo del sistema **no lo borra del router**. Hay que sacarlo del equipo aparte. Para la
próxima: suspéndelo primero, confirma que quedó cortado, y después bórralo. Ver [5.5](#55-eliminar-un-cliente).

**El sistema me dice que espere / que hay demasiadas peticiones.**
Las operaciones que tocan los routers están limitadas a propósito (unas diez por minuto, y
menos para las cargas masivas) para no tumbar los equipos. Espera un minuto y sigue.

**Borré una factura por error.**
Tendrás que crearla manualmente. El sistema **no la regenera** a propósito, para no
resucitar facturas que un administrador decidió eliminar.

**¿Puedo cobrar medio mes al cliente que entra a mitad de mes?**
Sí: en su ficha, opción de primera factura **Prorrateado**. También puedes configurarlo en
el plan para que aplique a todos los clientes que lo contraten.

**Cargué al cliente y salió la factura de instalación, pero no la del servicio.**
Le pasaba a los clientes creados antes de agosto de 2026: la del servicio esperaba al día
de facturación del router, y si el router no tenía ese día configurado no llegaba nunca.
Hoy la factura del servicio sale al guardar el cliente. Para los que quedaron sin ella,
pídele a soporte que la emita (`billing:first-invoice`), y **revisa que el router tenga
configurado el día de facturación**: sin él, ninguno de sus clientes recibirá la
mensualidad del mes siguiente.
