<?php
namespace App\Enums;

enum PageStatus: string {
    case PENDING_CRAWL = 'pending_crawl';
    case CRAWLING = 'crawling';
    case CRAWLED = 'crawled';
    case PENDING_CHUNKING = 'pending_chunking';
    case CHUNKED = 'chunked';
    case FAILED = 'failed';
}