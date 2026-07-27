<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agences', function (Blueprint $table) {
            if (! Schema::hasColumn('agences', 'kiosk_serial_number')) {
                $table->string('kiosk_serial_number', 128)->nullable()->after('parent_agence_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agences', function (Blueprint $table) {
            if (Schema::hasColumn('agences', 'kiosk_serial_number')) {
                $table->dropColumn('kiosk_serial_number');
            }
        });
    }
};
