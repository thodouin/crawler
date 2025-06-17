<?php

namespace App\Models;

use App\Enums\WorkerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // Pour le site en cours
use Illuminate\Database\Eloquent\Relations\HasMany;   // Pour tous les sites traités par ce worker

class CrawlerWorker extends Model 
{
    use HasFactory;
    
    protected $fillable = [
        'worker_identifier', 'name', 'ip_address', 'port', 'ws_protocol',
        'status', 'current_site_id_processing', 'last_heartbeat_at',
    ];

    protected $casts = [
        'status' => WorkerStatus::class,
        'last_heartbeat_at' => 'datetime',
        'port' => 'integer',
    ];

    public function processingSite(): BelongsTo 
    {
        return $this->belongsTo(Site::class, 'current_site_id_processing');
    }

    public function sites(): HasMany { // Sites historiquement assignés à ce worker
        return $this->hasMany(Site::class);
    }
}