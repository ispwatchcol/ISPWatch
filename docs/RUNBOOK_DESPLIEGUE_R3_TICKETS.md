# RUNBOOK — Despliegue de la R3 (eliminar los enums del ticket)

**Ámbito:** Fase 1 de la reestructuración del módulo de tickets, release R3 (contraer).
**Estado:** ⏳ pendiente de aprobación y del gate de la sección 1.
**Contexto de diseño:** [`ARQUITECTURA.md`](ARQUITECTURA.md) § 15 · [`BITACORA_TECNICA.md`](BITACORA_TECNICA.md) § 26-28.

---

## 0. Por qué este runbook existe

El despliegue de este proyecto **no tiene ventana de mantenimiento y no puede tenerla**:

- `deploy_on_push: true` sobre `main` para los tres componentes: mergear **es** desplegar.
- Las migraciones corren en el `run_command` del contenedor **nuevo**, y sólo después
  ese contenedor levanta Apache. Mientras tanto **el contenedor viejo sigue atendiendo
  tráfico contra una base ya migrada**.
- El maintenance mode de Laravel es inviable aquí: `php artisan down` escribe un archivo
  en `storage/framework/`, y los contenedores de App Platform tienen filesystem efímero y
  no compartido. Un `down` en el contenedor viejo no lo ve el nuevo.

De ahí que eliminar las columnas no pueda hacerse en el mismo despliegue que el código que
deja de escribirlas. Este documento es la secuencia que sí es segura.

---

## 1. 🚦 GATE OBLIGATORIO — antes de mergear el PR de la R3

> **No se mergea el PR de la R3 hasta que alguien con acceso al panel de DigitalOcean
> confirme por escrito que el App Spec vivo conserva, en el componente `ispwatch`, un
> `run_command` que ejecuta `php artisan migrate --force` ANTES de `heroku-php-apache2`.**

**Responsable:** quien tenga acceso al panel de DigitalOcean.

**Cómo confirmarlo:**

```bash
doctl apps spec get <APP_ID> | grep -A6 "run_command"
```

o, desde el panel: *Settings → Components → ispwatch → Commands → Run Command*.

**Qué debe verse:**

```
php artisan migrate --force
heroku-php-apache2 public/
```

**Por qué es un gate y no una comprobación opcional.** Toda la secuencia de abajo asume
que la migración se aplica sola al mergear. Si el `run_command` vivo ya no la ejecuta:

- las columnas **no** se dropean y el código de la R3 queda desplegado contra un esquema
  viejo — inocuo, pero la R3 no habría ocurrido y alguien lo daría por hecho;
- si en cambio ejecuta algo distinto (un `migrate:fresh`, un `db:seed`, un script propio),
  las consecuencias no están analizadas en este runbook y **hay que rehacer el análisis**.

`.do/deploy.template.yaml` está versionado, pero el spec vivo se administra desde el panel
y **puede haber divergido**. Nadie del equipo que preparó esta fase tiene acceso para
comprobarlo, y por eso se convierte en un paso explícito con dueño.

---

## 2. La secuencia de tres despliegues

Cada paso es seguro **porque el código viejo y el nuevo pueden convivir contra el esquema
resultante**. Ese es el criterio, no la comodidad.

| # | Qué entra | Esquema | Por qué la convivencia es segura | Estado |
|---|---|---|---|---|
| **1** | R1 (3 migraciones) + R2 (código) | Se añaden catálogos y claves foráneas. Las columnas enum siguen | El código viejo usa las columnas enum, que siguen ahí y las mantiene sincronizadas el código R2 | ✅ Listo en PR #233 |
| **2** | **R2.5** (sólo código) | Sin cambios | Viejo (R2) y nuevo (R2.5) leen por clave foránea. Las columnas siguen existiendo, sólo quedan congeladas | ✅ Listo |
| **3** | **R3** (1 migración + código) | Se eliminan las 3 columnas enum y sus `CHECK` | Todo el código vivo es R2.5, que ya no toca esas columnas | ⏳ Pendiente |

