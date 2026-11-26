<?php

namespace App\Console\Commands;

use App\Models\InventoryDevice;
use App\Services\Inventory\InventoryLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pasa al cliente los equipos que quedaron instalados «en casa de nadie»
 * porque se registraron en la orden de un prospecto que DESPUÉS se convirtió.
 *
 * Hasta P-74, convertir el prospecto le daba customer_id a la orden y a sus
 * documentos, pero no a los equipos de la hoja: seguían `installed` con
 * customer_id en null. Desde el arreglo la conversión ya los arrastra; este
 * comando limpia los que se convirtieron antes. Usa exactamente la misma regla
 * (InventoryLedger::adoptInstallationCustomer): sólo equipos que siguen
 * instalados sin cliente, y les pone el cliente de su orden.
 *
 * Los prospectos que todavía NO se han convertido no se tocan: no hay a quién
 * asignárselos. El listado del inventario ya los muestra como «Prospecto: …».
 *
 * Arranca en dry-run: sin --apply sólo informa.
 */
class AdoptConvertedProspectDevices extends Command
{
    protected $signature = 'inventory:adopt-converted-prospect-devices
                            {--tenant= : Limitar a un tenant}
                            {--apply : Aplicar los cambios (sin este flag solo reporta)}';

    protected $description = 'Asigna al cliente los equipos instalados en órdenes de prospectos ya convertidos';

    public function handle(InventoryLedger $ledger): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $apply    = (bool) $this->option('apply');

        $rows = DB::table('inventory_device as d')
            ->join('installation_equipment as ie', 'ie.device_id', '=', 'd.id')
            ->join('customer_installations as ci', 'ci.id', '=', 'ie.installation_id')
            ->where('d.status', InventoryDevice::STATUS_INSTALLED)
            ->whereNull('d.customer_id')
            ->whereNotNull('ci.customer_id')
            ->whereColumn('ci.tenant_id', 'd.tenant_id')
            ->when($tenantId, fn ($q) => $q->where('d.tenant_id', $tenantId))
            ->orderBy('d.tenant_id')
            ->orderBy('d.id')
            ->get(['d.id as device_id', 'd.tenant_id', 'd.serial', 'ci.id as installation_id', 'ci.customer_id']);

        if ($rows->isEmpty()) {
            $this->info('No hay equipos instalados sin cliente en órdenes ya convertidas.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tenant', 'Equipo', 'Serial', 'Orden', 'Cliente'],
            $rows->map(fn ($r) => [$r->tenant_id, $r->device_id, $r->serial ?? '—', $r->installation_id, $r->customer_id])->all()
        );

        if (!$apply) {
            $this->warn("Dry-run: {$rows->count()} equipo(s) por asignar. Repite con --apply para aplicarlo.");

            return self::SUCCESS;
        }

        $adopted = $ledger->adoptInstallationCustomer($rows->pluck('installation_id'));
        $this->info("Asignados {$adopted} equipo(s) a su cliente.");

        return self::SUCCESS;
    }
}
