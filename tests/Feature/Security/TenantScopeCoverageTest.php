<?php

namespace Tests\Feature\Security;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guardia estructural del aislamiento entre tenants.
 *
 * El aislamiento de ISPWatch vive en la capa de aplicación: si una tabla tiene
 * tenant_id, la frontera la pone el global scope de BelongsToTenant. El
 * problema de ese diseño es que la falla es silenciosa — un modelo nuevo sin el
 * trait no lanza ningún error, no rompe ninguna prueba y funciona perfecto en
 * desarrollo con un solo tenant. Simplemente devuelve de más.
 *
 * Así fue como `Payment` terminó exponiendo el listado y la exportación de
 * recaudos de todos los ISP a la vez. Este test existe para que el próximo
 * modelo que se agregue sin scope falle en CI en vez de en producción.
 *
 * Cuando un modelo NO deba llevar el scope, la salida no es quitarlo del test:
 * es agregarlo abajo con el motivo escrito. Una excepción justificada y visible
 * es sana; una excepción por olvido es una fuga.
 */
class TenantScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modelos con columna tenant_id que a propósito NO llevan el global scope.
     *
     * @var array<class-string<Model>, string>
     */
    private const EXCEPCIONES_JUSTIFICADAS = [
        \App\Models\User::class =>
            'El login busca por email_tenant ANTES de saber de qué tenant es quien entra: '
            . 'con el scope puesto, nadie podría autenticarse. El aislamiento de users se '
            . 'hace explícito en cada controlador (User::where(tenant_id, ...)->findOrFail).',

        \App\Models\Role::class =>
            'Los roles globales del sistema viven con tenant_id NULL y se listan como '
            . 'is_global para todos los tenants. El scope los escondería y dejaría a los '
            . 'usuarios sin rol asignable.',

        \App\Models\CustomerProfile::class =>
            'Su aislamiento lo garantiza el User: toda lectura del perfil va precedida de '
            . 'User::where(tenant_id, ...)->findOrFail($id), así que un id ajeno muere antes '
            . 'de llegar al perfil. La columna tenant_id existe como insumo de Row Level '
            . 'Security (migración 2026_08_15_100000), no como filtro de aplicación.',

        \App\Models\BulkProvisionRun::class =>
            'Los jobs en cola leen y escriben la corrida sin sesión autenticada. El scoping '
            . 'por tenant se hace explícito en CustomerProfileController (bulkProvisionStatus '
            . 'y startAsyncProvision filtran por tenant_id a mano).',

        \App\Models\Billing::class =>
            'A una configuración de facturación sólo se llega por router.billing_router_id, y '
            . 'Router sí lleva el scope: la frontera está un nivel más arriba. Su tenant_id '
            . 'quedó en NULL en las filas anteriores a que RouterController lo poblara; el '
            . 'backfill va en 2026_08_15_100100 y activar el scope antes escondería toda la '
            . 'configuración de cobro. Ver MEJORAS_RECOMENDADAS.md.',

        \App\Models\InvoiceType::class =>
            'Los tipos de factura del sistema viven con tenant_id NULL y deben verse desde '
            . 'todos los tenants: scopeForTenant() hace whereNull(tenant_id) OR tenant. Es el '
            . 'mismo caso que Role — el global scope los escondería y rompería la facturación.',

        \App\Models\ApiClient::class =>
            'El tenant operador administra los consumidores de API de TODOS los tenants desde '
            . 'una sola pantalla, así que index() los lista a propósito sin filtrar. La '
            . 'frontera la pone ApiClientController::authorizeOperator() y, en el camino de '
            . 'auto-servicio, el filtro explícito por tenant de TenantApiKeyController.',

        \App\Models\PartnerEvent::class =>
            'Exclusión documentada en el propio modelo: el tenant lo fija siempre quien '
            . 'registra el evento a partir del modelo que cambió, y los lectores (la API '
            . 'pública) filtran por el tenant de la llave de forma explícita.',
    ];

    public function test_todo_modelo_con_tenant_id_lleva_scope_o_esta_justificado(): void
    {
        $desprotegidos = [];

        foreach ($this->modelosDeLaApp() as $clase) {
            if (!$this->tieneColumnaTenant($clase)) {
                continue;
            }

            if ($this->llevaScopeDeTenant($clase)) {
                continue;
            }

            if (array_key_exists($clase, self::EXCEPCIONES_JUSTIFICADAS)) {
                continue;
            }

            $desprotegidos[] = $clase;
        }

        $this->assertSame([], $desprotegidos, sprintf(
            "Estos modelos tienen columna tenant_id pero ninguna frontera automática:\n  - %s\n\n"
            . "Agrégales `use BelongsToTenant;` o, si de verdad no deben llevarlo, "
            . "declara el motivo en TenantScopeCoverageTest::EXCEPCIONES_JUSTIFICADAS.",
            implode("\n  - ", $desprotegidos)
        ));
    }

    /**
     * La lista de excepciones también se pudre: un modelo al que después le
     * ponen el trait, o al que le quitan la columna, deja una entrada mintiendo
     * sobre por qué algo es seguro. Peor que no tener la lista.
     */
    public function test_la_lista_de_excepciones_sigue_siendo_cierta(): void
    {
        foreach (array_keys(self::EXCEPCIONES_JUSTIFICADAS) as $clase) {
            $this->assertTrue($this->tieneColumnaTenant($clase), sprintf(
                '%s está en la lista de excepciones, pero su tabla ya no tiene tenant_id. '
                . 'Sácalo de la lista.',
                $clase
            ));

            $this->assertFalse($this->llevaScopeDeTenant($clase), sprintf(
                '%s ya lleva BelongsToTenant, así que la excepción sobra. Sácalo de la lista.',
                $clase
            ));
        }
    }

    // ── Andamiaje ──────────────────────────────────────────────────────────

    /** @return list<class-string<Model>> */
    private function modelosDeLaApp(): array
    {
        $clases = [];

        foreach (glob(app_path('Models/*.php')) as $archivo) {
            $clase = 'App\\Models\\' . pathinfo($archivo, PATHINFO_FILENAME);

            if (!class_exists($clase)) {
                continue;
            }

            $reflexion = new ReflectionClass($clase);

            if ($reflexion->isAbstract() || !$reflexion->isSubclassOf(Model::class)) {
                continue;
            }

            $clases[] = $clase;
        }

        sort($clases);

        return $clases;
    }

    /** @param class-string<Model> $clase */
    private function tieneColumnaTenant(string $clase): bool
    {
        $tabla = (new $clase)->getTable();

        // hasTable primero: un modelo puede apuntar a una tabla de un paquete
        // que la suite no migra, y hasColumn sobre algo inexistente revienta.
        return Schema::hasTable($tabla) && Schema::hasColumn($tabla, 'tenant_id');
    }

    /** @param class-string<Model> $clase */
    private function llevaScopeDeTenant(string $clase): bool
    {
        return in_array(BelongsToTenant::class, class_uses_recursive($clase), true);
    }
}
