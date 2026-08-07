<?php

namespace App\Filament\Resources\CommunicationRooms;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\CommunicationRooms\Pages\ManageCommunicationRooms;
use App\Models\CommunicationRoom;
use App\Models\Site;
use App\Models\Team;
use App\Services\Communication\CommunicationService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommunicationRoomResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = CommunicationRoom::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = '내부 메신저 방 (Rooms)';

    protected static ?string $modelLabel = '메신저 방';

    protected static ?string $pluralModelLabel = '메신저 방';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 60;

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
        return ['company' => 'company_id', 'site' => 'site_id', 'team' => 'team_id', 'self' => null];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('방 유형')
                ->options(CommunicationRoom::TYPE_OPTIONS)
                ->default(CommunicationRoom::TYPE_SITE_CHAT)
                ->required(),
            TextInput::make('name')
                ->label('방 이름')
                ->required()
                ->maxLength(255),
            Select::make('site_id')
                ->label('현장')
                ->options(fn (): array => Site::query()->orderBy('code')->pluck('name', 'id')->all())
                ->searchable()
                ->preload(),
            Select::make('team_id')
                ->label('팀')
                ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload(),
            Toggle::make('is_read_only')
                ->label('공지 전용')
                ->helperText('켜면 관리자/현장관리자만 새 글을 올리고 직원은 댓글만 남깁니다.'),
            Select::make('status')
                ->label('상태')
                ->options(CommunicationRoom::STATUS_OPTIONS)
                ->default('active')
                ->required(),
            Textarea::make('description')
                ->label('설명')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('방 이름')->searchable()->sortable(),
                TextColumn::make('type')
                    ->label('유형')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CommunicationRoom::TYPE_OPTIONS[$state] ?? (string) $state)
                    ->sortable(),
                TextColumn::make('site.code')->label('현장')->badge()->sortable(),
                TextColumn::make('team.name')->label('팀')->toggleable(),
                TextColumn::make('members_count')->label('구성원')->counts('members')->badge()->sortable(),
                IconColumn::make('is_read_only')->label('공지전용')->boolean(),
                TextColumn::make('last_message_at')->label('최근 메시지')->since()->sortable(),
                TextColumn::make('status')->label('상태')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('유형')->options(CommunicationRoom::TYPE_OPTIONS),
                SelectFilter::make('site_id')
                    ->label('현장')
                    ->options(fn (): array => Site::query()->orderBy('code')->pluck('name', 'id')->all()),
                SelectFilter::make('status')->label('상태')->options(CommunicationRoom::STATUS_OPTIONS),
            ])
            ->recordActions([
                Action::make('syncMembers')
                    ->label('현장 직원 동기화')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (CommunicationRoom $record): bool => filled($record->site_id))
                    ->action(function (CommunicationRoom $record): void {
                        app(CommunicationService::class)->syncSiteRoomMembers($record->site);
                        Notification::make()->success()->title('현장 직원이 메신저 방에 동기화되었습니다.')->send();
                    }),
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
            'index' => ManageCommunicationRooms::route('/'),
        ];
    }
}
