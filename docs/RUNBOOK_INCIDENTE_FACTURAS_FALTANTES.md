# Runbook · Mensualidades faltantes con pagos convertidos en saldo a favor

Incidente abierto el **2026-10-01**. Bitácora § 88; mejoras P-80 a P-83.

Para: la persona con **acceso autorizado de sólo lectura** a la base de producción y para
quien aprueba la reparación. Nada de este documento escribe en la base salvo el paso 5, y el
paso 5 sólo se ejecuta con aprobación explícita y por escrito.

> **No usar** `billing:generate-monthly <periodo>` (corre para TODOS los routers),
> `billing:generate-tenant` (no toma el bloqueo, P-82) ni SQL de actualización para reparar.

---

## 0. Antes de consultar

1. Confirmar **qué commit corre en producción**, en la plataforma de despliegue. Si es
   anterior a la corrección de este incidente, el paso 4 no está disponible: usar las
   consultas del paso 2.
2. Abrir la sesión en sólo lectura y comprobar el esquema. Producción y desarrollo
   comparten base y los separa el `search_path` (ver `ProductionDatabaseGuard`):

```sql
BEGIN TRANSACTION READ ONLY;
SHOW search_path;          -- debe ser el esquema que se quiere auditar
-- … consultas …
ROLLBACK;
```

3. No pegar resultados con datos personales en Jira ni en el chat: usar los IDs.

Las consultas usan variables de `psql` (`\set`). Ejemplo:

```
\set tenant_id 7
\set ids '{101,102,103,104}'
\set desde '2026-07-01'
\set patron '%osorio%'
```

---

## 1. Resolver tenant e identidad

No suponer el tenant ni el cliente por el nombre. Esta consulta devuelve **todas** las
coincidencias en **todos** los tenants; el responsable elige.

**PUNTO 2 es otro registro** salvo evidencia en contra (otro `user_id`).

```sql
SELECT COALESCE(cp.tenant_id, u.tenant_id) AS tenant_id, t.name AS tenant,
       cp.user_id, cp.id AS profile_id, cp.name, cp.last_name, cp.cedula,
       cp.router_id, cp.service_status, cp.exclude_from_billing, cp.notify_invoice,
       cp.credit_balance, u.created_at
FROM customer_profile cp
JOIN users u        ON u.id = cp.user_id
LEFT JOIN tenant t  ON t.id = COALESCE(cp.tenant_id, u.tenant_id)
WHERE lower(cp.name || ' ' || COALESCE(cp.last_name, '')) LIKE lower(:'patron')
ORDER BY 1, 3;
```

Repetir con un patrón por cliente. Anotar `tenant_id` y los `user_id` confirmados.

---

## 2. Estado de cada cliente

Todas acotadas a `:tenant_id` y a la lista `:ids`.

**2.1 Router y configuración de facturación**: día y hora de creación, modo, día de pago, tope.

```sql
SELECT cp.user_id, r.id AS router_id, r.name AS router, r.tenant_id AS router_tenant,
       r.billing_router_id, b.create_invoice, b.create_invoice_time, b.billing_mode,
       b.payment_day, b.overdue_invoices, b.stop_invoicing_extra, b.notification_type
FROM customer_profile cp
LEFT JOIN router  r ON r.id = cp.router_id
LEFT JOIN billing b ON b.id = r.billing_router_id
WHERE cp.user_id = ANY(:'ids'::bigint[]);
```

**2.2 Servicio y plan**

```sql
SELECT us.user_id, us.id, us.status, us.start_date,
       sp.id AS plan_id, sp.name AS plan, sp.cost_product, sp.is_courtesy
FROM user_services us
LEFT JOIN service_plan sp ON sp.id = us.service_plan_id
WHERE us.user_id = ANY(:'ids'::bigint[])
ORDER BY us.user_id, us.id;
```

**2.3 Facturas**: todos los tipos y estados, anuladas incluidas.

