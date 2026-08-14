<?php

namespace App\Http\Requests\Concerns;

use App\Services\CustomerProvisioningService;

/**
 * Garantiza que un router se guarde con UN solo método de control activo.
 *
 * POR QUÉ NORMALIZA Y NO RECHAZA
 * -------------------------------
 * La tentación es validar "solo uno" y devolver 422. El problema es el dato
 * heredado: si algún router quedó en BD con dos banderas encendidas, el
 * formulario de edición lo carga tal cual y lo reenvía tal cual, así que el
 * operador se encontraría con un router que NO PUEDE guardar — ni siquiera
 * para corregirlo. Un error de validación que solo se puede resolver con SQL
 * a mano no es una validación, es una trampa.
 *
 * Normalizar no tiene ese filo: el guardado siempre funciona y además arregla
 * la fila al pasar. El caso que la validación dura pretendía cazar (un cliente
 * de API mandando dos modos) queda igual de resuelto, y sin bloquear a nadie.
 *
 * POR QUÉ ESTE ORDEN DE PRIORIDAD
 * --------------------------------
 * Es el mismo de CustomerProvisioningService::resolveControlMode(), que es
 * quien decide el modo AL LEER. Si al escribir ganara otro, la BD diría una
 * cosa y el aprovisionamiento haría otra. Tienen que ser el mismo orden, y por
 * eso las constantes se derivan de ahí en vez de repetirse a mano.
 */
trait NormalizesRouterControlMode
{
    /**
     * Columnas de método de control, en orden de prioridad.
     *
     * RADIUS primero: es el único que no escribe en el RouterBoard, así que si
     * hubiera empate es el que menos daño hace ganándolo.
     */
    protected const CONTROL_MODE_COLUMNS = [
        'radius',
        'simple_queue',
        'control_pcq',
        'hotspot',
        'pppoe',
        'dhcp_leases',
    ];

    /**
     * Deja encendido solo el modo de mayor prioridad de los que vengan activos.
     *
     * Se llama desde prepareForValidation(), o sea ANTES de validar, para que
     * lo que se valide sea exactamente lo que se va a guardar.
     */
    protected function normalizeControlMode(): void
    {
        // Si el request no menciona ningún modo, no es una edición del método
        // de control (puede ser un PATCH de otra cosa). No tocar nada.
        $mentioned = array_filter(
            self::CONTROL_MODE_COLUMNS,
            fn (string $column) => $this->has($column)
        );

        if (empty($mentioned)) {
            return;
        }

        $active = array_values(array_filter(
            self::CONTROL_MODE_COLUMNS,
            fn (string $column) => $this->boolean($column)
        ));

        // Apagar todos es legítimo: un router sin método de control es un
        // estado válido y el dispatcher ya lo reporta ("sin método activo").
        if (empty($active)) {
            return;
        }

        $winner = $active[0];

        // Se reescriben TODAS las columnas, no solo las que vinieron: si el
        // request declara un método, el resultado guardado tiene que ser
        // exactamente ese y ninguno más. Reescribir solo las presentes dejaría
        // encendida una bandera vieja que el request no mencionó.
        $patch = [];
        foreach (self::CONTROL_MODE_COLUMNS as $column) {
            $patch[$column] = ($column === $winner);
        }

        // Al activar PPPoE hace falta un modo de limitación; el frontend ya lo
        // hace, pero un cliente de API podría no mandarlo.
        if ($winner === 'pppoe' && !$this->filled('pppoe_limit_mode')) {
            $patch['pppoe_limit_mode'] = 'dynamic';
        }

        $this->merge($patch);
    }

    /**
     * Modo resultante tras normalizar, o null si el router queda sin método.
     * Útil para reglas condicionales (p. ej. exigir secreto RADIUS).
     */
    protected function normalizedControlMode(): ?string
    {
        foreach (self::CONTROL_MODE_COLUMNS as $column) {
            if ($this->boolean($column)) {
                return $column === 'radius'
                    ? CustomerProvisioningService::MODE_RADIUS
                    : $column;
            }
        }

        return null;
    }
}
