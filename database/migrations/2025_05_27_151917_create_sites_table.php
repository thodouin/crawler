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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->string('status')->default(App\Enums\SiteStatus::PENDING_CRAWL->value);
            $table->timestamp('last_crawled_at')->nullable();
            $table->foreignId('crawl_version_id')->nullable()->constrained('crawl_versions')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
