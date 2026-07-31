# MANUAL DE USUARIO — ISPWatch

> Guía paso a paso para el uso diario del sistema. Escrita en lenguaje sencillo,
> sin conocimientos técnicos previos.
> Si eres desarrollador, busca [`MANUAL_DESARROLLADOR.md`](MANUAL_DESARROLLADOR.md).

**Última actualización:** 2026-07-31

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

- **Clientes totales** y **clientes activos**.
- **Sectoriales** y **routers** registrados.
- **Routers con falla general**: si un equipo está marcado en falla masiva, aparece aquí
  con su nombre e IP. Es la alerta más importante del panel.
- **Tickets abiertos** y **tickets urgentes**.
- **Ingresos del mes**: suma de las facturas ya pagadas este mes.
- **Pagos recibidos en el mes**.

---

## 5. Clientes

### 5.1 Ver la lista de clientes

**Usuarios → Lista de usuarios.**

Verás la tabla con todos tus clientes. Puedes:

- **Buscar** por nombre, cédula, IP o correo usando la barra de búsqueda.
- **Ordenar** haciendo clic en el encabezado de una columna.
- **Pasar de página** con los controles de abajo.

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

> **La carga al router tarda entre 17 y 34 segundos.** Es normal. Si ves un error de
> "tiempo agotado" pero el cliente quedó creado, usa después el botón de aprovisionar
> desde la ficha del cliente.

### 5.3 Editar un cliente

En la lista, pulsa el icono de **editar**. Verás el mismo formulario con los datos actuales,
más unas pestañas adicionales:

| Pestaña | Contenido |
|---|---|
| **Facturación** | Facturas del cliente, saldo y saldo a favor |
| **Documentos** | Cédula, contrato y otros archivos |
| **Instalaciones** | Historial de instalaciones |
| **Tickets** | Tickets de soporte del cliente |

### 5.4 Suspender o activar un cliente

Desde la ficha del cliente, con los botones **Suspender** y **Activar**.
Esto actúa **de verdad sobre el router**: al suspender, el cliente deja de navegar.

> Necesitas el permiso *Activar y Desactivar Clientes*.

### 5.5 Eliminar un cliente

En la lista, icono de **eliminar**. El sistema pedirá confirmación.
Se borran también su perfil, sus facturas y sus documentos.

> **Piénsalo dos veces.** Si el cliente sólo se retiró, es mejor desactivarlo que borrarlo:
> así conservas su historial de pagos.

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
2. **Sube fotos**.
   > **Sube las fotos de una en una.** Si intentas subir varias a la vez el sistema puede
   > fallar. El sistema las comprime solo antes de enviarlas.
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
  pendientes que tenga el cliente en ese momento.

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

> **La búsqueda distingue mayúsculas.** Si buscas "eliud" y el cliente está guardado como
> "Eliud", puede que no aparezca en algunas pantallas. Prueba con la inicial en mayúscula.

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

Deja de navegar, pero **sí puede entrar al portal de pago** de tu empresa. Ese acceso
queda abierto a propósito, para que pueda pagar y reconectarse.

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

> ⚠️ **Antes de usar el corte automático, aplica las reglas de bloqueo al router.**
> Sin ellas el sistema marca al cliente como cortado pero el cliente sigue navegando.

### 11.5 Historial de tráfico

Si activas **Historial de tráfico** en el router, el sistema toma una medición cada
5 minutos y guarda el consumo diario. Se consulta desde la ficha del router.

### 11.6 Falla masiva

Cuando un nodo se cae y afecta a muchos clientes:

1. Entra a **Gestión → Lista de Routers**.
2. Pulsa **Reportar falla masiva** en el router afectado.
3. Cuando se restablezca, pulsa **Marcar como resuelta**.

El router afectado aparece resaltado en el Dashboard y el aviso se difunde a los clientes
por WhatsApp a través del sistema conectado.

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

En **Roles** puedes crear roles a medida. Los permisos están agrupados:

| Grupo | Ejemplos |
|---|---|
| **Clientes** | Ver lista, agregar, editar servicio, activar/desactivar, editar descuento |
| **Facturas** | Ver estadísticas, buscar facturas, registrar pagos, eliminar factura |
| **Contabilidad** | Editar gasto, ver gastos, ver facturas, editar fecha de pago |
| **Infraestructura** | Gestionar routers, ver planes, ver sectoriales |
| **Inventario** | Ver inventario |
| **Soporte** | Ver soporte técnico |
| **Facturación** | Ver facturación |
| **Sistema** | Ver personal, gestionar roles, configuración de empresa, plantillas de documentos, ajustes, acciones masivas |

> ⚠️ **Muy importante:** cuando el sistema estrena un permiso nuevo, **los roles que ya
> existían no lo reciben solos**. Si tras una actualización una pestaña desaparece para los
> administradores, ve a **Roles**, marca el permiso nuevo, guarda, y pide a los usuarios
> afectados que **cierren sesión y vuelvan a entrar**.

### 16.3 Refrescar permisos sin cerrar sesión

Si un administrador te acaba de cambiar el rol, recarga la página. El sistema vuelve a
consultar tus permisos.

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
reemplaza por datos reales (nombre del cliente, plan, monto...). El botón **Vista previa**
te muestra cómo queda con datos de ejemplo, y **Restaurar** vuelve a la plantilla original.

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

Carga a los routers a varios clientes de golpe. Como cada cliente tarda unos 20–30 segundos,
el proceso corre en segundo plano y verás una **barra de progreso**.
Puedes seguir trabajando mientras tanto.

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
2. ¿Ya pasó el **día de creación** configurado en ese router?
3. ¿El cliente tiene un **servicio activo** con un plan que no sea de cortesía?
4. ¿Está marcado como **"No facturar a este cliente"**?
5. ¿Alguien **eliminó** esa factura? Si es así, no se regenera.

**Guardé el cliente pero no se cargó al router.**
Suele ser tiempo de espera agotado, no un error de datos. Entra a la ficha del cliente y
usa el botón de aprovisionar. Si insiste, pide a soporte técnico que revise la conexión al
router (menú del router → *Probar conexión SSH*).

**Corté a un cliente pero sigue navegando.**
Casi siempre es porque el router **no tiene las reglas de bloqueo instaladas**.
Ve a **Gestión → Lista de Routers**, entra al equipo y pulsa **Aplicar reglas de bloqueo**.
Después, en **Acciones masivas**, pulsa **Reconciliar**.

**El cliente pagó y sigue cortado.**
La reconexión automática sólo funciona con cortes por facturación y sólo si el cliente quedó
**completamente** al día. Si aún debe una factura anterior, sigue cortado. Revisa su saldo
en la pestaña **Facturación** de su ficha.

**No encuentro un cliente al buscarlo.**
La búsqueda distingue mayúsculas en algunas pantallas. Prueba escribiendo el nombre con la
inicial en mayúscula.

**No puedo subir varias fotos de instalación.**
Súbelas de una en una. Es una limitación conocida.

**Borré una factura por error.**
Tendrás que crearla manualmente. El sistema **no la regenera** a propósito, para no
resucitar facturas que un administrador decidió eliminar.

**¿Puedo cobrar medio mes al cliente que entra a mitad de mes?**
Sí: en su ficha, opción de primera factura **Prorrateado**. También puedes configurarlo en
el plan para que aplique a todos los clientes que lo contraten.
