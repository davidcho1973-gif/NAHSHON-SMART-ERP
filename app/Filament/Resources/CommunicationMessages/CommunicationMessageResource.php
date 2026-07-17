<?php

namespace App\Filament\Resources\CommunicationMessages;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\CommunicationMessages\Pages\ManageCommunicationMessages;
use App\Models\CommunicationMessage;
use App\Models\CommunicationRoom;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommunicationMessageResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = CommunicationMessage::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';

    protected static ?string $navigationLabel = '내부 메시지 (Messages)';

    protected static ?string $modelLabel = '메시지';

    protected static ?string $pluralModelLabel = '메시지';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 61;

    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager'];
    }

    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => 'site_id', 'team' => 'team_id', 'self' => 'sender_employee_id'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('communication_room_id')
                ->label('메신저 방')
                ->options(fn (): array => CommunicationRoom::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->required(),
            Select::make('kind')
                ->label('메시지 유형')
                ->options(CommunicationMessage::KIND_OPTIONS)
                ->default(CommunicationMessage::KIND_MESSAGE)
                ->required(),
            TextInput::make('title')
                ->label('제목')
                ->maxLength(255),
            Textarea::make('body')
                ->label('내용')
                ->rows(5)
                ->required()
                ->columnSpanFull(),
            Select::make('priority')
                ->label('중요도')
                ->options(CommunicationMessage::PRIORITY_OPTIONS)
                ->default('normal')
                ->required(),
            Toggle::make('is_pinned')
                ->label('상단 고정'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->columns([
                TextColumn::make('room.name')->label('방')->searchable()->sortable(),
                TextColumn::make('kind')
                    ->label('유형')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CommunicationMessage::KIND_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('title')->label('제목')->limit(32)->searchable(),
                TextColumn::make('body')->label('내용')->limit(54)->searchable(),
                TextColumn::make('senderEmployee.name')->label('작성자')->toggleable(),
                TextColumn::make('reads_count')->label('읽음')->counts('reads')->badge()->sortable(),
                IconColumn::make('is_pinned')->label('고정')->boolean(),
                TextColumn::make('sent_at')->label('발송시각')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->label('유형')->options(CommunicationMessage::KIND_OPTIONS),
                SelectFilter::make('communication_room_id')
                    ->label('방')
                    ->options(fn (): array => CommunicationRoom::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCommunicationMessages::route('/'),
        ];
    }
}
