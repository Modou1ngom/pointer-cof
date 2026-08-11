<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendances')) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->change();
            }
            if (Schema::hasColumn('attendances', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendances')) {
            return;
        }

        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable(false)->change();
            }
            if (Schema::hasColumn('attendances', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable(false)->change();
            }
        });
    }
};
