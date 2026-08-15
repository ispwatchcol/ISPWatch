<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FASE 1 · R1 (expandir) — siembra de los catálogos migrados desde los enums.
 *
 * POR QUÉ UNA MIGRACIÓN Y NO UN SEEDER
 *
 * `migrate:both` aplica las migraciones en `ispwatch_dev` y en `public`, pero
 * NUNCA siembra `public` (ver MigrateBothSchemas: los seeders crean datos de
 * demostración que contaminarían producción). Un catálogo que dependiera del
 * seeder quedaría VACÍO en producción, y sin filas en `ticket_status` no se
 * puede crear ni un solo ticket. Esa factura ya se pagó una vez con `cut_type`
 * — ver 2026_07_31_000001_ensure_cut_type_catalog_rows.
 *
 * LOS CÓDIGOS SON EXACTAMENTE LOS ENUMS ACTUALES
 *
 * Ni uno más ni uno menos, y con la misma grafía. Es lo que convierte el
 * backfill en un join por código sin mapeo manual y sin ninguna fila
 * huérfana: el CHECK constraint del enum lleva desde 2024 garantizando que en
 * esas columnas no hay nada fuera de estos doce valores.
 *
 * QUÉ NO SE SIEMBRA, Y POR QUÉ
 *
 * `ticket_symptom`, `ticket_cause`, `ticket_solution` y `ticket_result` quedan
 * VACÍOS a propósito. Su vocabulario todavía no está acordado con el ISP ni
 * con el integrador, y como los códigos son inmutables por diseño, inventarlos
 * ahora significaría o cargar para siempre con códigos equivocados, o
 * retirarlos en dos semanas dejando basura en el histórico. Las tablas quedan
 * listas; el vocabulario se siembra en su propia migración cuando esté
 * acordado.
 *
 * Idempotente: sólo inserta lo que falta y no toca las filas existentes ni sus
 * ids, a los que apuntarán las claves foráneas de `support_ticket`.
 */
return new class extends Migration
{
    /**
     * Estados. `resolved` y `closed` son AMBOS terminales: por eso reabrir es
     * una transición explícita (Fase 2) y no un efecto colateral de escribir
     * un estado cualquiera, que es lo que pasa hoy.
     */
    private const ESTADOS = [
        ['code' => 'open',        'label' => 'Abierto',     'weight' => 10, 'is_initial' => true,  'is_terminal' => false, 'stamps_resolved_at' => false, 'stamps_closed_at' => false],
        ['code' => 'in_progress', 'label' => 'En progreso', 'weight' => 20, 'is_initial' => false, 'is_terminal' => false, 'stamps_resolved_at' => false, 'stamps_closed_at' => false],
        ['code' => 'resolved',    'label' => 'Resuelto',    'weight' => 30, 'is_initial' => false, 'is_terminal' => true,  'stamps_resolved_at' => true,  'stamps_closed_at' => false],
        ['code' => 'closed',      'label' => 'Cerrado',     'weight' => 40, 'is_initial' => false, 'is_terminal' => true,  'stamps_resolved_at' => false, 'stamps_closed_at' => true],
    ];

    /** Prioridades. Los SLA quedan nulos hasta la fase que los defina. */
    private const PRIORIDADES = [
        ['code' => 'low',    'label' => 'Baja',    'weight' => 10],
        ['code' => 'medium', 'label' => 'Media',   'weight' => 20],
        ['code' => 'high',   'label' => 'Alta',    'weight' => 30],
        ['code' => 'urgent', 'label' => 'Urgente', 'weight' => 40],
    ];

    /**
     * Categorías. Sólo `technical` viaja al integrador: el alcance acordado se
     * limita a soporte técnico de servicios existentes, y dejarlo como dato
     * evita que el filtro dependa de que alguien recuerde la convención.
     */
    private const CATEGORIAS = [
        ['code' => 'technical', 'label' => 'Técnica',      'weight' => 10, 'is_integration_visible' => true],
        ['code' => 'billing',   'label' => 'Facturación',  'weight' => 20, 'is_integration_visible' => false],
        ['code' => 'services',  'label' => 'Servicios',    'weight' => 30, 'is_integration_visible' => false],
        ['code' => 'general',   'label' => 'General',      'weight' => 40, 'is_integration_visible' => false],
    ];

    /** Catálogos que se registran en el control de versiones, incluidos los vacíos. */
    private const CATALOGOS = [
        'status', 'priority', 'category', 'symptom', 'cause', 'solution', 'result',
    ];

    public function up(): void
    {
        $this->sembrar('ticket_status', self::ESTADOS);
        $this->sembrar('ticket_priority', self::PRIORIDADES);
        $this->sembrar('ticket_category', self::CATEGORIAS);

        foreach (self::CATALOGOS as $catalogo) {
            // Los catálogos aún sin vocabulario también arrancan en la versión
            // 1: un integrador debe poder preguntar por ellos y recibir una
            // lista vacía, que es información, en vez de un 404.
            DB::table('ticket_catalog_version')->updateOrInsert(
                ['catalog' => $catalogo],
                ['version' => 1, 'updated_at' => now()],
            );
        }
    }

    /** Inserta sólo lo que falta, comparando por código. */
    private function sembrar(string $tabla, array $filas): void
    {
        $existentes = DB::table($tabla)->pluck('code')->all();

        foreach ($filas as $fila) {
            if (in_array($fila['code'], $existentes, true)) {
                continue;
            }

            DB::table($tabla)->insert($fila + [
                'valid_from' => now(),
                'revision'   => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // No se borra nada: `support_ticket.status_id` y sus hermanas apuntan a
        // estas filas, y perderlas dejaría los tickets sin catálogo. La tabla
        // entera desaparece en el `down()` de la migración que la creó, que es
        // el único punto donde eso es coherente.
    }
};