**El paso 2 no es burocracia.** Sin él, en el despliegue 3 el contenedor viejo correría
código R2 —que **sí** escribe el espejo— contra un esquema donde las columnas ya no
existen, y toda escritura de ticket devolvería error durante la ventana.

**Fusionar 2 y 3** deja una ventana de ~30-90 s (arranque del contenedor) en la que una
escritura concurrente de ticket fallaría. Con 14 tickets en producción la probabilidad es
baja, pero el fallo es un 500 visible. La decisión es de quien despliega; este runbook
documenta la vía segura.

---

## 3. Qué elimina exactamente la R3

| Objeto | Tipo real en PostgreSQL |
|---|---|
| `support_ticket.status` | `varchar(255) NOT NULL DEFAULT 'open'` |
| `support_ticket.priority` | `varchar(255) NOT NULL DEFAULT 'medium'` |
| `support_ticket.category` | `varchar(255) NOT NULL DEFAULT 'general'` |
| `support_ticket_status_check` | CHECK — cae con la columna |
| `support_ticket_priority_check` | CHECK — cae con la columna |
| `support_ticket_category_check` | CHECK — cae con la columna |

**No se toca nada más:** ni `resolved_at`, ni `closed_at`, ni las 8 claves foráneas, ni
`sectorial_id`. Verificado: no hay índices sobre esas columnas, ni vistas, ni SQL crudo.

Los nombres de los `CHECK` siguen la convención de Laravel. **Confírmalos antes de correr
el rollback:**

```sql
SELECT conname, pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conrelid = 'support_ticket'::regclass AND contype = 'c';
```

---

## 4. Comprobaciones previas al despliegue 3

Ejecutar contra **el schema que se va a migrar** (`public` en producción). Las tres deben
dar **0**:

```sql
SELECT count(*) FROM support_ticket t JOIN ticket_status   s ON s.id = t.status_id   WHERE s.code IS NULL;
SELECT count(*) FROM support_ticket WHERE status_id   IS NULL;
SELECT count(*) FROM support_ticket WHERE priority_id IS NULL OR category_id IS NULL;
```

> ⚠️ **No compares la columna enum con el catálogo.** Desde la R2.5 el espejo está
> congelado a propósito y va a discrepar: esa discrepancia es el comportamiento correcto,
> no un fallo. Lo único que importa es que **la clave foránea esté resuelta**.

Confirmar además que los catálogos existen en el schema destino:

```sql
SELECT count(*) FROM ticket_status;    -- 4
SELECT count(*) FROM ticket_priority;  -- 4
SELECT count(*) FROM ticket_category;  -- 4
```

---

## 5. Verificación posterior

1. `GET /api/support` — el listado responde y cada ticket trae `status`, `priority`,
   `category` (código) **y** `status_label`, `priority_label`, `category_label`.
2. `GET /api/v1/partner/tickets` con una llave real — `status` sigue siendo cadena
   (`"open"`), **nunca** un entero.
3. Crear un ticket desde el panel y cambiarle el estado.
4. `GET /api/support/statistics` — las distribuciones responden con etiquetas en español.
5. Revisar el log del componente `ispwatch` en busca de `column ... does not exist`.

---

## 6. Rollback manual

**No uses `php artisan migrate:rollback`.** Con `deploy_on_push` el siguiente despliegue
volvería a aplicar la migración, y el rollback de Laravel no coordina el código.

### 6.1 Lo que cambia respecto al rollback de la R2

Antes de la R2.5 el espejo estaba sincronizado y bastaba con dejarlo estar. **Desde la
R2.5 el espejo está obsoleto**, así que el rollback **debe reconstruir las columnas desde
el catálogo**. Restaurarlas con su último valor guardado devolvería datos incorrectos.

Esto no es una pérdida: las tres columnas no contienen ni un bit de información propia.
Son derivables al 100 % de `status_id` → `ticket_status.code`.