```sql
SELECT customer_id, id, number, invoice_type, status, period_start, period_end,
       issue_date, due_date, total, balance_due, voided_at, void_reason, created_at
FROM invoices
WHERE tenant_id = :tenant_id
  AND customer_id = ANY(:'ids'::bigint[])
  AND (period_start >= :'desde' OR created_at >= :'desde')
ORDER BY customer_id, period_start, id;
```

**2.4 Rastro de la corrida**: fallos, reintentos agotados, meses suprimidos por un borrado.

```sql
SELECT customer_id, period_start, period_end, status, attempts, last_error, invoice_id, updated_at
FROM billing_action_logs
WHERE tenant_id = :tenant_id AND customer_id = ANY(:'ids'::bigint[])
ORDER BY customer_id, period_start;
```

**2.5 Pagos, asignaciones y saldo a favor**

```sql
SELECT p.customer_id, p.id, p.amount, p.payment_date, p.method, p.created_at,
       COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa WHERE pa.payment_id = p.id), 0) AS asignado_a_facturas
FROM payments p
WHERE p.tenant_id = :tenant_id AND p.customer_id = ANY(:'ids'::bigint[])
ORDER BY p.customer_id, p.id;

SELECT customer_id, id, type, amount, balance_after, from_payment_id, to_invoice_id, reason, created_at
FROM customer_credits
WHERE tenant_id = :tenant_id AND customer_id = ANY(:'ids'::bigint[])
ORDER BY customer_id, id;
```

**2.6 Historia de las casillas y de las facturas**: quién cambió qué y cuándo.

```sql
SELECT created_at, action, model_type, model_id, old_values, new_values, description, user_id
FROM audit_logs
WHERE (model_type LIKE '%CustomerProfile' AND model_id IN
          (SELECT id FROM customer_profile WHERE user_id = ANY(:'ids'::bigint[])))
   OR (model_type LIKE '%Invoice' AND model_id IN
          (SELECT id FROM invoices WHERE tenant_id = :tenant_id AND customer_id = ANY(:'ids'::bigint[])))
ORDER BY created_at;
```

### Cómo leer cada caso

| Si se ve… | Es… | ¿Reparar con la herramienta? |
|---|---|---|
| Mensualidad del mes con `status = void` | Anulada; sus pagos volvieron a saldo a favor | **No**: reponerla es una decisión del ISP |
| `exclude_from_billing = true` | «No facturar a este cliente», no «no notificar» | No; revisar con el ISP cuál casilla se quiso marcar |
| `notify_invoice = false` y sin factura | **No** explica la falta en el código actual | Sí, si el resto de puertas lo permite |
| `create_invoice` vacío en el router | P-28: la corrida salta el router entero | No; primero configurar el router |
| `billing_action_logs.status = suppressed` | Un administrador borró la factura de ese mes | No, salvo decisión explícita |
| `failed` o `exhausted` | La corrida falló al crear; ver `last_error` | Sí |
| Servicio no `active`, plan de cortesía, `service_status` retirado o cancelado | No le corresponde | No |
| Factura en `period_start` de otro mes | Está en otro periodo, no falta | No |
| Modo `vencido` | La factura de septiembre sale en octubre | Depende de la fecha |

---

## 3. Otros afectados del mismo tenant y periodo

Aproximación SQL: candidatos con servicio activo de pago y **sin ninguna mensualidad** del
mes. **No basta para declararlos faltantes**: el tope de mora, la política de primera factura
y el mes suprimido se ven en las columnas de la derecha, y el veredicto final lo da el paso 4.

