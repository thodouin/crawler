<?php
namespace App\Models;

use App\Enums\SiteStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Site extends Model {

    use HasFactory;

    protected $fillable = [
        'url', 
        'status_api', 
        'fastapi_job_id', 
        'last_sent_to_api_at', 
        'last_api_response'
    ];

    protected $casts = [
        'status_api' => SiteStatus::class, 
        'last_sent_to_api_at' => 'datetime'
    ];
    // Plus de relation crawlVersion() ici
}