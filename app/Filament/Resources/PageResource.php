<?php

namespace App\Filament\Resources;

use App\Enums\PageStatus;
use App\Filament\Resources\PageResource\Pages;
use App\Filament\Resources\PageResource\RelationManagers;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Gestion des Crawls';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('site_id')
                    ->relationship('site', 'url')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('url')
                    ->label('URL de la Page')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->label('Statut')
                    ->options(PageStatus::class)
                    ->required(),
                Forms\Components\DateTimePicker::make('last_crawled_at')
                    ->label('Dernier Crawl le'),
                Forms\Components\DateTimePicker::make('sitemap_last_updated_at')
                    ->label('MàJ Sitemap le'),
                Forms\Components\TextInput::make('content_hash')
                    ->label('Hash du Contenu')
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
                Tables\Columns\TextColumn::make('site.url')
                    ->label('Site')
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->tooltip(fn (Page $record): string => $record->site->url ?? ''),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL Page')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn (Page $record): string => $record->url),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_crawled_at')
                    ->label('Dernier Crawl')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('crawlVersion.version_name')
                    ->label('Version Crawl')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(PageStatus::class),
                Tables\Filters\SelectFilter::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'url')
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
            RelationManagers\ChunksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'view' => Pages\ViewPage::route('/{record}'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}