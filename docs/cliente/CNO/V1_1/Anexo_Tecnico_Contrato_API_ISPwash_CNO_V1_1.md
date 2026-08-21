# Anexo técnico — Contrato API ISPwash para ColombiaNetDiagnostico

**Versión:** 1.1  
**Fecha:** 2026-08-10  
**Organización solicitante:** Colombia Net de Occidente S.A.S. — sede Chaguaní  
**Subfase:** 2A — recepción y análisis del contrato oficial

## 1. Propósito

Obtener evidencia oficial suficiente para diseñar una integración segura sin inventar endpoints, nombres de campos, catálogos, eventos, permisos ni semántica operacional de ISPwash.

La entrega del contrato puede realizarse mientras se evalúa la reestructuración funcional del ticket. La integración operativa solo se congelará después de:

1. aprobar el modelo de ticket de soporte;
2. versionar sus campos, catálogos, estados y reglas;
3. recibir y analizar el contrato oficial de la API;
4. acordar el mapeo ISPwash ↔ CNO;
5. superar pruebas de contrato y sandbox.

## 2. Frontera de responsabilidades

| Responsabilidad | Sistema maestro |
|---|---|
| Cliente, contrato, plan y estado comercial | ISPwash |
| Servicio o punto instalado | ISPwash |
| Ticket, consecutivo, SLA, asignación, estado y cierre | ISPwash |
| Correlación técnica e infraestructura | CNO |
| PPPoE, RADIUS y diagnóstico | CNO |
| Evidencia técnica enriquecida | CNO, vinculada al ticket oficial |
| Causa probable automatizada | CNO, como sugerencia |
| Causa y solución confirmadas | ISPwash, validadas por personal autorizado |

CNO no duplicará el ticket maestro ni accederá directamente a bases de datos internas de ISPwash.

## 3. Alcance

Solo se integrarán tickets de **soporte técnico de servicios existentes**.

Fuera de alcance:

- instalaciones y activaciones nuevas;
- traslados y reubicaciones;
- retiros;
- ventas y cambios comerciales;
- cartera y facturación;
- cambios de titular;
- solicitudes administrativas;
- escrituras automáticas en RouterOS, OLT, FreeRADIUS o bases productivas durante la primera fase.

## 4. Artefacto principal solicitado

### 4.1 OpenAPI oficial

- OpenAPI `3.0.x` o `3.1.x`;
- JSON UTF-8;
- versión y fecha de vigencia;
- paths, métodos y `operationId`;
- parámetros, request bodies, respuestas y esquemas;
- códigos HTTP y mecanismos de seguridad;
- sin credenciales ni datos reales.

Para la recepción actual de CNO se prefiere un archivo único de hasta **2 MiB**. Si el contrato oficial supera ese tamaño, se coordinará una entrega segmentada o un ajuste controlado; el tamaño no debe justificar omitir información contractual.

### 4.2 Diccionario de datos obligatorio

El OpenAPI debe acompañarse de un diccionario o documentación equivalente que identifique:

- nombre oficial de cada campo;
- descripción y semántica;
- tipo y formato;
- obligatoriedad y nulabilidad;
- longitud o límites;
- valores permitidos y códigos estables;
- lectura, escritura o cálculo interno;
- historial y versionamiento;
- disponibilidad en sandbox y producción;
- exposición de campos personalizados.

Los nuevos campos que resulten de la reestructuración del ticket no deben quedar únicamente visibles en la interfaz. ISPwash debe confirmar cuáles estarán disponibles por API y cuáles no, indicando alternativa.

## 5. Evidencias oficiales requeridas

### E1. Identidad y versión del contrato

- nombre del producto y módulo;
- versión de API;
- fecha de vigencia;
- política de cambios y deprecación;
- contacto responsable;
- hash SHA-256 del artefacto, si está disponible.

### E2. Ambientes

- sandbox o pruebas;
- producción;
- URL base de cada ambiente;
- diferencias funcionales;
- datos ficticios disponibles;
- proceso de habilitación.

### E3. Autenticación y autorización

- método oficial;
- scopes o permisos;
- expiración y renovación;
- rotación y revocación;
- restricciones por IP;
- encabezados obligatorios;
- separación de credenciales por ambiente.

### E4. Identificadores estables

Confirmar el identificador único e inmutable de:

- organización o tenant;
- cliente o abonado;
- servicio o punto instalado;
- contrato;
- ticket;
- router lógico, servidor o NAS;
- comentario, adjunto e incidente, si aplican.

No se asumirá que nombre, teléfono, IP, usuario PPPoE o consecutivo visible sean identificadores maestros sin confirmación contractual.

### E5. Cliente, servicio y router lógico

Documentar la relación oficial entre:

- cliente;
- servicio;
- contrato y plan;
- usuario PPPoE;
- IP asignada;
- estado comercial y técnico;
- zona o sede;
- router lógico/NAS.

CNO dispone de referencias canónicas como `subscriber_code`, `ispwash_subscriber_id`, `pppoe_username`, `mapping_code` e `ispwash_logical_router_id`; su correspondencia con ISPwash se definirá manualmente después de recibir los nombres oficiales del proveedor.

