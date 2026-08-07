<?php

namespace App\Filament\Resources\MemberDocuments;

use App\Filament\Concerns\AuthorizesResourceAccess;
use App\Filament\Resources\MemberDocuments\Pages\ManageMemberDocuments;
use App\Filament\Resources\MemberDocuments\Pages\ManageMemberUploadedDocuments;
use App\Models\MemberRegistration;
use App\Services\GeminiDocumentAnalyzer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MemberDocumentResource extends Resource
{
    // Aliased so the eager-loading query below can build on the scoped base query.
    use AuthorizesResourceAccess {
        getEloquentQuery as scopedEloquentQuery;
    }

    protected static ?string $model = MemberRegistration::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'HR Documents';

    protected static ?string $modelLabel = 'HR Document Summary';

    protected static ?string $pluralModelLabel = 'HR Documents';

    protected static string | \UnitEnum | null $navigationGroup = 'HUMAN RESOURCE';

    // Visas, IDs and certificates are personal records, so this stays narrower than
    // the employee list. Safety managers are included because expiring safety
    // certificates live here — it is also their landing page after login.
    protected static function accessViewRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager', 'safety_manager'];
    }

    protected static function accessManageRoles(): array
    {
        return ['super_admin', 'admin', 'hr_manager'];
    }

    protected static function accessScopeColumns(): array
    {
        return ['company' => 'company_id', 'site' => 'site_id', 'team' => 'team_id', 'self' => 'employee_id'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function documentForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('document_type')
                ->options([
                    'id' => 'Government ID',
                    'id_back' => 'Government ID Back',
                    'certification' => 'Certification',
                    'visa' => 'Visa / Work Authorization',
                    'safety' => 'Safety Orientation',
                    'safety_training' => 'Safety Training',
                    'nfc' => 'Badge / NFC',
                    'contract' => 'Contract',
                    'insurance' => 'Insurance',
                    'other' => 'Other',
                ])
                ->required(),
            TextInput::make('title')->required()->maxLength(255),
            Select::make('status')
                ->options([
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'needs_review' => 'Needs review',
                    'expired' => 'Expired',
                    'rejected' => 'Rejected',
                ])
                ->default('pending')
                ->required(),
            DatePicker::make('issued_on'),
            DatePicker::make('expires_on'),
            TextInput::make('file_path')->label('File path / Drive URL')->maxLength(255)->columnSpanFull(),
            Actions::make([
                Action::make('ai_analyze_document')
                    ->label('🤖 AI 문서 분석')
                    ->color('primary')
                    ->action(fn (Set $set, Get $get) => self::analyzeMemberDocument((string) $get('file_path'), $set, $get)),
            ])->columnSpanFull(),
            KeyValue::make('extracted_data')->keyLabel('Field')->valueLabel('Value')->columnSpanFull(),
            Textarea::make('review_notes')->columnSpanFull(),
        ]);
    }

    /**
     * 로컬에 저장된 문서를 AI 로 읽어 문서유형·발행/만료일·추출데이터를 비어 있는 칸에 채운다.
     */
    public static function analyzeMemberDocument(string $filePath, Set $set, Get $get): void
    {
        $path = self::resolveLocalDocument($filePath);
        if ($path === null) {
            Notification::make()->warning()->title('AI 분석 불가')
                ->body('로컬에 저장된 파일만 분석할 수 있습니다(외부 Drive URL 등은 미지원). 파일 경로를 확인하세요.')->send();

            return;
        }

        try {
            $data = app(GeminiDocumentAnalyzer::class)->analyze($path, mime_content_type($path) ?: null);
        } catch (Throwable $e) {
            Notification::make()->warning()->title('AI 문서 분석 건너뜀')->body($e->getMessage())->send();

            return;
        }

        $fillEmpty = static function (string $key, mixed $value) use ($set, $get): void {
            if ($value !== null && $value !== '' && blank($get($key))) {
                $set($key, $value);
            }
        };

        // 분석기의 계약 유형 키 → 멤버 문서 유형 키 매핑.
        $typeMap = [
            'certificate_of_insurance' => 'insurance', 'bond' => 'insurance',
            'visa' => 'visa', 'work_authorization' => 'visa',
            'license' => 'certification', 'certificate' => 'certification',
            'executed_contract' => 'contract', 'amendment' => 'contract',
        ];
        $memberType = $data['document_type'] !== null ? ($typeMap[$data['document_type']] ?? null) : null;
        if ($memberType !== null) {
            $fillEmpty('document_type', $memberType);
        }
        $fillEmpty('title', $data['title']);
        $fillEmpty('issued_on', $data['issued_on']);
        $fillEmpty('expires_on', $data['expires_on']);

        $extracted = $data['fields'];
        foreach (['document_number', 'issuer', 'counterparty', 'amount', 'currency', 'summary'] as $k) {
            if ($data[$k] !== null) {
                $extracted[$k] = $data[$k];
            }
        }
        if ($extracted !== []) {
            $set('extracted_data', $extracted);
        }

        Notification::make()->success()->title('AI 문서 분석 완료')
            ->body('발행/만료일·추출 데이터를 채웠습니다. 확인 후 저장하세요.')->send();
    }

    private static function resolveLocalDocument(string $filePath): ?string
    {
        $filePath = trim($filePath);
        if ($filePath === '' || str_starts_with($filePath, 'http')) {
            return null;
        }
        foreach (['public', 'local'] as $disk) {
            if (Storage::disk($disk)->exists($filePath)) {
                return Storage::disk($disk)->path($filePath);
            }
        }

        return is_file($filePath) && is_readable($filePath) ? $filePath : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Member')
                    ->searchable()
                    ->sortable()
                    ->url(fn (MemberRegistration $record): string => static::getUrl('documents', ['record' => $record])),
                TextColumn::make('employee.employee_number')
                    ->label('Employee')
                    ->badge()
                    ->placeholder('Not synced')
                    ->toggleable(),
                TextColumn::make('company.name')->label('Company')->toggleable(),
                TextColumn::make('site.code')->label('Site')->badge(),
                TextColumn::make('documents_count')
                    ->label('Docs')
                    ->badge()
                    ->sortable(),
                TextColumn::make('verified_documents_count')
                    ->label('Verified')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('pending_documents_count')
                    ->label('Pending')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
                TextColumn::make('expired_documents_count')
                    ->label('Expired')
                    ->badge()
                    ->color('danger')
                    ->sortable(),
                TextColumn::make('document_status')->label('Document status')->badge()->sortable(),
                TextColumn::make('onboarding_status')->label('Member status')->badge()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('document_status')->options([
                    'missing' => 'Missing',
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'expired' => 'Expired',
                ]),
                SelectFilter::make('onboarding_status')->options(MemberRegistration::onboardingStatusOptions()),
            ])
            ->recordActions([
                Action::make('documents')
                    ->label('Documents')
                    ->icon('heroicon-o-document-check')
                    ->url(fn (MemberRegistration $record): string => static::getUrl('documents', ['record' => $record])),
                DeleteAction::make(),
            ])
            ->recordUrl(fn (MemberRegistration $record): string => static::getUrl('documents', ['record' => $record]));
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scopedEloquentQuery()
            ->with(['employee', 'company', 'site'])
            ->withCount([
                'documents',
                'documents as verified_documents_count' => fn (Builder $query): Builder => $query->where('status', 'verified'),
                'documents as pending_documents_count' => fn (Builder $query): Builder => $query->whereIn('status', ['pending', 'needs_review']),
                'documents as expired_documents_count' => fn (Builder $query): Builder => $query->where('status', 'expired'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMemberDocuments::route('/'),
            'documents' => ManageMemberUploadedDocuments::route('/{record}/documents'),
        ];
    }
}
