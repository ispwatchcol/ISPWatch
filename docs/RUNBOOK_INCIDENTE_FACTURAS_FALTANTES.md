# Runbook · Mensualidades faltantes con pagos convertidos en saldo a favor

Incidente abierto el **2026-10-01**. Bitácora § 88; mejoras P-80 a P-86.

**Alcance confirmado**: tenant **19**, período **septiembre de 2026** (facturas cuyo
`period_start` cae en 2026-09). Ninguna consulta de este documento sale de ese tenant.

Para: la persona que ejecute las consultas con **acceso autorizado de sólo lectura** y quien
apruebe la reparación. Hasta el paso 5 nada escribe en la base. El paso 5 exige una aprobación
aparte, por escrito.

> **No usar** para reparar `billing:generate-monthly <periodo>` ni `POST /billing/run-monthly`
> (corren para TODOS los tenants), `billing:generate-tenant` (reglas propias, P-84) ni SQL de
> actualización.

---

## 0. Antes de consultar

1. Confirmar **qué commit corre en producción**. Si no incluye la corrección de § 88, el paso 4
   no existe allí y el diagnóstico sale sólo de las consultas del paso 2.
2. Sesión en sólo lectura y esquema comprobado. Producción y desarrollo comparten base; los
   separa el `search_path` (ver `ProductionDatabaseGuard`).

```sql
BEGIN TRANSACTION READ ONLY;
SHOW search_path;
-- … consultas …
ROLLBACK;
```

3. **Horas en UTC.** La aplicación corre con `timezone = UTC`: `created_at`, `issue_date` y la
   hora de creación del router (`create_invoice_time`) se leen en UTC. Bogotá es UTC−5.
4. No copiar resultados con datos personales a Jira ni al chat: referirse a los `user_id`.

Variables de `psql`. Los patrones de nombre se fijan **al ejecutar**, no se guardan en el
repositorio:

```
\set tenant_id 19
\set p_ini '2026-09-01'
\set p_fin '2026-09-30'
\set desde '2026-08-01'
\set hasta '2026-10-31'
```

---

## 1. Identidad: IDs exactos dentro del tenant 19

Una consulta por cliente reportado, con todas las palabras del nombre como patrones. Devuelve
**todas** las coincidencias del tenant 19. Si un nombre trae más de un registro, **no se elige
por aproximación**: se reporta y lo resuelve el ISP (cédula, dirección, router).
«PUNTO 2» es un registro aparte salvo evidencia en contra.

```sql
\set pats '{%juan%,%carlos%,%morera%,%garay%}'

SELECT cp.user_id, cp.id AS profile_id, cp.name, cp.last_name,
       right(COALESCE(cp.cedula, ''), 4) AS cedula_ult4,
       cp.router_id, cp.service_status, u.created_at
FROM customer_profile cp
JOIN users u ON u.id = cp.user_id
WHERE COALESCE(cp.tenant_id, u.tenant_id) = :tenant_id
  AND translate(lower(cp.name || ' ' || COALESCE(cp.last_name, '')), 'áéíóúüñ', 'aeiouun')
      LIKE ALL (:'pats'::text[])
ORDER BY cp.user_id;
```

Repetir con los patrones de cada cliente reportado. Resultado esperado: una tabla
`cliente reportado → user_id(s)`, con los ambiguos marcados. **Los pasos siguientes usan sólo
IDs resueltos:**

```
\set ids '{<id1>,<id2>,<id3>,<id4>}'
```

---

## 2. Estado de cada registro

Todas las consultas filtran por `:tenant_id` y por `:ids`.

### 2.1 Configuración y fecha en que debía correr la mensualidad de septiembre

En **anticipado**, la mensualidad de septiembre sale el día de creación **de septiembre**. En
**vencido**, sale el día de creación **de octubre**. Esta consulta calcula cuál aplica.

