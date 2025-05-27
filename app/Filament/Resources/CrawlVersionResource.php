<?php

namespace App\Filament\Resources;

use App\Enums\CrawlVersionStatus;
use App\Filament\Resources\CrawlVersionResource\Pages;
use App\Filament\Resources\CrawlVersionResource\RelationManagers;
use App\Models\CrawlVersion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CrawlVersionResource extends Resource
{
    protected static ?string $model = CrawlVersion::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Gestion des Crawls';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('version_name')
                    ->label('Nom de la Version')
                    ->maxLength(255),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options(CrawlVersionStatus::class) // Utilise l'Enum pour les options
                    ->required(),
                Forms\Components\DateTimePicker::make('started_at')
                    ->label('Débuté le'),
                Forms\Components\DateTimePicker::make('completed_at')
                    ->label('Terminé le'),
                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('version_name')
                    ->label('Nom de la Version')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge() // Affiche comme un badge
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Débuté le')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->label('Terminé le')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Créé le')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(CrawlVersionStatus::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SitesRelationManager::class,
            // Tu pourras créer ces relation managers plus tard
            // RelationManagers\PagesRelationManager::class,
            // RelationManagers\ChunksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrawlVersions::route('/'),
            'create' => Pages\CreateCrawlVersion::route('/create'),
            'view' => Pages\ViewCrawlVersion::route('/{record}'),
            'edit' => Pages\EditCrawlVersion::route('/{record}/edit'),
        ];
    }
}