### 6.2 SQL

Ejecutar en **cada schema afectado** (`public` y, si aplica, `ispwatch_dev`).

```sql
BEGIN;

-- 1. Recrear las columnas, nullable de momento.
ALTER TABLE support_ticket ADD COLUMN status   varchar(255);
ALTER TABLE support_ticket ADD COLUMN priority varchar(255);
ALTER TABLE support_ticket ADD COLUMN category varchar(255);

-- 2. Reconstruir desde el catálogo, que es la fuente de verdad.
UPDATE support_ticket t SET status   = s.code FROM ticket_status   s WHERE s.id = t.status_id;
UPDATE support_ticket t SET priority = p.code FROM ticket_priority p WHERE p.id = t.priority_id;
UPDATE support_ticket t SET category = c.code FROM ticket_category c WHERE c.id = t.category_id;

-- 3. Red por si alguna fila tuviera la clave foránea nula.
UPDATE support_ticket SET status   = 'open'    WHERE status   IS NULL;
UPDATE support_ticket SET priority = 'medium'  WHERE priority IS NULL;
UPDATE support_ticket SET category = 'general' WHERE category IS NULL;

-- 4. Restituir NOT NULL y defaults.
ALTER TABLE support_ticket ALTER COLUMN status   SET NOT NULL, ALTER COLUMN status   SET DEFAULT 'open';
ALTER TABLE support_ticket ALTER COLUMN priority SET NOT NULL, ALTER COLUMN priority SET DEFAULT 'medium';
ALTER TABLE support_ticket ALTER COLUMN category SET NOT NULL, ALTER COLUMN category SET DEFAULT 'general';

-- 5. Restituir los CHECK (confirma los nombres con la consulta de la § 3).
ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_status_check
  CHECK (status::text = ANY (ARRAY['open','in_progress','resolved','closed']::text[]));
ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_priority_check
  CHECK (priority::text = ANY (ARRAY['low','medium','high','urgent']::text[]));
ALTER TABLE support_ticket ADD CONSTRAINT support_ticket_category_check
  CHECK (category::text = ANY (ARRAY['technical','billing','services','general']::text[]));

-- 6. Que Laravel no crea que la R3 sigue aplicada.
DELETE FROM migrations WHERE migration LIKE '%drop_ticket_enum_columns%';

COMMIT;
```

**Verificar antes del `COMMIT`** (las tres deben dar 0):

```sql
SELECT count(*) FROM support_ticket t JOIN ticket_status   s ON s.id=t.status_id   WHERE t.status   IS DISTINCT FROM s.code;
SELECT count(*) FROM support_ticket t JOIN ticket_priority p ON p.id=t.priority_id WHERE t.priority IS DISTINCT FROM p.code;
SELECT count(*) FROM support_ticket t JOIN ticket_category c ON c.id=t.category_id WHERE t.category IS DISTINCT FROM c.code;
```

PostgreSQL soporta DDL transaccional: si algo falla, `ROLLBACK` y no ha pasado nada.

### 6.3 Paso obligatorio fuera de SQL

**Revertir también el código** al commit anterior a la R3 y desplegarlo. El SQL por sí solo
deja el esquema restaurado pero el código de la R3 no volvería a escribir el espejo, así
que quedaría obsoleto otra vez desde el primer ticket.

---

## 7. Riesgo residual aceptado

Entre que el contenedor nuevo termina `migrate` y App Platform le conmuta el tráfico, el
contenedor viejo sigue sirviendo. En el despliegue 3 ese contenedor corre código R2.5, que
**no lee ni escribe** las columnas eliminadas — por eso la ventana es inocua.

Lo que **no** cubre esta secuencia: que alguien despliegue a `main` un cambio ajeno que
vuelva a tocar esas columnas entre el paso 2 y el 3. La superficie está auditada y hoy es
mínima —worker, scheduler, jobs, comandos y observers **no tocan** `support_ticket`—, pero
conviene no dejar los dos pasos separados por semanas.
