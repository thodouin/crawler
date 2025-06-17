<?php

namespace App\Filament\Resources\SiteResource\Pages;

use App\Filament\Resources\SiteResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth; // Importer Auth

class CreateSite extends CreateRecord
{
    protected static string $resource = SiteResource::class;

    public ?string $maxContentWidth = 'full';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Associer automatiquement le site à l'utilisateur connecté lors de la création
        $data['user_id'] = Auth::id();

        return $data;
    }

    // Optionnel: si vous voulez rediriger vers une page spécifique après la création
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}