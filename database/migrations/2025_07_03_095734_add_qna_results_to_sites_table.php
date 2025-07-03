<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sites', function (Blueprint $table) {
            // Colonne pour stocker le résultat complet de l'IA au format JSON
            $table->json('qna_results')->nullable()->after('questions_to_ask');
        });
    }
    public function down(): void {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('qna_results');
        });
    }
};