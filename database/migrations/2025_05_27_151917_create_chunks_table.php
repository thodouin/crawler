<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete(); // Dénormalisé
            $table->text('content');
            $table->string('status')->default(App\Enums\ChunkStatus::PENDING_EMBEDDING->value);
            $table->timestamp('embedded_at')->nullable();
            $table->string('embedding_model_version')->nullable();
            $table->foreignId('crawl_version_id')->nullable()->constrained('crawl_versions')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
