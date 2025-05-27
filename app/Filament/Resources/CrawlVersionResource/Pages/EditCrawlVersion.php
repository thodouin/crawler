<?php

namespace App\Filament\Resources\CrawlVersionResource\Pages;

use App\Filament\Resources\CrawlVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCrawlVersion extends EditRecord
{
    protected static string $resource = CrawlVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