```sql
WITH cfg AS (
  SELECT cp.user_id, cp.router_id, r.billing_router_id, b.create_invoice, b.create_invoice_time,
         COALESCE(b.billing_mode, 'anticipado') AS modo, b.payment_day, b.overdue_invoices,
         b.stop_invoicing_extra, b.notification_type,
         CASE WHEN COALESCE(b.billing_mode, 'anticipado') = 'vencido'
              THEN (:'p_ini'::date + interval '1 month')::date ELSE :'p_ini'::date END AS mes_corrida
  FROM customer_profile cp
  LEFT JOIN router  r ON r.id = cp.router_id AND r.tenant_id = :tenant_id
  LEFT JOIN billing b ON b.id = r.billing_router_id
  WHERE cp.user_id = ANY(:'ids'::bigint[])
)
SELECT *,
  -- date + integer = date; date + time = timestamp (timestamp + time no existe).
  CASE WHEN create_invoice IS NULL THEN NULL ELSE
    (mes_corrida
      + (LEAST(EXTRACT(day FROM create_invoice)::int,
               EXTRACT(day FROM (mes_corrida + interval '1 month - 1 day'))::int) - 1))
    + COALESCE(create_invoice_time, time '00:00')
  END AS debia_correr_utc
FROM cfg;
```

Cómo leerlo:

- `router_id` o `billing_router_id` vacío → la corrida no recorre al cliente.
- `create_invoice` vacío → la corrida salta el router entero (P-28).
- `debia_correr_utc` posterior a ahora → **todavía no le tocaba**: no falta.

### 2.2 Elegibilidad del cliente: casillas, servicio, plan y fechas para la primera factura

```sql
SELECT cp.user_id, cp.exclude_from_billing, cp.notify_invoice, cp.service_status,
       cp.installation_date, u.created_at AS alta_usuario, cp.credit_balance,
       us.id AS servicio_id, us.status AS servicio_estado, us.start_date,
       sp.id AS plan_id, sp.cost_product, sp.is_courtesy,
       (SELECT jsonb_object_agg(k, v) FROM jsonb_each(to_jsonb(cp)) WHERE k LIKE 'first_invoice%') AS politica_cliente,
       (SELECT jsonb_object_agg(k, v) FROM jsonb_each(to_jsonb(sp)) WHERE k LIKE 'first_invoice%') AS politica_plan,
       (SELECT jsonb_object_agg(k, v) FROM jsonb_each(to_jsonb(b))  WHERE k LIKE 'first_invoice%') AS politica_router
FROM customer_profile cp
JOIN users u               ON u.id = cp.user_id
LEFT JOIN user_services us ON us.user_id = cp.user_id
LEFT JOIN service_plan sp  ON sp.id = us.service_plan_id
LEFT JOIN router r         ON r.id = cp.router_id AND r.tenant_id = :tenant_id
LEFT JOIN billing b        ON b.id = r.billing_router_id
WHERE cp.user_id = ANY(:'ids'::bigint[])
ORDER BY cp.user_id, us.id;
```

La corrida sólo cobra a quien cumple todo esto:

- `exclude_from_billing = false`;
- `service_status` en `activo`, `gratis` o `suspendido` (o vacío);
- un servicio con `status = 'active'` y un plan que no sea de cortesía.

> `notify_invoice` **no** decide si hay factura: sólo si se avisa. Y **no tiene historial**:
> la bitácora de dinero no registra sus cambios (`exclude_from_billing` sí; ver 2.6).

### 2.3 Facturas: del período septiembre frente a las emitidas en septiembre

No es lo mismo. La columna `clase` las separa. Incluye las anuladas.

```sql
SELECT customer_id, id, number, COALESCE(invoice_type, '(sin tipo)') AS tipo, status,
       period_start, period_end, issue_date, due_date, total, balance_due,
       voided_at, void_reason, created_at,
       CASE
         WHEN period_start::date BETWEEN :'p_ini' AND :'p_fin'
              AND (invoice_type = 'monthly' OR invoice_type IS NULL) THEN 'MENSUALIDAD DEL PERIODO 2026-09'
         WHEN period_start::date BETWEEN :'p_ini' AND :'p_fin'        THEN 'otro tipo, periodo 2026-09'
         WHEN issue_date::date  BETWEEN :'p_ini' AND :'p_fin'          THEN 'emitida en sept., OTRO periodo'
         ELSE 'otro periodo'
       END AS clase
FROM invoices
WHERE tenant_id = :tenant_id
  AND customer_id = ANY(:'ids'::bigint[])
  AND (period_start::date BETWEEN :'desde' AND :'hasta' OR issue_date::date BETWEEN :'desde' AND :'hasta')
ORDER BY customer_id, period_start, id;
```

