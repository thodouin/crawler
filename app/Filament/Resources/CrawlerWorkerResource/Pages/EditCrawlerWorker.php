<?php

namespace App\Filament\Resources\CrawlerWorkerResource\Pages;

use App\Filament\Resources\CrawlerWorkerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCrawlerWorker extends EditRecord
{
    protected static string $resource = CrawlerWorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
