<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Sectorial extends Model
{
    use BelongsToTenant;

    protected $table = 'sectorial';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'element_type',
        'parent_id',
        'tenant_id',
        'ip',
        'type',
        'split_ratio',
        'ports_total',
        'pon_port',
        'vlan',
        'user_rb',
        'pass_rb',
        'zona_id',
        'frequency',
        'node_tower',
        'comments',
        'ssid',
        'coordinates',
        'coverage_radius_meters',
        'antenna_type',
    ];

    protected $appends = ['ports_used', 'ports_capacity', 'ports_free'];

    // Infraestructura inalámbrica / genérica
    public const ELEMENT_SECTORIAL = 'sectorial';
    public const ELEMENT_SWITCH    = 'switch';
    public const ELEMENT_NODO      = 'nodo';

    // Planta externa de fibra (FTTH/GPON)
    public const ELEMENT_OLT      = 'olt';
    public const ELEMENT_SPLITTER = 'splitter';
    public const ELEMENT_NAP      = 'nap';
    public const ELEMENT_MUFA     = 'mufa';

    /** Tipos de elemento de red válidos. */
    public const ELEMENT_TYPES = [
        self::ELEMENT_SECTORIAL,
        self::ELEMENT_SWITCH,
        self::ELEMENT_NODO,
        self::ELEMENT_OLT,
        self::ELEMENT_SPLITTER,
        self::ELEMENT_NAP,
        self::ELEMENT_MUFA,
    ];

    /** Tipos que forman la planta de fibra (cuelgan en árbol vía parent_id). */
    public const FIBER_TYPES = [
        self::ELEMENT_OLT,
        self::ELEMENT_SPLITTER,
        self::ELEMENT_NAP,
        self::ELEMENT_MUFA,
    ];

    /**
     * Conteo de hijos/clientes inyectado por el controlador (groupBy) para
     * evitar N+1. Cuando está presente se usa para ports_used.
     */
    public ?int $children_count = null;
    public ?int $clients_count = null;

    public function photos()
    {
        return $this->hasMany(SectorialPhoto::class)->orderBy('created_at', 'desc');
    }

    public function notes()
    {
        return $this->hasMany(SectorialNote::class)->orderBy('created_at', 'desc');
    }

    public function history()
    {
        return $this->hasMany(SectorialHistory::class)->orderBy('created_at', 'desc');
    }

    public function tickets()
    {
        return $this->hasMany(SupportTicket::class, 'sectorial_id')->orderBy('created_at', 'desc');
    }

    /** Elemento del que cuelga este (ej. el splitter padre de un NAP). */
    public function parent()
    {
        return $this->belongsTo(Sectorial::class, 'parent_id');
    }

    /** Elementos que cuelgan de este (splitters/NAPs aguas abajo). */
    public function children()
    {
        return $this->hasMany(Sectorial::class, 'parent_id');
    }

    /** Clientes conectados directamente a este elemento (su NAP/sectorial). */
    public function customers()
    {
        return $this->hasMany(CustomerProfile::class, 'sectorial_id');
    }

    /** ¿Es un elemento de planta de fibra? */
    public function getIsFiberAttribute(): bool
    {
        return in_array($this->element_type, self::FIBER_TYPES, true);
    }

    /**
     * Capacidad de puertos del elemento:
     *  - splitter: derivada del split_ratio ("1:8" -> 8).
     *  - resto (nap/olt/...): ports_total.
     * Devuelve null si no aplica (no se puede calcular ocupación).
     */
    public function getPortsCapacityAttribute(): ?int
    {
        if ($this->element_type === self::ELEMENT_SPLITTER && $this->split_ratio) {
            if (preg_match('/(\d+)\s*$/', (string) $this->split_ratio, $m)) {
                return (int) $m[1];
            }
        }

        return $this->ports_total !== null ? (int) $this->ports_total : null;
    }

    /**
     * Puertos ocupados = hijos en el árbol + clientes conectados.
     * Usa los conteos inyectados por el controlador (groupBy) si existen para
     * evitar N+1; si no, cae a las relaciones cargadas / consultas directas.
     */
    public function getPortsUsedAttribute(): int
    {
        $children = $this->children_count;
        if ($children === null) {
            $children = $this->relationLoaded('children')
                ? $this->children->count()
                : (int) $this->children()->count();
        }

        $clients = $this->clients_count;
        if ($clients === null) {
            $clients = $this->relationLoaded('customers')
                ? $this->customers->count()
                : (int) $this->customers()->count();
        }

        return (int) $children + (int) $clients;
    }

    /** Puertos libres (capacidad - usados); null si no hay capacidad definida. */
    public function getPortsFreeAttribute(): ?int
    {
        $capacity = $this->ports_capacity;
        if ($capacity === null) {
            return null;
        }

        return max(0, $capacity - $this->ports_used);
    }


    /**
     * Accessor para convertir coordinates (geography) a array lat/lng
     */
    public function getCoordinatesAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Usar ST_AsGeoJSON para convertir el punto geography a JSON
            $result = DB::select(
                "SELECT ST_AsGeoJSON(?)::json->'coordinates' as coords",
                [$value]
            );

            if (!empty($result) && isset($result[0]->coords)) {
                $coords = json_decode($result[0]->coords);
                return [
                    'lng' => $coords[0], // PostGIS devuelve [lng, lat]
                    'lat' => $coords[1]
                ];
            }
        } catch (\Exception $e) {
            Log::error('Error parsing coordinates: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Mutator para convertir array lat/lng a geography point
     */
    public function setCoordinatesAttribute($value)
    {
        if (empty($value)) {
            $this->attributes['coordinates'] = null;
            return;
        }

        try {
            // Si viene como string JSON, parsearlo
            if (is_string($value)) {
                $value = json_decode($value, true);
            }

            // Si tiene lat y lng, crear el punto geography
            if (is_array($value) && isset($value['lat']) && isset($value['lng'])) {
                // PostGIS usa (lng, lat), no (lat, lng)
                $result = DB::select(
                    "SELECT ST_GeogFromText(?) as geog",
                    ["POINT({$value['lng']} {$value['lat']})"]
                );

                $this->attributes['coordinates'] = $result[0]->geog ?? null;
            } else {
                $this->attributes['coordinates'] = null;
            }
        } catch (\Exception $e) {
            Log::error('Error setting coordinates: ' . $e->getMessage());
            $this->attributes['coordinates'] = null;
        }
    }
}
