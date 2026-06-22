<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Resources\Employees\Pages\ManageEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Site;
use App\Models\Team;
use App\Services\GeminiBadgeAnalyzer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Employees';

    protected static ?string $modelLabel = 'Employee';

    protected static ?string $pluralModelLabel = 'Employees';

    protected static string | \UnitEnum | null $navigationGroup = 'HUMAN RESOURCE';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('employee_number')
                ->label('Employee ID')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(80),
            TextInput::make('badge_number')
                ->label('Badge / NFC ID')
                ->unique(ignoreRecord: true)
                ->maxLength(80),
            FileUpload::make('badge_photo_path')
                ->label('Badge photo / camera')
                ->disk('public')
                ->directory('employee-badges')
                ->visibility('public')
                ->image()
                ->imagePreviewHeight('180')
                ->maxSize(10240)
                ->openable()
                ->downloadable()
                ->helperText('Take a photo on mobile or upload a badge image. Gemini 3.5 Flash analyzes it after upload.')
                ->extraInputAttributes(['accept' => 'image/*', 'capture' => 'environment'], merge: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?TemporaryUploadedFile $state): void {
                    if (! $state instanceof TemporaryUploadedFile) {
                        return;
                    }

                    self::analyzeBadgePhoto($state, $set, $get);
                })
                ->columnSpanFull(),
            Actions::make([
                Action::make('analyzeBadgePhoto')
                    ->label('Analyze badge photo')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->action(fn (Set $set, Get $get): null => self::analyzeBadgePhoto($get('badge_photo_path'), $set, $get)),
            ])->columnSpanFull(),
            TextInput::make('first_name')
                ->label('First name')
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
            TextInput::make('last_name')
                ->label('Last name')
                ->maxLength(120)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, Get $get): null => self::syncFullName($set, $get)),
            TextInput::make('name')
                ->label('Full name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('badge_company_name')
                ->label('Badge company name')
                ->maxLength(255),
            DatePicker::make('badge_issued_on')
                ->label('Badge issued on'),
            Select::make('company_id')
                ->label('Company')
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('site_id')
                ->label('Site')
                ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all())
                ->searchable(),
            Select::make('team_id')
                ->label('Team')
                ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            TextInput::make('role')
                ->label('Role / Trade')
                ->maxLength(120),
            TextInput::make('nationality')
                ->maxLength(80),
            DatePicker::make('start_date')
                ->label('Hire date / 입사일'),
            Select::make('employment_status')
                ->label('Status')
                ->options([
                    'active' => 'Active',
                    'pending' => 'Pending',
                    'on_leave' => 'On leave',
                    'inactive' => 'Inactive',
                    'terminated' => 'Terminated',
                ])
                ->default('active')
                ->required(),
            DatePicker::make('visa_expires_on')
                ->label('Visa expires'),
            DatePicker::make('safety_training_expires_on')
                ->label('Safety training expires'),
            KeyValue::make('payload')
                ->label('Extra data')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->columnSpanFull(),
            Hidden::make('badge_analysis_model'),
            Hidden::make('badge_analyzed_at'),
            KeyValue::make('badge_analysis_payload')
                ->label('Gemini badge analysis')
                ->keyLabel('Field')
                ->valueLabel('Value')
                ->disabled()
                ->dehydrated()
                ->visible(fn (Get $get): bool => filled($get('badge_analysis_payload')))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('badge_photo_path')
                    ->label('Badge')
                    ->disk('public')
                    ->height(40)
                    ->square()
                    ->toggleable(),
                TextColumn::make('employee_number')->label('Employee ID')->searchable()->sortable(),
                TextColumn::make('name')->label('Full name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('company.name')->label('Company')->searchable()->toggleable(),
                TextColumn::make('site.code')->label('Site')->badge()->sortable(),
                TextColumn::make('team.name')->label('Team')->searchable()->toggleable(),
                TextColumn::make('role')->label('Role')->searchable()->toggleable(),
                TextColumn::make('start_date')->label('Hire date')->date()->sortable()->toggleable(),
                TextColumn::make('employment_status')->label('Status')->badge()->sortable(),
                TextColumn::make('badge_number')->label('Badge')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('badge_company_name')->label('Badge company')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('badge_issued_on')->label('Badge issued')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('visa_expires_on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('safety_training_expires_on')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('employment_status')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'pending' => 'Pending',
                        'on_leave' => 'On leave',
                        'inactive' => 'Inactive',
                        'terminated' => 'Terminated',
                    ]),
                SelectFilter::make('company_id')
                    ->label('Company')
                    ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => Site::query()->orderBy('code')->pluck('code', 'id')->all()),
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
            'index' => ManageEmployees::route('/'),
        ];
    }

    private static function analyzeBadgePhoto(mixed $state, Set $set, Get $get): null
    {
        $file = self::resolveBadgePhoto($state);

        if ($file === null) {
            Notification::make()
                ->warning()
                ->title('Badge photo required')
                ->body('Take a badge photo or upload an image first.')
                ->send();

            return null;
        }

        try {
            $analysis = app(GeminiBadgeAnalyzer::class)->analyze($file['path'], $file['mime_type']);
            self::applyBadgeAnalysis($analysis, $set, $get);

            Notification::make()
                ->success()
                ->title('Badge analysis complete')
                ->body('Company, name, role, issue date, and badge fields were filled from the photo.')
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->warning()
                ->title('Badge analysis skipped')
                ->body($exception->getMessage())
                ->send();
        }

        return null;
    }

    /**
     * @return array{path: string, mime_type: string}|null
     */
    private static function resolveBadgePhoto(mixed $state): ?array
    {
        if (is_array($state)) {
            $state = Arr::first($state);
        }

        if ($state instanceof TemporaryUploadedFile) {
            return [
                'path' => $state->getRealPath(),
                'mime_type' => $state->getMimeType() ?: 'image/jpeg',
            ];
        }

        if (is_string($state) && $state !== '' && Storage::disk('public')->exists($state)) {
            $path = Storage::disk('public')->path($state);

            return [
                'path' => $path,
                'mime_type' => mime_content_type($path) ?: 'image/jpeg',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private static function applyBadgeAnalysis(array $analysis, Set $set, Get $get): void
    {
        self::setIfFilled($set, 'first_name', $analysis['first_name'] ?? null);
        self::setIfFilled($set, 'last_name', $analysis['last_name'] ?? null);
        self::setIfFilled($set, 'name', $analysis['full_name'] ?? null);
        self::setIfFilled($set, 'role', $analysis['role'] ?? null);
        self::setIfFilled($set, 'badge_company_name', $analysis['company_name'] ?? null);
        self::setIfFilled($set, 'badge_issued_on', $analysis['issued_on'] ?? null);

        if (blank($get('badge_number'))) {
            self::setIfFilled($set, 'badge_number', $analysis['badge_number'] ?? null);
        }

        if ($companyId = self::findCompanyId($analysis['company_name'] ?? null)) {
            $set('company_id', $companyId);
        }

        $set('badge_analysis_model', $analysis['model'] ?? config('services.gemini.model', 'gemini-3.5-flash'));
        $set('badge_analyzed_at', Carbon::now()->toDateTimeString());
        $set('badge_analysis_payload', Arr::except($analysis, ['raw']) + [
            'raw_json' => json_encode($analysis['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        self::syncFullName($set, $get);
    }

    private static function setIfFilled(Set $set, string $field, mixed $value): void
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (blank($value)) {
            return;
        }

        $set($field, $value);
    }

    private static function syncFullName(Set $set, Get $get): null
    {
        $firstName = trim((string) $get('first_name'));
        $lastName = trim((string) $get('last_name'));
        $fullName = trim(implode(' ', array_filter([$firstName, $lastName])));

        if ($fullName !== '') {
            $set('name', $fullName);
        }

        return null;
    }

    private static function findCompanyId(mixed $companyName): ?int
    {
        if (! is_string($companyName) || trim($companyName) === '') {
            return null;
        }

        $normalized = Str::lower(trim($companyName));

        return Company::query()
            ->where(fn ($query) => $query
                ->whereRaw('lower(name) = ?', [$normalized])
                ->orWhereRaw('lower(code) = ?', [$normalized]))
            ->value('id');
    }
}
