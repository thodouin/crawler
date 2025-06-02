<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('user_id')
                  ->after('id')
                  ->nullable() // <--- AJOUTER CECI
                  ->constrained()
                  ->onDelete('cascade'); // Ou 'set null' si vous voulez garder les sites si l'utilisateur est supprimé
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};