Si la mensualidad del período aparece con `status = void`, **no falta**: está anulada. La
corrida no la repone y la herramienta tampoco (`invoice_voided`).

### 2.4 Rastro de la corrida para estos clientes

```sql
SELECT customer_id, router_id, period_start, period_end, status, attempts, last_error,
       invoice_id, created_at, updated_at
FROM billing_action_logs
WHERE tenant_id = :tenant_id AND customer_id = ANY(:'ids'::bigint[])
ORDER BY customer_id, period_start;
```

- `failed` / `exhausted` → la corrida intentó crear la factura y falló; ver `last_error`.
- `suppressed` → un administrador borró la factura de ese mes; no se regenera.
- **Ninguna fila** no prueba nada: la corrida sólo escribe aquí cuando algo falla.

### 2.5 ¿Corrió la mensualidad en su router? Evidencia indirecta

El latido del scheduler vive en caché, no en la base: **no hay registro histórico** de cada
ejecución. La evidencia es que la corrida sí facturó a los demás clientes del mismo router y
período, y cuándo lo hizo.

```sql
SELECT cp.router_id,
       COUNT(*) FILTER (WHERE i.id IS NOT NULL)          AS mensualidades_sept,
       MIN(i.created_at)                                  AS primera_creada_utc,
       MAX(i.created_at)                                  AS ultima_creada_utc,
       COUNT(DISTINCT cp.user_id)                         AS clientes_del_router
FROM customer_profile cp
LEFT JOIN invoices i ON i.customer_id = cp.user_id AND i.tenant_id = :tenant_id
     AND (i.invoice_type = 'monthly' OR i.invoice_type IS NULL)
     AND i.period_start::date BETWEEN :'p_ini' AND :'p_fin'
WHERE cp.router_id IN (SELECT router_id FROM customer_profile WHERE user_id = ANY(:'ids'::bigint[]))
  AND COALESCE(cp.tenant_id, (SELECT tenant_id FROM users WHERE id = cp.user_id)) = :tenant_id
GROUP BY cp.router_id;
```

Complemento, **fuera de la base**: los logs de la aplicación del día de la corrida. La corrida
escribe por cliente el motivo de cada salto (`Billing: Customer <id> — … Skipping.`, `tope`,
`primera factura`, `eliminada por un administrador`) y por router `has no create_invoice day`.
Pedir esos logs a la plataforma, con la retención disponible, filtrando por los `user_id`.

### 2.6 Historia auditada: casillas, servicio y facturas

```sql
SELECT created_at, action, model_type, model_id, old_values, new_values, description, user_id
FROM audit_logs
WHERE tenant_id = :tenant_id
  AND ((model_type LIKE '%CustomerProfile' AND model_id IN
          (SELECT id FROM customer_profile WHERE user_id = ANY(:'ids'::bigint[])))
    OR (model_type LIKE '%Invoice' AND model_id IN
          (SELECT id FROM invoices WHERE tenant_id = :tenant_id AND customer_id = ANY(:'ids'::bigint[]))))
  AND created_at >= :'desde'
ORDER BY created_at;
```

Registra cambios de `exclude_from_billing`, `service_status`, plan, y el ciclo de vida de las
facturas (creación, anulación, borrado). **No** registra `notify_invoice`.

### 2.7 Tope de mora (hoy)

```sql
SELECT customer_id, COUNT(*) AS facturas_pendientes
FROM invoices
WHERE tenant_id = :tenant_id AND customer_id = ANY(:'ids'::bigint[])
  AND balance_due > 0 AND status NOT IN ('paid', 'void', 'cancelled')
GROUP BY customer_id;
```

El tope del router es `overdue_invoices + stop_invoicing_extra` (2.1); si
`stop_invoicing_extra` está vacío, no hay tope. **Es el estado de hoy**: el del día de la
corrida se reconstruye con 2.3 (qué estaba pendiente entonces).

