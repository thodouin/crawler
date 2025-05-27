<?php

namespace App\Filament\Resources;

use App\Enums\SiteStatus;
use App\Filament\Resources\SiteResource\Pages;
use App\Filament\Resources\SiteResource\RelationManagers;
use App\Models\Site;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?string $navigationGroup = 'Gestion des Crawls';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('url')
                    ->label('URL du Site')
                    ->url()
                    ->required()
                    ->maxLength(2048) // Correspond à la validation de l'API
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options(SiteStatus::class)
                    ->required(),
                Forms\Components\DateTimePicker::make('last_crawled_at')
                    ->label('Dernier Crawl le'),
                Forms\Components\Select::make('crawl_version_id')
                    ->label('Version de Crawl')
                    ->relationship('crawlVersion', 'version_name') // Affiche 'version_name' de CrawlVersion
                    ->searchable()
                    ->preload(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->tooltip(fn (Site $record): string => $record->url),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_crawled_at')
                    ->label('Dernier Crawl')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('crawlVersion.version_name')
                    ->label('Version Crawl')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ajouté le')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(SiteStatus::class),
                Tables\Filters\SelectFilter::make('crawl_version_id')
                    ->label('Version de Crawl')
                    ->relationship('crawlVersion', 'version_name')
                    ->searchable()
                    ->preload(),
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
            RelationManagers\PagesRelationManager::class,
            // RelationManagers\ChunksRelationManager::class, // Peut être ajouté ici aussi
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'view' => Pages\ViewSite::route('/{record}'),
            'edit' => Pages\EditSite::route('/{record}/edit'),
        ];
    }
}