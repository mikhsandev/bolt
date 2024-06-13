<?php

namespace LaraZeus\Bolt\Filament\Resources\FormResource\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use LaraZeus\Bolt\BoltPlugin;
use LaraZeus\Bolt\Filament\Actions\SetResponseStatus;
use LaraZeus\Bolt\Filament\Exports\ResponseExporter;
use LaraZeus\Bolt\Filament\Resources\FormResource;
use LaraZeus\Bolt\Models\Field;
use LaraZeus\Bolt\Models\Form;
use LaraZeus\Bolt\Models\Response;

/**
 * @property Form $record.
 */
class ManageResponses extends ManageRelatedRecords
{
    protected static string $resource = FormResource::class;

    protected static string $relationship = 'responses';

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    public function table(Table $table): Table
    {
        $getUserModel = config('auth.providers.users.model')::getBoltUserFullNameAttribute();

        $mainColumns = [
            ImageColumn::make('user.avatar')
                ->sortable(false)
                ->searchable(false)
                ->label(__('Avatar'))
                ->circular()
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('user.' . $getUserModel)
                ->label(__('Name'))
                ->toggleable()
                ->sortable()
                ->default(__('guest'))
                ->searchable(),

            TextColumn::make('status')
                ->toggleable()
                ->sortable()
                ->badge()
                ->label(__('status'))
                ->formatStateUsing(fn ($state) => __(str($state)->title()->toString()))
                ->colors(BoltPlugin::getModel('FormsStatus')::pluck('key', 'color')->toArray())
                ->icons(BoltPlugin::getModel('FormsStatus')::pluck('key', 'icon')->toArray())
                ->grow(false)
                ->searchable('status'),

            TextColumn::make('notes')
                ->label(__('notes'))
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];

        /**
         * @var Field $field.
         */
        foreach ($this->record->fields->sortBy('ordering') as $field) {
            $getFieldTableColumn = (new $field->type)->TableColumn($field);

            if ($getFieldTableColumn !== null) {
                $mainColumns[] = $getFieldTableColumn;
            }
        }

        $mainColumns[] = TextColumn::make('created_at')
            ->sortable()
            ->searchable()
            ->dateTime()
            ->label(__('created at'))
            ->toggleable();

        return $table
            ->query(
                BoltPlugin::getModel('Response')::query()
                    ->where('form_id', $this->record->id)
                    ->with(['fieldsResponses'])
                    ->withoutGlobalScopes([
                        SoftDeletingScope::class,
                    ])
            )
            ->columns($mainColumns)
            ->actions([
                SetResponseStatus::make(),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\ForceDeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->filters([
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DatePicker::make('created_from'),
                        DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
                Tables\Filters\TrashedFilter::make(),
                SelectFilter::make('status')
                    ->options(BoltPlugin::getModel('FormsStatus')::query()->pluck('label', 'key'))
                    ->label(__('Status')),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
                Tables\Actions\RestoreBulkAction::make(),
                Tables\Actions\ForceDeleteBulkAction::make(),

                Tables\Actions\ExportBulkAction::make()
                    ->label(__('Export Responses'))
                    ->exporter(ResponseExporter::class),
            ])
            ->recordUrl(
                fn (Response $record): string => FormResource::getUrl('viewResponse', [
                    'record' => $record->form->slug,
                    'responseID' => $record,
                ]),
            );
    }

    public static function getNavigationLabel(): string
    {
        return __('Entries Report');
    }

    public function getTitle(): string
    {
        return __('Entries Report');
    }

    public function getTableRecords(): Collection | Paginator | CursorPaginator
    {
        $formId = $this->record->id;

        if ($translatableContentDriver = $this->makeFilamentTranslatableContentDriver()) {
            $setRecordLocales = function (Collection | Paginator | CursorPaginator $records) use ($translatableContentDriver): Collection | Paginator | CursorPaginator {
                $records->transform(fn (Model $record) => $translatableContentDriver->setRecordLocale($record));

                return $records;
            };
        } else {
            $setRecordLocales = fn (Collection | Paginator | CursorPaginator $records): Collection | Paginator | CursorPaginator => $records;
        }

        if ($this->cachedTableRecords) {
            return $setRecordLocales($this->cachedTableRecords);
        }

        $query = $this->getFilteredSortedTableQuery();

        // If role is not 'Admin Super', and not 'Admin': filter by user ID
        if (! auth()->user()->hasRole(['Admin Super', 'Admin'])) {
            $query->where('user_id', auth()->id());
        }

        if (
            (! $this->getTable()->isPaginated()) ||
            ($this->isTableReordering() && (! $this->getTable()->isPaginatedWhileReordering()))
        ) {
            return $setRecordLocales($this->cachedTableRecords = $this->hydratePivotRelationForTableRecords($query->get()));
        }

        return $setRecordLocales($this->cachedTableRecords = $this->hydratePivotRelationForTableRecords($this->paginateTableQuery($query)));
    }
}