### 2.8 Pagos, asignaciones y saldo a favor

```sql
SELECT p.customer_id, p.id AS pago_id, p.amount, p.payment_date, p.method, p.created_at,
       pa.invoice_id, pa.amount AS asignado
FROM payments p
LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
WHERE p.tenant_id = :tenant_id AND p.customer_id = ANY(:'ids'::bigint[])
  AND p.payment_date >= :'desde'
ORDER BY p.customer_id, p.id, pa.id;

SELECT customer_id, id, type, amount, balance_after, from_payment_id, to_invoice_id, reason, created_at
FROM customer_credits
WHERE tenant_id = :tenant_id AND customer_id = ANY(:'ids'::bigint[])
ORDER BY customer_id, id;
```

Qué buscar:

- un pago sin asignación, o con sobrante, debió dejar un `earned` en `customer_credits` con
  `from_payment_id`;
- un `applied` con `to_invoice_id` es un saldo ya aplicado a una factura;
- el `credit_balance` de 2.2 tiene que coincidir con el último `balance_after`.

---

## 3. Otros posibles afectados del tenant 19 en septiembre

Sólo después de resolver la identidad del paso 1. Lista los clientes con servicio activo de
pago y **sin ninguna mensualidad del período**, anuladas incluidas.

**No basta para declararlos faltantes.** Faltan el tope, la política de primera factura, el
mes suprimido y la fecha de corrida (2.1); el veredicto lo da el paso 4.

```sql
SELECT cp.user_id, cp.router_id, cp.service_status, cp.notify_invoice, cp.credit_balance,
       us.start_date, cp.installation_date,
       EXISTS (SELECT 1 FROM billing_action_logs l
               WHERE l.tenant_id = :tenant_id AND l.customer_id = cp.user_id
                 AND l.period_start = :'p_ini' AND l.status = 'suppressed') AS mes_suprimido,
       (SELECT COUNT(*) FROM invoices i2
         WHERE i2.tenant_id = :tenant_id AND i2.customer_id = cp.user_id
           AND i2.balance_due > 0 AND i2.status NOT IN ('paid','void','cancelled')) AS pendientes_hoy
FROM customer_profile cp
JOIN router r         ON r.id = cp.router_id AND r.tenant_id = :tenant_id
JOIN billing b        ON b.id = r.billing_router_id
JOIN user_services us ON us.user_id = cp.user_id AND us.status = 'active'
JOIN service_plan sp  ON sp.id = us.service_plan_id AND sp.is_courtesy = false
WHERE cp.exclude_from_billing = false
  AND (cp.service_status IN ('activo','gratis','suspendido') OR cp.service_status IS NULL)
  AND NOT EXISTS (
      SELECT 1 FROM invoices i
      WHERE i.tenant_id = :tenant_id AND i.customer_id = cp.user_id
        AND (i.invoice_type = 'monthly' OR i.invoice_type IS NULL)
        AND i.period_start::date BETWEEN :'p_ini' AND :'p_fin')
ORDER BY cp.router_id, cp.user_id;
```

---

## 4. Simulación exacta (requiere la corrección desplegada; no escribe)

```bash
php artisan billing:missing-invoices --tenant=19 --period=2026-09 --customer=<id> --customer=<id> --json
php artisan billing:missing-invoices --tenant=19 --period=2026-09 --all
```

Aplica las mismas reglas que la corrida y devuelve, por cliente:

- `decision`: `missing`, `present` (puede estar anulada) o `not_applicable`;
- `reason`: el motivo;
- la vista previa: importe, saldo a favor antes, saldo a aplicar, saldo de la factura, crédito
  restante, si se avisa y vencimiento;
- el `plan-hash` del lote.

Evalúa el estado **de hoy**. Si el día de la corrida el cliente estaba en otra situación, el
motivo de entonces puede ser distinto: contrastar con 2.4 a 2.6.

**Aprobación** (aparte, por escrito, en la tarjeta): la lista de `customer_id`, el período y el
`plan-hash`. **La simulación y la aplicación tienen que hacerse el mismo día**: la fecha de
emisión forma parte de la huella.

