<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            // Añadir role si no existe
            if (!Schema::hasColumn('organization_user', 'role')) {
                $table->string('role')->default('member')->after('user_id');
            }
        });
        
        // Asignar roles predeterminados a los registros existentes si es necesario
        if (Schema::hasColumn('organization_user', 'role')) {
            // Obtener todos los usuarios en la tabla pivote que no tienen un rol asignado
            $pivotRecords = DB::table('organization_user')
                ->whereNull('role')
                ->get();
            
            foreach ($pivotRecords as $record) {
                $isFirstUser = DB::table('organization_user')
                    ->where('organization_id', $record->organization_id)
                    ->orderBy('created_at')
                    ->first()->user_id === $record->user_id;
                
                // El primer usuario que se unió se convierte en admin
                $role = $isFirstUser ? 'admin' : 'member';
                
                DB::table('organization_user')
                    ->where('organization_id', $record->organization_id)
                    ->where('user_id', $record->user_id)
                    ->update(['role' => $role]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_user', function (Blueprint $table) {
            // Eliminar role si existe
            if (Schema::hasColumn('organization_user', 'role')) {
                $table->dropColumn('role');
            }
        });
    }
};