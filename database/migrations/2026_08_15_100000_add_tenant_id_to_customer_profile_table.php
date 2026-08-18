<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * customer_profile es la única tabla central que nunca llevó tenant_id: su
 * aislamiento se apoya en `users`, y cada consulta del panel valida primero
 * `User::where('tenant_id', ...)->findOrFail($id)` antes de leer el perfil.
 * Eso funciona y no hay fuga hoy, pero deja la frontera dependiendo de que
 * ningún sitio futuro olvide el paso previo.
 *
 * La columna se agrega como prerrequisito de Row Level Security: una política
 * de Postgres tiene que poder decidir con lo que hay EN LA FILA. Sin tenant_id
 * aquí, la política de customer_profile tendría que resolverse con una
 * subconsulta contra users en cada fila leída — más lento y, peor, frágil: si
 * la subconsulta se escribe mal, la política deja de aislar sin avisar.
 *
 * Se deja nullable a propósito. Marcarla NOT NULL en la misma migración
 * fallaría con cualquier perfil huérfano (usuario borrado sin borrar el
 * perfil), y una migración que revienta a mitad en `public` es peor que una
 * columna nullable: el modelo rellena el valor al crear (ver CustomerProfile)
 * y la verificación de huérfanos se hace aparte, con datos a la vista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('user_id');
        });

        // Backfill con subconsulta correlacionada: es la única forma de UPDATE
        // ... FROM que entienden igual PostgreSQL y SQLite, y las migraciones
        // de este proyecto tienen que correr en ambos (la suite usa SQLite).
        DB::table('customer_profile')
            ->whereNull('tenant_id')
            ->update([
                'tenant_id' => DB::raw(
                    '(select users.tenant_id from users where users.id = customer_profile.user_id)'
                ),
            ]);

        Schema::table('customer_profile', function (Blueprint $table) {
            // El acceso real es "los clientes de esta sede": tenant primero.
            $table->index(['tenant_id', 'user_id'], 'customer_profile_tenant_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_profile', function (Blueprint $table) {
            $table->dropIndex('customer_profile_tenant_user_idx');
            $table->dropColumn('tenant_id');
        });
    }
};
