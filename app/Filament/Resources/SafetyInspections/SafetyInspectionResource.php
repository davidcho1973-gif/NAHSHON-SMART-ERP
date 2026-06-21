<?php

namespace App\Filament\Resources\SafetyInspections;

use App\Filament\Resources\SafetyInspections\Pages\ManageSafetyInspections;
use App\Models\SafetyInspection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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

class SafetyInspectionResource extends Resource
{
    protected static ?string $model = SafetyInspection::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = '안전점검 (Safety Inspections)';

    protected static ?string $modelLabel = '안전점검';

    protected static ?string $pluralModelLabel = '안전점검 목록';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 40;

    public const TYPE_OPTIONS = [
        'routine' => '정기 점검',
        'special' => '특별 점검',
        'equipment' => '장비 점검',
        'fire' => '소방 점검',
        'follow_up' => '후속 점검',
    ];

    public const STATUS_OPTIONS = [
        'scheduled' => '예정',
        'in_progress' => '진행중',
        'completed' => '완료',
        'failed' => '불합격',
    ];

    public const SEVERITY_OPTIONS = [
        'low' => '낮음',
        'medium' => '보통',
        'high' => '높음',
        'critical' => '심각',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('점검 번호')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(60),
            TextInput::make('title')
                ->label('점검명')
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
                ->label('점검자 (Inspector)')
                ->relationship('employee', 'name')
                ->searchable()
                ->preload(),
            Select::make('type')
                ->label('점검 유형')
                ->options(self::TYPE_OPTIONS)
                ->default('routine')
                ->required(),
            Select::make('status')
                ->label('상태')
                ->options(self::STATUS_OPTIONS)
                ->default('scheduled')
                ->required(),
            Select::make('severity')
                ->label('위험도')
                ->options(self::SEVERITY_OPTIONS),
            DatePicker::make('scheduled_date')
                ->label('예정일'),
            DatePicker::make('completed_date')
                ->label('완료일'),
            TextInput::make('score')
                ->label('점수 (0-100)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100),
            Textarea::make('findings')
                ->label('지적 사항')
                ->columnSpanFull(),
            Textarea::make('corrective_action')
                ->label('시정 조치')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('점검 번호')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('title')
                    ->label('점검명')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('site.name')
                    ->label('현장')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('type')
                    ->label('유형')
                    ->formatStateUsing(fn (?string $state): string => self::TYPE_OPTIONS[$state] ?? (string) $state)
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('상태')
                    ->formatStateUsing(fn (?string $state): string => self::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'in_progress' => 'warning',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('severity')
                    ->label('위험도')
                    ->formatStateUsing(fn (?string $state): string => self::SEVERITY_OPTIONS[$state] ?? (string) ($state ?? ''))
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('scheduled_date')
                    ->label('예정일')
                    ->date()
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
                SelectFilter::make('type')
                    ->label('유형')
                    ->options(self::TYPE_OPTIONS),
            ])
            ->defaultSort('scheduled_date', 'desc')
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
            'index' => ManageSafetyInspections::route('/'),
        ];
    }
}