---

## 5. Reparación (sólo con aprobación explícita)

```bash
php artisan billing:missing-invoices --tenant=19 --period=2026-09 \
  --customer=<id> --customer=<id> \
  --plan-hash=<hash aprobado> \
  --reason="Incidente 2026-10-01, aprobado por <nombre> en <tarjeta>" \
  --apply
```

Qué hace, en este orden, dentro de **una sola transacción**:

1. Bloquea a **todos** los clientes nombrados, ordenados por `user_id`.
2. Con los clientes bloqueados, vuelve a evaluarlos: elegibilidad, factura existente (anuladas
   incluidas), importe, arrastre, adicionales, saldo a favor, fechas y aviso.
3. Recalcula la huella. **Si no coincide con la aprobada, deshace todo y no escribe nada**, ni
   siquiera a los clientes que no cambiaron. Hay que simular y aprobar de nuevo.
4. Emite cada faltante por la vía de la corrida y comprueba que el total y el saldo emitidos
   cuadren con lo aprobado; si no cuadran, deshace todo.
5. Confirma. **Sólo entonces** salen los avisos, a quien no los tenga silenciados y tenga saldo
   por pagar.

La corrida horaria, el reintento, el alta y `billing:generate-tenant` usan el mismo bloqueo:
si alguno llega a la vez, espera y no duplica.

## 6. Verificación y conciliación posterior

1. Repetir el paso 4 para los clientes reparados: todos `present` / `invoice_present`.
2. Repetir 2.3 y 2.8. Debe verse:
   - una mensualidad por cliente para septiembre;
   - los pagos y sus asignaciones, iguales que antes;
   - el saldo a favor reducido exactamente en lo aplicado, con un `applied` por factura.
3. `php artisan billing:verify-orphan-payments` y `php artisan billing:audit-books --warnings-ok`:
   sin descuadres nuevos.
4. Registrar el resultado en la tarjeta. **El incidente se cierra aquí, no antes.**

## 7. Recuperación: qué se puede deshacer y qué no

No hay un rollback automático. La única vía soportada para dejar sin efecto una factura
reparada es **anularla** (Facturación → Anular, con motivo), y su efecto está fijado por una
prueba
(`MissingMonthlyInvoiceTest::voiding_a_repaired_invoice_returns_credit_and_later_payments_but_not_everything`).

| Efecto de la reparación | ¿Lo revierte anular? | Cómo queda |
|---|---|---|
| Factura emitida (número del consecutivo) | Parcial | Queda `void`, con total, número y fechas intactos. **El número no se recupera** |
| Saldo a favor aplicado | Sí | Vuelve como movimiento `adjusted`; el `applied` original se conserva en el historial |
| Pagos **posteriores** aplicados a esa factura | Los cambia | Sus asignaciones se sueltan y el dinero pasa a **saldo a favor** (`earned`). No es la inversa de la reparación: deshace también lo que vino después |
| Arrastre (deuda vieja) que cobró la factura | Sí | Vuelve a pendiente para la siguiente factura |
| Servicios adicionales cobrados en la factura | **No** | Sus ítems siguen contando como cobrados en ese mes, así que no se vuelven a cobrar (P-85) |
| El mes | **No** | Queda «cubierto» por la anulada: ni la corrida ni la herramienta lo reemiten |
| Aviso por correo / WhatsApp | **No** | Ya llegó. Anular no envía nada |
| API partner (`/invoices`) | **No** | La integración del ISP pudo leer la factura; después leerá su anulación |
| Corte por mora | Indirecto | Mientras la factura estuvo vigente pudo contar para el corte; anularla la saca, pero un corte ya ejecutado no se revierte solo |
| Auditoría | — | Quedan la emisión (origen `console`, motivo en la nota) y la anulación (`voided_at`, `void_reason`, actor) |

**Antes de anular**, revisar 2.8: si hubo pagos posteriores aplicados a esa factura, anular
los convierte en saldo a favor. Decidirlo con el ISP. No corregir nada por SQL.
