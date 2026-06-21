<?php

namespace App\Filament\Resources\SafetyIncidents;

use App\Filament\Resources\SafetyIncidents\Pages\ManageSafetyIncidents;
use App\Models\SafetyIncident;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SafetyIncidentResource extends Resource
{
    protected static ?string $model = SafetyIncident::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = '사고 보고 (Safety Incidents)';

    protected static ?string $modelLabel = '사고 보고';

    protected static ?string $pluralModelLabel = '사고 보고 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 41;

    public const CATEGORY_OPTIONS = [
        'injury' => '부상',
        'near_miss' => '아차사고',
        'property' => '재물 손상',
        'environmental' => '환경',
        'fire' => '화재',
    ];

    public const SEVERITY_OPTIONS = [
        'low' => '낮음',
        'medium' => '보통',
        'high' => '높음',
        'critical' => '심각',
    ];

    public const STATUS_OPTIONS = [
        'open' => '접수',
        'investigating' => '조사중',
        'resolved' => '조치완료',
        'closed' => '종결',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('보고 번호')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(60),
            TextInput::make('title')
                ->label('제목')
                ->required()
                ->maxLength(255),
            Select::make('company_id')
                ->label('원청사 (Company)')
                ->relationship('company', 'name')
                ->searchable()
                ->preload(),
            Select::make('site_id')
                ->label('현장 (Site)')
                ->relationship('site', 'name')
                ->searchable()
                ->preload(),
            Select::make('team_id')
                ->label('팀 (Team)')
                ->relationship('team', 'name')
                ->searchable()
                ->preload(),
            Select::make('employee_id')
                ->label('관련 직원 / 보고자')
                ->relationship('employee', 'name')
                ->searchable()
                ->preload(),
            Select::make('category')
                ->label('분류')
                ->options(self::CATEGORY_OPTIONS)
                ->default('near_miss')
                ->required(),
            Select::make('severity')
                ->label('심각도')
                ->options(self::SEVERITY_OPTIONS)
                ->default('low')
                ->required(),
            Select::make('status')
                ->label('상태')
                ->options(self::STATUS_OPTIONS)
                ->default('open')
                ->required(),
            DateTimePicker::make('occurred_at')
                ->label('발생 일시'),
            DateTimePicker::make('reported_at')
                ->label('보고 일시'),
            TextInput::make('location')
                ->label('발생 장소')
                ->maxLength(255),
            Textarea::make('description')
                ->label('사고 경위')
                ->columnSpanFull(),
            Textarea::make('action_taken')
                ->label('조치 내용')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('보고 번호')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('title')
                    ->label('제목')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('site.name')
                    ->label('현장')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('category')
                    ->label('분류')
                    ->formatStateUsing(fn (?string $state): string => self::CATEGORY_OPTIONS[$state] ?? (string) $state)
                    ->badge()
                    ->sortable(),
                TextColumn::make('severity')
                    ->label('심각도')
                    ->formatStateUsing(fn (?string $state): string => self::SEVERITY_OPTIONS[$state] ?? (string) $state)
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label('상태')
                    ->formatStateUsing(fn (?string $state): string => self::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'resolved' => 'success',
                        'investigating' => 'warning',
                        'closed' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('occurred_at')
                    ->label('발생 일시')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('수정일')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('상태')
                    ->options(self::STATUS_OPTIONS),
                SelectFilter::make('severity')
                    ->label('심각도')
                    ->options(self::SEVERITY_OPTIONS),
                SelectFilter::make('category')
                    ->label('분류')
                    ->options(self::CATEGORY_OPTIONS),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::applyAccessScope(parent::getEloquentQuery());
    }

    /**
     * §6-3 접근제어: users.access_scope 기준으로 행을 제한한다.
     */
    protected static function applyAccessScope(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user === null) {
            return $query;
        }

        if (in_array($user->access_role, ['super_admin', 'admin'], true) || $user->access_scope === 'all_sites') {
            return $query;
        }

        return match ($user->access_scope) {
            'company' => $query->where('company_id', $user->allowed_company_id),
            'site' => $query->where('site_id', $user->allowed_site_id),
            'team' => $query->where('team_id', $user->allowed_team_id),
            'self' => $query->where('employee_id', $user->employee_id),
            default => $query,
        };
    }

    protected static function userCanView(): bool
    {
        return in_array(Auth::user()?->access_role, ['super_admin', 'admin', 'site_manager', 'safety_manager'], true);
    }

    protected static function userCanManage(): bool
    {
        return in_array(Auth::user()?->access_role, ['super_admin', 'admin', 'safety_manager'], true);
    }

    public static function canViewAny(): bool
    {
        return static::userCanView();
    }

    public static function canCreate(): bool
    {
        return static::userCanManage();
    }

    public static function canEdit(Model $record): bool
    {
        return static::userCanManage();
    }

    public static function canDelete(Model $record): bool
    {
        return static::userCanManage();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSafetyIncidents::route('/'),
        ];
    }
}
