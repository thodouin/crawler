<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Pour stocker le résultat de la vérification d'existence
            $table->string('existence_status')->nullable()->after('status_api'); 
            // Pour stocker la date de la dernière vérification
            $table->timestamp('last_existence_check_at')->nullable()->after('existence_status');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('existence_status');
            $table->dropColumn('last_existence_check_at');
        });
    }
};