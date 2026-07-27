<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agences', function (Blueprint $table) {
            if (! Schema::hasColumn('agences', 'is_virtual')) {
                $table->boolean('is_virtual')->default(false)->after('actif');
            }
            if (! Schema::hasColumn('agences', 'parent_agence_id')) {
                $table->foreignId('parent_agence_id')
                    ->nullable()
                    ->after('is_virtual')
                    ->constrained('agences')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agences', function (Blueprint $table) {
            if (Schema::hasColumn('agences', 'parent_agence_id')) {
                $table->dropConstrainedForeignId('parent_agence_id');
            }
            if (Schema::hasColumn('agences', 'is_virtual')) {
                $table->dropColumn('is_virtual');
            }
        });
    }
};
