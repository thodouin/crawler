<?php

namespace App\Filament\Resources;

use App\Enums\ChunkStatus;
use App\Filament\Resources\ChunkResource\Pages;
use App\Filament\Resources\ChunkResource\RelationManagers;
use App\Models\Chunk;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ChunkResource extends Resource
{
    protected static ?string $model = Chunk::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';
    protected static ?string $navigationGroup = 'Gestion des Crawls';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('page_id')
                    ->relationship('page', 'url') // Affiche l'URL de la page
                    ->searchable()
                    ->preload()
                    ->required(),
                // site_id est dénormalisé, on peut l'afficher ou le remplir automatiquement
                // via un Observe sur page_id si on le souhaite. Pour l'instant, en lecture seule.
                Forms\Components\Select::make('site_id')
                    ->relationship('site', 'url')
                    ->label('Site (dénormalisé)')
                    ->disabled()
                    ->dehydrated(false) // Ne pas essayer de le sauvegarder si désactivé
                    ->placeholder('Auto-rempli depuis la page'),
                Forms\Components\Textarea::make('content')
                    ->label('Contenu du Chunk')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options(ChunkStatus::class)
                    ->required(),
                Forms\Components\DateTimePicker::make('embedded_at')
                    ->label('Embeddé le'),
                Forms\Components\TextInput::make('embedding_model_version')
                    ->label('Version Modèle Embedding')
                    ->maxLength(255),
                Forms\Components\Select::make('crawl_version_id')
                    ->label('Version de Crawl')
                    ->relationship('crawlVersion', 'version_name')
                    ->searchable()
                    ->preload(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('page.url')
                    ->label('Page')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn (Chunk $record): string => $record->page->url ?? ''),
                Tables\Columns\TextColumn::make('site.url')
                    ->label('Site')
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->tooltip(fn (Chunk $record): string => $record->site->url ?? '')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('content')
                    ->label('Contenu')
                    ->limit(50) // Limite la longueur affichée
                    ->searchable(),
                Tables\Columns\TextColumn::make('embedded_at')
                    ->label('Embeddé le')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(ChunkStatus::class),
                Tables\Filters\SelectFilter::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'url')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('page_id')
                    ->label('Page')
                    ->relationship('page', 'url') // Il faudra peut-être une logique plus avancée pour ce filtre
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChunks::route('/'),
            'create' => Pages\CreateChunk::route('/create'),
            'view' => Pages\ViewChunk::route('/{record}'),
            'edit' => Pages\EditChunk::route('/{record}/edit'),
        ];
    }
}