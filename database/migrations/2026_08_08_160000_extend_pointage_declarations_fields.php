<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pointage_declarations', function (Blueprint $table) {
            if (! Schema::hasColumn('pointage_declarations', 'date_fin')) {
                $table->date('date_fin')->nullable()->after('date_concernee');
            }
            if (! Schema::hasColumn('pointage_declarations', 'heure_debut')) {
                $table->string('heure_debut', 8)->nullable()->after('date_fin');
            }
            if (! Schema::hasColumn('pointage_declarations', 'heure_fin')) {
                $table->string('heure_fin', 8)->nullable()->after('heure_debut');
            }
            if (! Schema::hasColumn('pointage_declarations', 'lieu')) {
                $table->string('lieu', 255)->nullable()->after('heure_fin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pointage_declarations', function (Blueprint $table) {
            foreach (['date_fin', 'heure_debut', 'heure_fin', 'lieu'] as $col) {
                if (Schema::hasColumn('pointage_declarations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
