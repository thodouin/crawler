<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawler_workers', function (Blueprint $table) {
            // Option 1: Colonne JSON (recommandé pour la flexibilité)
            $table->json('system_info')->nullable()->after('last_heartbeat_at');

            // $table->string('os_platform')->nullable()->after('last_heartbeat_at');
            // $table->string('os_type')->nullable()->after('os_platform');
            // $table->string('os_release')->nullable()->after('os_type');
            // $table->decimal('total_memory_gb', 8, 2)->nullable()->after('os_release'); // Ex: 16.00 GB
            // $table->decimal('free_memory_gb', 8, 2)->nullable()->after('total_memory_gb');
            // $table->integer('cpu_cores')->nullable()->after('free_memory_gb');
            // $table->string('cpu_model')->nullable()->after('cpu_cores');
        });
    }

    public function down(): void
    {
        Schema::table('crawler_workers', function (Blueprint $table) {
            $table->dropColumn('system_info'); // Si Option 1
            // Ou $table->dropColumn(['os_platform', 'os_type', ...]); // Si Option 2
        });
    }
};