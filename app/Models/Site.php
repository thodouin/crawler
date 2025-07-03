<?php

namespace App\Models;

use App\Enums\SiteStatus;
use App\Enums\SitePriority;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Site extends Model 
{
    use HasFactory;

    protected $fillable = [
        'url', 'crawler_worker_id', 'status_api', 'priority', 'task_type',
        'fastapi_job_id', 'last_sent_to_api_at', 'last_api_response',
        'existence_status', 'last_existence_check_at', 'max_depth',
        'qna_results',
    ];
    protected $casts = [
        'status_api' => SiteStatus::class,
        'priority' => SitePriority::class,
        'last_sent_to_api_at' => 'datetime',
        'qna_results' => 'array'
    ];
    // Relation vers le CrawlerWorker assigné
    public function crawlerWorker(): BelongsTo 
    {
        return $this->belongsTo(CrawlerWorker::class);
    }
}