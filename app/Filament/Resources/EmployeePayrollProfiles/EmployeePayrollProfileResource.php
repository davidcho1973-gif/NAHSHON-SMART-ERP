<?php

namespace App\Filament\Resources\EmployeePayrollProfiles;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\EmployeePayrollProfiles\Pages\ManageEmployeePayrollProfiles;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmployeePayrollProfileResource extends Resource
{
    use AuthorizesResourceAccess;

    protected static ?string $model = EmployeePayrollProfile::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = '임금 프로필 (Pay Profiles)';

    protected static ?string $modelLabel = 'Pay Profile';

    protected static ?string $pluralModelLabel = '임금 프로필';

    protected static string | \UnitEnum | null $navigationGroup = '급여 (Payroll)';

    // Payroll/HR can set wages; super_admin/admin manage and delete.
    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'payroll'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'payroll'];
    }

    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => 'site_id', 'team' => null, 'self' => 'employee_id'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('대상 직원')
                ->columns(2)
                ->schema([
                    Select::make('employee_id')
                        ->label('Employee / 직원')
                        ->options(fn (): array => Employee::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Employee $e): array => [
                                $e->id => "{$e->name} ({$e->employee_number})",
                            ])
                            ->all())
                        ->searchable()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('직원 등록 시 프로필이 자동 생성됩니다. 여기서는 임금만 입력하세요.'),
                ]),

            Section::make('임금 기준 (Wage basis)')
                ->columns(2)
                ->schema([
                    Select::make('pay_type')
                        ->label('임금 형태 / Pay type')
                        ->options([
                            'hourly' => '시급 (Hourly)',
                            'salary' => '연봉 (Salary)',
                            'daily' => '일급 (Daily)',
                        ])
                        ->default('hourly')
                        ->required()
                        ->live(),
                    TextInput::make('base_rate')
                        ->label('기준 임금 / Base rate')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->prefix('$')
                        ->helperText(fn (Get $get): string => match ($get('pay_type')) {
                            'salary' => '연봉 ($/year) — 급여 1회당 연봉÷26로 분할',
                            'daily' => '일급 ($/day) — 8시간 = 1일 기준',
                            default => '시급 ($/hour) — OT는 1.5배 자동 적용',
                        }),
                    TextInput::make('overtime_multiplier')
                        ->label('OT 배수')
                        ->numeric()
                        ->default(1.5)
                        ->step(0.1)
                        ->minValue(1),
                    Select::make('pay_currency')
                        ->label('통화')
                        ->options(['USD' => 'USD', 'KRW' => 'KRW'])
                        ->default('USD')
                        ->required(),
                    TextInput::make('per_diem_rate')
                        ->label('Per Diem (비과세 일당)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('$'),
                    Toggle::make('is_exempt')
                        ->label('FLSA Exempt (OT 미적용)'),
                ]),

            Section::make('분류 / 파견 (Classification)')
                ->columns(2)
                ->schema([
                    TextInput::make('trade')
                        ->label('직종 / Trade')
                        ->maxLength(60)
                        ->helperText('Welder / Fitter / Rigger ... (prevailing wage 매칭)'),
                    Select::make('worker_division')
                        ->label('직군 / Division')
                        ->options([
                            '관리자' => '관리자 (Manager)',
                            '한국인' => '한국인 (Korean)',
                            '외국인' => '외국인 (Local)',
                        ])
                        ->placeholder('비우면 자동 추론'),
                    Toggle::make('is_dispatched')
                        ->label('한국 파견 (Dispatched)'),
                    TextInput::make('visa_type')
                        ->label('비자 / Visa')
                        ->maxLength(20)
                        ->placeholder('L-1 / E-2 / B-1 / H-1B'),
                ]),

            Section::make('세금 / 공제 (Withholding)')
                ->columns(2)
                ->collapsible()
                ->schema([
                    Select::make('fed_filing_status')
                        ->label('연방 신고 상태')
                        ->options(['single' => 'Single', 'married' => 'Married'])
                        ->placeholder('Single'),
                    TextInput::make('withholding_state')
                        ->label('원천징수 주 (State)')
                        ->maxLength(2)
                        ->placeholder('GA / TX / AZ / TN'),
                    TextInput::make('retirement_pct')
                        ->label('401(k) %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(0)
                        ->suffix('%'),
                    DatePicker::make('effective_from')
                        ->label('발효일 / Effective from'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.name')->label('직원')->searchable()->sortable(),
                TextColumn::make('employee.employee_number')->label('Employee ID')->searchable()->toggleable(),
                TextColumn::make('pay_type')
                    ->label('형태')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ['hourly' => '시급', 'salary' => '연봉', 'daily' => '일급'][$state] ?? $state),
                TextColumn::make('base_rate')
                    ->label('기준 임금')
                    ->money(fn (EmployeePayrollProfile $r): string => strtolower($r->pay_currency ?? 'usd'))
                    ->sortable(),
                TextColumn::make('trade')->label('직종')->toggleable(),
                TextColumn::make('worker_division')->label('직군')->badge()->toggleable(),
                IconColumn::make('is_dispatched')->label('파견')->boolean()->toggleable(),
                TextColumn::make('company.name')->label('회사')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('site.code')->label('현장')->badge()->toggleable(),
                TextColumn::make('updated_at')->label('수정')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('pay_type')->label('임금 형태')->options([
                    'hourly' => '시급',
                    'salary' => '연봉',
                    'daily' => '일급',
                ]),
                SelectFilter::make('worker_division')->label('직군')->options([
                    '관리자' => '관리자',
                    '한국인' => '한국인',
                    '외국인' => '외국인',
                ]),
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
            'index' => ManageEmployeePayrollProfiles::route('/'),
        ];
    }
}
