<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public ?string $maxContentWidth = 'full';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    #[On('echo:user-status-channel,UserRowShouldRefresh')]
    public function onUserChange(array $eventData): void 
    {
        \Illuminate\Support\Facades\Log::info('ListUsers Livewire: Received UserRowShouldRefresh via Echo. Refreshing table.', $eventData);
        $this->dispatch('$refresh');
    }
}
