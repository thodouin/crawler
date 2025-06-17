<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Enums\WorkerStatus; // Vous devrez créer cet Enum

return new class extends Migration {
    public function up(): void {
        Schema::create('crawler_workers', function (Blueprint $table) {
            $table->id();
            $table->string('worker_identifier')->unique()->comment('ID unique fourni par l\'app Electron');
            $table->string('name')->comment('Nom convivial du worker/machine');
            $table->string('ip_address')->nullable();
            $table->integer('port')->nullable();
            $table->string('ws_protocol')->default('ws');
            $table->string('status')->default(WorkerStatus::OFFLINE->value);
            $table->foreignId('current_site_id_processing')->nullable()->constrained('sites')->onDelete('set null');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('crawler_workers');
    }
};