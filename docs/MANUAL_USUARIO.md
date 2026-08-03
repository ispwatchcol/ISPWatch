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
| **Facturación** | Facturas del cliente, saldo y saldo a favor |
| **Documentos** | Cédula, contrato y otros archivos |
| **Instalaciones** | Historial de instalaciones |
| **Tickets** | Tickets de soporte del cliente |

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

En la lista, icono de **eliminar**. El sistema pedirá confirmación.
Se borran también su perfil, sus facturas y sus documentos.

> ⚠️ **Eliminar al cliente NO lo saca del router.** El sistema lo borra de la base de datos,
> pero su configuración se queda en el equipo y **el cliente sigue navegando** — sólo que ya
> no aparece en ninguna pantalla, así que nadie se entera. Si de verdad quieres cortarle el
> servicio: **suspéndelo primero** (5.4), comprueba que quedó cortado, y **después** bórralo.

> **Piénsalo dos veces.** Si el cliente sólo se retiró, es mejor desactivarlo que borrarlo:
> así conservas su historial de pagos y el sistema deja de facturarle igual.

### 5.6 Ver el mapa y las estadísticas

- **Usuarios → Mapa de usuarios**: cada cliente aparece como un punto. Se ven también las
  antenas con su radio de cobertura.
- **Usuarios → Estadísticas**: totales, distribución por plan y por estado.

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

1. **Llena el acta**: equipos instalados, observaciones, mediciones.
2. **Sube fotos**. Puedes seleccionarlas **todas juntas**: el sistema las comprime en el
   teléfono y las va enviando **una por una** por su cuenta, para que no se caiga la subida.
   Verás el progreso mientras trabaja.
   > Antes había que subirlas de a una a mano porque varias juntas hacían fallar la subida.
   > Eso se corrigió; ya no hace falta.
   >
   > Cada foto puede pesar hasta **10 MB** y ser **JPG, PNG o WEBP**. Si una foto no cumple,
   > el sistema la rechaza y te lo dice.
3. **Registra el cobro**: costo de instalación, cargos adicionales, descuento (con motivo),
   forma de pago y cuánto recibió.
4. **Recoge las firmas**: la del cliente y la del técnico, dibujadas en pantalla.

Al completar la instalación se genera automáticamente la **factura de instalación**.

### 6.3 Convertir el prospecto en cliente

1. Crea el cliente normalmente (**Usuarios → Agregar usuario**).
2. Vuelve al prospecto y pulsa **Marcar como convertido**, eligiendo el cliente creado.

El prospecto queda enlazado al cliente y su estado pasa a **convertido**.

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

### 7.2 Ver y buscar facturas

**Finanzas → Facturación.**

Puedes filtrar por estado, cliente y fechas. Los estados son:

| Estado | Significa |
|---|---|
| **Borrador** | Creada pero no emitida |
| **Emitida** | Enviada al cliente, pendiente de pago |
| **Parcial** | Tiene un abono, falta saldo |
| **Pagada** | Cancelada por completo |
| **Vencida** | Pasó la fecha de pago sin pagarse |
| **Anulada** | Sin efecto |

El buscador de arriba busca a la vez por **número de factura**, **nombre**, **apellido** y
**correo** del cliente. No distingue mayúsculas: `eliud` encuentra a *Eliud*.

### 7.3 Ver el detalle y descargar el PDF

Pulsa sobre una factura. Verás los ítems, los pagos aplicados y el saldo.
El botón **Descargar PDF** genera la factura con el diseño y los datos de tu empresa.

### 7.4 Crear una factura manual

**Finanzas → Facturación → Nueva factura.** Necesitas indicar cliente, **tipo de
factura**, fecha de emisión, fecha de vencimiento, periodo y total. El número lo asigna
el sistema.

### 7.5 Servicios adicionales

**Finanzas → Servicios adicionales.** Para cobrar algo puntual que no viene de un ticket
(traslado, cambio de equipo, reconexión). También eliges el **tipo de factura** que se va
a emitir.

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

### 7.6 Corregir una factura

- **Editar**: cambia fechas, total o notas.
- **Marcar como no pagada**: revierte los pagos y restaura el saldo. Úsalo si registraste
  un pago por error.
- **Eliminar**: la borra.

> ⚠️ **Al eliminar una factura, el sistema NO la volverá a generar nunca.**
> Deja una marca interna para ese cliente y ese mes. Si la borraste por error, tendrás que
> crearla a mano. El mes siguiente se factura con normalidad.

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

1. Pulsa **Nuevo gasto**.
2. Elige la **categoría** (puedes crearlas en *Categorías de gasto*).
3. Indica **fecha** y **monto**.
4. Opcional: **beneficiario** (el empleado o técnico a cuyo nombre va el gasto; déjalo vacío
   en gastos como arriendo o servicios públicos).
5. Añade descripción y notas.

> **Los gastos no se borran.** Si te equivocaste, edítalo y cámbialo a estado **anulado**.
> Así queda el rastro de la corrección.

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

**Inventarios.** Se organiza en cuatro niveles:

| Sección | Qué guarda |
|---|---|
| **Stock / Modelos** | Los modelos de equipo que manejas (marca, modelo, precio) |
| **Proveedores** | A quién le compras, con datos del asesor comercial |
| **Sucursales** | Dónde están físicamente los equipos |
| **Lista de equipos** | **Cada equipo individual**, con su serial y su MAC |

Un equipo se puede asignar a un cliente.

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
instalación, y las imágenes de firma del cliente y del técnico. Se insertan con botones
aparte (tarjetas más grandes, con ícono) — al hacer clic, el sistema los coloca automáticamente
en su propio párrafo para que no queden a mitad de una frase. Si por alguna razón uno de estos
bloques no se pudo insertar donde lo pusiste, la **Vista previa** te avisa con un mensaje
explícito (a diferencia de los marcadores de texto simples, que se quedan callados).

El botón **Vista previa** te muestra cómo queda con datos de ejemplo, y **Restaurar** vuelve
a la plantilla original (tu borrador no se pierde, puedes reactivarlo guardando de nuevo).

**Modo avanzado.** Un interruptor arriba del editor cambia a un modo donde editas el
documento HTML completo (incluyendo el diseño y los colores, no sólo el texto) en un cuadro
de texto plano en vez del editor enriquecido — pensado para quien sabe HTML/CSS y quiere
control total sobre el diseño. El sistema sigue revisando el contenido por seguridad (nunca
se guarda código que pueda ejecutar algo en el navegador de quien lo abra), así que no todo lo
que escribas va a sobrevivir tal cual — usa **Vista previa** para confirmar antes de guardar.
Si no sabes HTML/CSS, no actives este modo: no hay ayuda visual todavía, es edición de código.

> Esta pestaña necesita el permiso *Gestionar Plantillas de Documentos*, que es distinto
> del de configuración de empresa. Si no la ves, revisa tu rol.

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
