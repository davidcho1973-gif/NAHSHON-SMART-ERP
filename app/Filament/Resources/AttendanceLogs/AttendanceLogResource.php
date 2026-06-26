<?php

namespace App\Filament\Resources\AttendanceLogs;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\AttendanceLogs\Pages\ManageAttendanceLogs;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AttendanceLogResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager', 'payroll'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager'];
    }

    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => 'site_id', 'team' => 'team_id', 'self' => 'employee_id'];
    }

    protected static ?string $model = AttendanceLog::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = '출퇴근 기록';

    protected static ?string $modelLabel = '출퇴근 기록';

    protected static ?string $pluralModelLabel = '출퇴근 기록';

    protected static string | \UnitEnum | null $navigationGroup = 'HUMAN RESOURCE';

    protected static ?int $navigationSort = 3;

    public const EVENT_TYPES = [
        'clock_in' => '출근',
        'clock_out' => '퇴근',
    ];

    public const STATUSES = [
        'approved' => '승인완료',
        'pending' => '대기중',
        'rejected' => '반려',
    ];

    public const SOURCES = [
        'web_portal' => '웹 포탈',
        'team_qr' => 'QR 스캔',
        'nfc_reader' => 'NFC 리더',
        'gps' => 'GPS',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('employee_id')
                ->label('직원')
                ->options(fn (): array => Employee::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required(),
            Select::make('event_type')
                ->label('구분')
                ->options(self::EVENT_TYPES)
                ->required(),
            DateTimePicker::make('event_at')
                ->label('기록 시각')
                ->seconds(true)
                ->required(),
            Select::make('status')
                ->label('상태')
                ->options(self::STATUSES)
                ->default('approved')
                ->required(),
            Select::make('source')
                ->label('기록 방식')
                ->options(self::SOURCES)
                ->default('web_portal'),
            Textarea::make('notes')
                ->label('비고')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('event_at', 'desc')
            ->columns([
                TextColumn::make('employee.name')->label('직원')->searchable()->sortable(),
                TextColumn::make('attendance_date')->label('날짜')->date()->sortable(),
                TextColumn::make('event_at')->label('시각')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('event_type')
                    ->label('구분')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::EVENT_TYPES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => $state === 'clock_in' ? 'success' : 'warning')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('방식')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::SOURCES[$state] ?? (string) $state)
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('상태')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUSES[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('site.code')->label('현장')->badge()->sortable()->toggleable(),
                TextColumn::make('company.name')->label('회사')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('team.name')->label('팀')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event_type')->label('구분')->options(self::EVENT_TYPES),
                SelectFilter::make('status')->label('상태')->options(self::STATUSES),
                SelectFilter::make('source')->label('방식')->options(self::SOURCES),
                SelectFilter::make('site_id')
                    ->label('현장')
                    ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all()),
                SelectFilter::make('company_id')
                    ->label('회사')
                    ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('event_at')
                    ->schema([
                        DateTimePicker::make('from')->label('시작')->seconds(false),
                        DateTimePicker::make('until')->label('종료')->seconds(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $v): Builder => $q->where('event_at', '>=', $v))
                        ->when($data['until'] ?? null, fn (Builder $q, $v): Builder => $q->where('event_at', '<=', $v))),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('승인')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AttendanceLog $record): bool => $record->status !== 'approved'
                        && static::currentUserHasRole(static::accessManageRoles()))
                    ->requiresConfirmation()
                    ->action(function (AttendanceLog $record): void {
                        $record->update(['status' => 'approved', 'approved_by_id' => auth()->id(), 'approved_at' => Carbon::now()]);
                        Notification::make()->success()->title('승인되었습니다.')->send();
                    }),
                Action::make('reject')
                    ->label('반려')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (AttendanceLog $record): bool => $record->status !== 'rejected'
                        && static::currentUserHasRole(static::accessManageRoles()))
                    ->requiresConfirmation()
                    ->action(function (AttendanceLog $record): void {
                        $record->update(['status' => 'rejected', 'approved_by_id' => auth()->id(), 'approved_at' => Carbon::now()]);
                        Notification::make()->warning()->title('반려되었습니다.')->send();
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
            'index' => ManageAttendanceLogs::route('/'),
        ];
    }
}
