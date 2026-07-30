<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Catálogo del modo de corte de un router: automático, manual o ninguno.
 *
 * El corte por mora se decidía comparando el NOMBRE literal de la fila
 * (`$cutType->name === 'Corte Automático'`). Eso es frágil por tres motivos que
 * no dan ningún error visible, sólo dejan de cortar:
 *
 *   1. La tilde. 'Corte Automatico' (sin tilde) no coincide, y el nombre se
 *      puede escribir desde la importación masiva de routers.
 *   2. Espacios sobrantes o mayúsculas distintas ('CORTE AUTOMATICO').
 *   3. Un renombrado desde la interfaz o desde SQL.
 *
 * En cualquiera de los tres casos el router cae en la rama "no action" y sus
 * clientes morosos nunca se cortan — fuga de ingreso silenciosa. Por eso la
 * comparación pasa por `matches()`, que normaliza antes de comparar, y el
 * llamador usa las constantes de esta clase en lugar de cadenas sueltas.
 */
class CutType extends Model
{
    protected $table = 'cut_type';

    /** El sistema suspende solo a los morosos de este router. */
    public const AUTOMATIC = 'Corte Automático';

    /** El sistema sólo encola los pendientes; suspende un operador. */
    public const MANUAL = 'Corte Manual';

    /** El router nunca corta. */
    public const NONE = 'Sin Corte';

    public const ALL = [self::AUTOMATIC, self::MANUAL, self::NONE];

    protected $fillable = [
        'name',
        'description',
    ];

    public $timestamps = false;

    public function routers()
    {
        return $this->hasMany(Router::class, 'cut_type_id');
    }

    /**
     * ¿El nombre dado corresponde al modo de corte esperado?
     *
     * Compara sin tildes, sin distinguir mayúsculas y sin espacios en los
     * extremos, de modo que 'corte automatico' y ' Corte Automático ' cuenten
     * ambos como {@see self::AUTOMATIC}.
     */
    public static function matches(?string $name, string $expected): bool
    {
        return $name !== null && self::normalize($name) === self::normalize($expected);
    }

    /** ¿Este router debe cortar de forma automática? */
    public function isAutomatic(): bool
    {
        return self::matches($this->name, self::AUTOMATIC);
    }

    /** ¿Este router deja el corte en manos de un operador? */
    public function isManual(): bool
    {
        return self::matches($this->name, self::MANUAL);
    }

    private static function normalize(string $value): string
    {
        return Str::lower(trim(Str::ascii($value)));
    }
}
