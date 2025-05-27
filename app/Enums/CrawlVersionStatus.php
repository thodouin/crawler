<?php
namespace App\Enums;

enum CrawlVersionStatus: string {
    case PENDING = 'pending';
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}