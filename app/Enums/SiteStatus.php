<?php
namespace App\Enums;

enum SiteStatus: string {
    case PENDING_CRAWL = 'pending_crawl';
    case CRAWLING = 'crawling';
    case CRAWLED = 'crawled';
    case FAILED = 'failed';
}