<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Unidad de medida del inventario (metro, unidad, rollo…).
 *
 * El producto guarda el NOMBRE de la unidad en `inventory_stock.unit`; esta
 * tabla es la lista de nombres válidos de cada empresa. Las unidades básicas
 * son del sistema: no se guardan, no se editan ni se borran, y están en todas
 * las empresas desde el primer día.
 */
class InventoryUnit extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_units';

    protected $fillable = ['name', 'decimals'];

    protected $casts = ['decimals' => 'integer'];

    /** Básicas del sistema: nombre → decimales sugeridos. */
    public const BUILT_IN = [
        'unidad'  => 0,
        'metro'   => 2,
        'rollo'   => 0,
        'caja'    => 0,
        'paquete' => 0,
    ];

    public static function isBuiltIn(string $name): bool
    {
        return array_key_exists(mb_strtolower(trim($name)), self::BUILT_IN);
    }

    /**
     * Catálogo completo de la empresa actual: básicas primero, luego las
     * propias por nombre. Cada fila dice si es del sistema y cuántos
     * productos la usan, para que la pantalla sepa qué ofrecer.
     *
     * @return Collection<int, array{id: int|null, name: string, decimals: int, built_in: bool, products_count: int}>
     */
    public static function catalog(): Collection
    {
        $uso = InventoryStock::query()
            ->whereNotNull('unit')
            ->get(['unit'])
            ->groupBy(fn (InventoryStock $s) => mb_strtolower(trim((string) $s->unit)))
            ->map->count();

        $basicas = collect(self::BUILT_IN)->map(fn (int $decimals, string $name) => [
            'id'             => null,
            'name'           => $name,
            'decimals'       => $decimals,
            'built_in'       => true,
            'products_count' => (int) ($uso[$name] ?? 0),
        ])->values();

        $propias = static::orderBy('name')->get()->map(fn (InventoryUnit $u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'decimals'       => $u->decimals,
            'built_in'       => false,
            'products_count' => (int) ($uso[mb_strtolower($u->name)] ?? 0),
        ]);

        return $basicas->concat($propias)->values();
    }

    /** ¿Existe esta unidad (básica o propia) en la empresa actual? Sin distinguir mayúsculas. */
    public static function existsNamed(string $name): bool
    {
        if (self::isBuiltIn($name)) {
            return true;
        }

        return static::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->exists();
    }

    /** Productos de la empresa actual que usan esta unidad. */
    public function productsUsing()
    {
        return InventoryStock::whereRaw('LOWER(unit) = ?', [mb_strtolower($this->name)]);
    }
}
