<?php

namespace App\Filament\Resources\DailyCrewReports;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\DailyCrewReports\Pages\ManageDailyCrewReports;
use App\Models\Company;
use App\Models\DailyCrewReport;
use App\Models\Site;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DailyCrewReportResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = DailyCrewReport::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = '일일 현장 인원 마감';

    protected static ?string $modelLabel = '일일 현장 인원 보고서';

    protected static ?string $pluralModelLabel = '일일 현장 인원 보고서';

    protected static string | \UnitEnum | null $navigationGroup = 'SMART COMPANY';

    protected static ?int $navigationSort = 35;

    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'site_manager', 'safety_manager', 'payroll'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'site_manager', 'safety_manager'];
    }

    protected static function accessDeleteRoles(): array
    {
        return ['super_admin', 'admin'];
    }

    protected static function accessScopeColumns(): array
    {
        return [
            'company' => 'company_id',
            'site' => 'site_id',
            'team' => 'team_id',
            'self' => null,
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('마감 기준')
                ->description('급여 인원과 분리된 안전·현장 운영용 인원 보고서입니다.')
                ->columns(2)
                ->schema([
                    DatePicker::make('work_date')
                        ->label('작업일')
                        ->required()
                        ->disabled(),
                    Select::make('status')
                        ->label('상태')
                        ->options(DailyCrewReport::STATUS_OPTIONS)
                        ->required(),
                    TextInput::make('site.name')
                        ->label('현장')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('team.name')
                        ->label('팀')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('siteContractor.company_name')
                        ->label('협력사')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
            Section::make('인원 집계')
                ->description('최종 인원 = 등록 작업자 + 외부 인원 + 수동 보정')
                ->columns(2)
                ->schema([
                    TextInput::make('scanned_headcount')
                        ->label('QR/출근 등록 인원')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('external_headcount')
                        ->label('미등록 외부 인원')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('manual_adjustment')
                        ->label('수동 보정')
                        ->numeric()
                        ->required()
                        ->helperText('누락은 양수, 중복 제외는 음수로 입력합니다.'),
                    TextInput::make('final_headcount')
                        ->label('최종 인원')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('adjustment_reason')
                        ->label('보정 사유')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            Section::make('일일 보고')
                ->schema([
                    Textarea::make('work_description')
                        ->label('주요 작업내용')
                        ->rows(2)
                        ->maxLength(500),
                    Textarea::make('notes')
                        ->label('특이사항 / 안전 메모')
                        ->rows(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('work_date', 'desc')
            ->columns([
                TextColumn::make('work_date')->label('작업일')->date()->sortable(),
                TextColumn::make('site.name')->label('현장')->searchable()->sortable(),
                TextColumn::make('siteContractor.company_name')->label('협력사')->searchable()->toggleable(),
                TextColumn::make('team.name')->label('팀')->searchable()->sortable(),
                TextColumn::make('scanned_headcount')->label('등록')->numeric()->sortable(),
                TextColumn::make('external_headcount')->label('외부')->numeric()->sortable(),
                TextColumn::make('manual_adjustment')
                    ->label('보정')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state)
                    ->sortable(),
                TextColumn::make('final_headcount')
                    ->label('최종 인원')
                    ->badge()
                    ->color('primary')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('상태')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => DailyCrewReport::STATUS_OPTIONS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => $state === 'closed' ? 'success' : 'warning'),
                TextColumn::make('closedBy.name')->label('마감자')->toggleable(),
                TextColumn::make('closed_at')->label('마감시각')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('현장')
                    ->options(fn (): array => Site::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('company_id')
                    ->label('회사')
                    ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->label('상태')
                    ->options(DailyCrewReport::STATUS_OPTIONS),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDailyCrewReports::route('/'),
        ];
    }
}