### E6. Ticket de soporte

Documentar las capacidades reales y su semántica:

- crear;
- consultar;
- listar y filtrar;
- consultar por fecha de actualización o cursor;
- asignar;
- cambiar prioridad;
- cambiar estado;
- agregar comentario;
- adjuntar evidencia;
- registrar intervención;
- registrar causa y solución;
- registrar restablecimiento;
- cerrar y reabrir;
- consultar historial;
- relacionar un incidente padre.

La existencia de una operación en este anexo no implica que Colombia Net solicite habilitarla en el piloto. La primera etapa operativa será de **solo lectura**; las escrituras se autorizarán de manera independiente.

### E7. Campos reestructurados del ticket

Para cada campo funcional solicitado en la Fase 1, ISPwash debe indicar:

- nombre interno;
- código estable;
- tipo;
- catálogo y versión;
- obligatoriedad por estado;
- disponibilidad por API;
- inclusión en historial y auditoría;
- comportamiento al modificar el catálogo;
- permisos de lectura y escritura.

Esto aplica, entre otros, a:

- síntoma;
- alcance e impacto;
- infraestructura;
- causa sospechada y confirmada;
- acción y solución;
- resultado;
- restablecimiento;
- reincidencia;
- incidente padre;
- pruebas iniciales y finales;
- evidencias e intervenciones.

### E8. Webhooks o consulta incremental

- eventos disponibles;
- esquema y versión;
- autenticación o firma;
- reintentos;
- orden y duplicados;
- retención;
- mecanismo de replay;
- alternativa de polling;
- filtros por `updated_since`, cursor o versión;
- reconciliación después de fallas.

### E9. Operación y resiliencia

- paginación;
- rate limits;
- timeouts;
- códigos y estructura de errores;
- idempotencia;
- control de concurrencia;
- reintentos;
- ventanas de mantenimiento;
- disponibilidad esperada;
- soporte técnico de la API.

### E10. Adjuntos y evidencias

- formatos permitidos;
- tamaño y cantidad máximos;
- carga y descarga;
- metadatos;
- hash o integridad;
- retención;
- permisos;
- posibilidad de enlazar una referencia segura externa.

### E11. Auditoría e historial

- actor humano o aplicación;
- fecha y hora;
- estado anterior y nuevo;
- campo modificado;
- versión del recurso;
- correlation ID o identificador equivalente;
- consulta autorizada del historial.

### E12. Ejemplos sanitizados

Incluir ejemplos ficticios para:

- cliente/abonado;
- servicio;
- router lógico/NAS;
- ticket abierto, actualizado y cerrado;
- historial;
- comentario, intervención y adjunto;
- incidente padre;
- validación fallida;
- no autorizado;
- no encontrado;
- conflicto o duplicado;
- límite de consumo.

## 6. Reglas de sanitización

Se deben retirar:

- tokens;
- contraseñas;
- secretos compartidos;
- llaves privadas;
- cookies de sesión;
- datos personales reales;
- direcciones o referencias de clientes reales;
- valores productivos sensibles.

Se deben conservar:

- nombres oficiales de campos y paths;
- tipos y obligatoriedad;
- enums y códigos;
- formatos y límites;
- códigos HTTP;
- esquemas de seguridad;
- `operationId`;
- relaciones entre entidades;
- versión y fecha de vigencia.

## 7. Puertas de seguridad y activación

La recepción del contrato **no** autoriza:

- llamadas de red productivas;
- entrega de credenciales reales;
- escritura de tickets o abonados;
- sincronización del ciclo de vida;
- ejecución de backends;
- cambios en RouterOS, OLT o RADIUS;
- generación automática de un mapeo de campos.

La secuencia será:

1. recepción sanitizada;
2. análisis local;
3. revisión humana;
4. mapeo versionado;
5. pruebas de contrato sin red;
6. sandbox de solo lectura;
7. conciliación;
8. piloto limitado;
9. escrituras controladas mediante autorización posterior.

## 8. Criterio de aceptación del contrato

La documentación estará lista para modelado cuando permita responder sin ambigüedad:

1. ¿Cuál es el ID estable de cliente, servicio, ticket y router lógico/NAS?
2. ¿Cómo se relacionan esas entidades?
3. ¿Cómo se consulta el estado comercial y técnico?
4. ¿Qué campos del ticket reestructurado existen y cuáles están expuestos por API?
5. ¿Cómo se consulta el ticket, su historial y sus cambios incrementales?
6. ¿Cómo se evitan duplicados y sobrescrituras concurrentes?
7. ¿Cómo se reciben eventos o se realiza polling confiable?
8. ¿Qué permisos mínimos requiere cada operación?
9. ¿Cómo se prueban las capacidades sin afectar producción?
10. ¿Qué limitaciones, licencias, costos o desarrollos adicionales existen?

## 9. Resultado esperado de la subfase 2A

- contrato oficial recibido y versionado;
- sanitización validada;
- identificadores estables confirmados;
- matriz de campos y capacidades completada;
- brechas frente al ticket reestructurado identificadas;
- sandbox y autenticación definidos;
- decisión formal de alcance para la subfase 2B.
