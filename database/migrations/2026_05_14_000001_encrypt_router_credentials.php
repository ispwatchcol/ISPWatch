<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Crear columnas nuevas para las credenciales encriptadas
        Schema::table('router', function (Blueprint $table) {
            $table->text('vpn_username_encrypted')->nullable()->after('vpn_password')->comment('VPN username - encrypted');
            $table->text('vpn_password_encrypted')->nullable()->after('vpn_username_encrypted')->comment('VPN password - encrypted');
            $table->text('user_rb_encrypted')->nullable()->after('vpn_password_encrypted')->comment('Mikrotik user - encrypted');
            $table->text('password_rb_encrypted')->nullable()->after('user_rb_encrypted')->comment('Mikrotik password - encrypted');
        });

        // Migrar datos de las columnas antiguas (sin encriptación aún)
        // Los datos serán encriptados automáticamente cuando se acceda vía modelo
        DB::statement('UPDATE router SET vpn_username_encrypted = vpn_username WHERE vpn_username IS NOT NULL');
        DB::statement('UPDATE router SET vpn_password_encrypted = vpn_password WHERE vpn_password IS NOT NULL');
        DB::statement('UPDATE router SET user_rb_encrypted = user_rb WHERE user_rb IS NOT NULL');
        DB::statement('UPDATE router SET password_rb_encrypted = password_rb WHERE password_rb IS NOT NULL');

        // Veremos si las columnas antiguas se pueden eliminar en una migración posterior
        // (después de verificar que todo funciona)
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('router', function (Blueprint $table) {
            $table->dropColumn([
                'vpn_username_encrypted',
                'vpn_password_encrypted',
                'user_rb_encrypted',
                'password_rb_encrypted',
            ]);
        });
    }
};
