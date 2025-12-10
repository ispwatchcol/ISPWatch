<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Sectorial extends Model
{
    protected $table = 'sectorial';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'type',
        'user_rb',
        'pass_rb',
        'zona_id',
        'frequency',
        'node_tower',
        'comments',
        'ssid',
        'coordinates',
    ];


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