```sql
\set p_ini '2026-09-01'
\set p_fin '2026-09-30'

SELECT cp.user_id, cp.name, cp.last_name, cp.router_id, b.create_invoice, b.billing_mode,
       cp.notify_invoice, cp.credit_balance, us.start_date, cp.installation_date,
       (SELECT COUNT(*) FROM invoices i
         WHERE i.tenant_id = :tenant_id AND i.customer_id = cp.user_id
           AND i.balance_due > 0 AND i.status NOT IN ('paid','void','cancelled')) AS pendientes,
       EXISTS (SELECT 1 FROM billing_action_logs l
         WHERE l.tenant_id = :tenant_id AND l.customer_id = cp.user_id
           AND l.period_start = :'p_ini' AND l.status = 'suppressed') AS suprimido
FROM customer_profile cp
JOIN router r          ON r.id = cp.router_id AND r.tenant_id = :tenant_id
JOIN billing b         ON b.id = r.billing_router_id
JOIN user_services us  ON us.user_id = cp.user_id AND us.status = 'active'
JOIN service_plan sp   ON sp.id = us.service_plan_id AND sp.is_courtesy = false
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

## 4. Simulación exacta (requiere la corrección desplegada)

Corre las mismas reglas que la corrida mensual, **sin escribir**:

```bash
php artisan billing:missing-invoices --tenant=<id> --period=2026-09 --all
php artisan billing:missing-invoices --tenant=<id> --period=2026-09 --customer=101 --customer=102 --json
```

| Columna | Qué es |
|---|---|
| `decision` | `missing` (falta), `present` (existe; puede estar anulada) o `not_applicable` |
| `reason` | Motivo exacto, p. ej. `invoice_voided`, `stop_threshold`, `router_without_create_day` |
| `preview` | Total, crédito antes, crédito a aplicar, saldo de la factura, crédito después, si se avisa y vencimiento |
| `plan-hash` | Huella del plan; es lo que se aprueba |

Evalúa el estado **de hoy**: si el día de la corrida el cliente estaba en otra situación, el
motivo de entonces puede ser distinto. Contrastar con 2.4 y 2.6.

**Aprobación**: el responsable revisa la tabla de faltantes y aprueba por escrito, en la
tarjeta de Jira, la lista de `customer_id`, el periodo y el `plan-hash`.

---

## 5. Reparación (sólo con aprobación)

```bash
php artisan billing:missing-invoices --tenant=<id> --period=2026-09 \
  --customer=101 --customer=102 \
  --plan-hash=<hash aprobado> \
  --reason="Incidente 2026-10-01, aprobado por <nombre> en <tarjeta>" \
  --apply
```

Qué garantiza:

- **Sólo** emite a los clientes nombrados que en ese momento siguen en `missing`.
- Si el plan cambió desde la aprobación (otro pago, otra factura), el hash no coincide y
  **no escribe nada**.
- Cada factura se emite en una transacción, con el cliente bloqueado. Si la corrida horaria
  la creó un instante antes, no se duplica.
- El saldo a favor existente se aplica sin crear pagos; la notificación respeta
  «No enviar notificaciones».
- Quedan el motivo en la nota de la factura, el origen `console` en `audit_logs` y una línea
  `[BILLING-REPAIR]` en el log.
- Al terminar verifica que ningún cliente reparado siga faltando, y sale con error si alguno
  sigue.

## 6. Verificación y conciliación posterior

1. Repetir el paso 4 para los clientes reparados: todos en `present` / `invoice_present`.
2. Repetir 2.3 y 2.5: una mensualidad por cliente y mes; los pagos y sus asignaciones,
   iguales que antes; el saldo a favor reducido exactamente en `credit_to_apply`.
3. Correr `php artisan billing:verify-orphan-payments` y `billing:audit-books --warnings-ok`:
   sin descuadres nuevos.
4. Registrar el resultado en la tarjeta. **El incidente se cierra aquí, no antes.**

## 7. Recuperación

Si una factura reparada resultó incorrecta, **anularla** desde Facturación, con motivo.
`voidInvoice()` devuelve como saldo a favor el crédito que esa factura había consumido, y la
deja anulada, así que la herramienta no la vuelve a emitir sola (`invoice_voided`). No borrar
filas ni corregir saldos por SQL.
