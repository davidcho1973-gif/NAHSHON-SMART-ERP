@php
    $copy = [
        'es' => [
            'html' => 'es',
            'title' => 'Solicitud de empleo',
            'subtitle' => 'Complete su información y suba una foto de su identificación. Puede usar licencia de conducir, pasaporte o ID oficial.',
            'success' => 'Solicitud enviada. El equipo de HR revisará sus documentos y se comunicará para la entrevista.',
            'registration' => 'Registro',
            'company' => 'Compañía',
            'site' => 'Sitio',
            'language' => 'Idioma',
            'last_name' => 'Apellido',
            'first_name' => 'Nombre',
            'preferred_name' => 'Nombre preferido',
            'email' => 'Correo electrónico',
            'phone' => 'Teléfono',
            'date_of_birth' => 'Fecha de nacimiento',
            'nationality' => 'Nacionalidad',
            'address' => 'Dirección',
            'position' => 'Puesto solicitado',
            'trade' => 'Especialidad o nota',
            'available_date' => 'Fecha disponible',
            'emergency_name' => 'Contacto de emergencia',
            'emergency_phone' => 'Teléfono de emergencia',
            'identity' => 'Foto de ID, licencia o pasaporte',
            'certifications' => 'Certificaciones, si tiene',
            'cert_hint' => 'Puede subir varias fotos o archivos PDF.',
            'consent' => 'Confirmo que la información es correcta y autorizo su uso para revisión de empleo, seguridad, acceso al sitio y cumplimiento.',
            'submit' => 'Enviar solicitud',
            'required' => 'Requerido',
        ],
        'en' => [
            'html' => 'en',
            'title' => 'Employment Application',
            'subtitle' => 'Enter your information and upload a photo of your ID. Driver license, passport, or government ID is accepted.',
            'success' => 'Application submitted. HR will review your documents and contact you for the interview.',
            'registration' => 'Registration',
            'company' => 'Company',
            'site' => 'Site',
            'language' => 'Language',
            'last_name' => 'Last name',
            'first_name' => 'First name',
            'preferred_name' => 'Preferred name',
            'email' => 'Email',
            'phone' => 'Phone',
            'date_of_birth' => 'Date of birth',
            'nationality' => 'Nationality',
            'address' => 'Address',
            'position' => 'Position requested',
            'trade' => 'Trade or note',
            'available_date' => 'Available date',
            'emergency_name' => 'Emergency contact',
            'emergency_phone' => 'Emergency phone',
            'identity' => 'Photo of ID, license, or passport',
            'certifications' => 'Certifications, if any',
            'cert_hint' => 'You can upload multiple photos or PDF files.',
            'consent' => 'I confirm the information is accurate and authorize its use for employment review, safety, site access, and compliance.',
            'submit' => 'Submit application',
            'required' => 'Required',
        ],
        'ko' => [
            'html' => 'ko',
            'title' => '입사지원서',
            'subtitle' => '기본 정보를 입력하고 운전면허증, 여권, 신분증 사진을 업로드해 주세요. 자격증이 있으면 함께 올릴 수 있습니다.',
            'success' => '입사지원서가 제출되었습니다. HR 담당자가 서류를 검토한 뒤 인터뷰를 안내합니다.',
            'registration' => '접수번호',
            'company' => '회사',
            'site' => '현장',
            'language' => '언어',
            'last_name' => '성',
            'first_name' => '이름',
            'preferred_name' => '사용 이름',
            'email' => '이메일',
            'phone' => '전화번호',
            'date_of_birth' => '생년월일',
            'nationality' => '국적',
            'address' => '주소',
            'position' => '지원 직책',
            'trade' => '전문분야 또는 메모',
            'available_date' => '근무 가능일',
            'emergency_name' => '비상 연락처 이름',
            'emergency_phone' => '비상 연락처 전화번호',
            'identity' => '신분증, 운전면허증 또는 여권 사진',
            'certifications' => '보유 자격증',
            'cert_hint' => '사진 또는 PDF 파일을 여러 개 업로드할 수 있습니다.',
            'consent' => '입력한 정보가 정확하며 채용 검토, 안전, 현장 출입, 컴플라이언스 목적으로 사용하는 것에 동의합니다.',
            'submit' => '입사지원서 제출',
            'required' => '필수',
        ],
    ];
    $t = $copy[$language] ?? $copy['es'];
@endphp
<!DOCTYPE html>
<html lang="{{ $t['html'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $t['title'] }} - SMART COMPANY</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #111827;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f4f7fb;
        }

        main {
            width: min(860px, 100%);
            margin: 0 auto;
            padding: 0 0 2rem;
        }

        header {
            display: grid;
            gap: 1rem;
            padding: calc(1rem + env(safe-area-inset-top)) 1rem 1.25rem;
            color: #f8fafc;
            background: #111827;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .mark {
            display: grid;
            width: 2.6rem;
            height: 2.6rem;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 10px;
            font-weight: 800;
        }

        h1, p {
            margin: 0;
        }

        h1 {
            font-size: clamp(1.6rem, 6vw, 2.4rem);
            line-height: 1.1;
        }

        .subtitle {
            color: #cbd5e1;
            line-height: 1.55;
        }

        .language-form {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .language-form label {
            color: #e5e7eb;
            font-weight: 800;
        }

        .content {
            display: grid;
            gap: 1rem;
            padding: 1rem;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.7rem;
        }

        .summary div,
        .success,
        .form-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }

        .summary div {
            padding: 0.75rem;
        }

        .summary span {
            display: block;
            margin-bottom: 0.2rem;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 800;
        }

        .success {
            padding: 0.9rem;
            background: #ecfdf5;
            color: #065f46;
            font-weight: 800;
        }

        form.application {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
            padding: 1rem;
        }

        label.field {
            display: grid;
            gap: 0.35rem;
            color: #334155;
            font-size: 0.9rem;
            font-weight: 800;
        }

        small {
            color: #64748b;
            font-weight: 600;
            line-height: 1.45;
        }

        input,
        select,
        textarea {
            width: 100%;
            min-height: 2.85rem;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 0 0.85rem;
            background: #ffffff;
            color: #111827;
            font: inherit;
            font-weight: 600;
        }

        textarea {
            min-height: 5rem;
            padding-top: 0.75rem;
            resize: vertical;
        }

        input[type="file"] {
            padding: 0.7rem;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: 2px solid #2563eb;
            outline-offset: 1px;
            border-color: #2563eb;
        }

        .full {
            grid-column: 1 / -1;
        }

        .consent {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.9rem;
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
            line-height: 1.45;
        }

        .consent input {
            width: 1.05rem;
            min-height: 1.05rem;
            margin-top: 0.2rem;
        }

        button {
            min-height: 3.2rem;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: #ffffff;
            font: inherit;
            font-weight: 900;
            cursor: pointer;
        }

        .error {
            color: #b91c1c;
            font-size: 0.8rem;
            font-weight: 700;
        }

        @media (max-width: 720px) {
            .summary,
            form.application {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <main>
        <header>
            <div class="brand">
                <div class="mark">NS</div>
                <div>
                    <p class="subtitle">SMART COMPANY ERP</p>
                    <h1>{{ $t['title'] }}</h1>
                </div>
            </div>
            <p class="subtitle">{{ $t['subtitle'] }}</p>
            <form class="language-form" method="GET" action="{{ route('member-registration.show', $registration->invite_token) }}">
                <label for="lang">{{ $t['language'] }}</label>
                <select id="lang" name="lang" onchange="this.form.submit()">
                    @foreach ($languages as $code => $label)
                        <option value="{{ $code }}" @selected($language === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        </header>

        <div class="content">
            @if ($submitted)
                <div class="success">{{ $t['success'] }}</div>
            @endif

            <section class="summary" aria-label="Registration assignment">
                <div>
                    <span>{{ $t['registration'] }}</span>
                    {{ $registration->registration_number }}
                </div>
                <div>
                    <span>{{ $t['company'] }}</span>
                    {{ $registration->company?->name ?? 'Pending' }}
                </div>
                <div>
                    <span>{{ $t['site'] }}</span>
                    {{ $registration->site?->code ?? 'Pending' }}
                </div>
            </section>

            <section class="form-card">
                <form class="application" method="POST" action="{{ route('member-registration.store', $registration->invite_token) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="preferred_language" value="{{ $language }}">

                    <label class="field">
                        {{ $t['last_name'] }} <small>{{ $t['required'] }}</small>
                        <input name="last_name" value="{{ old('last_name', $registration->last_name) }}" required maxlength="120" autocomplete="family-name">
                        @error('last_name') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="field">
                        {{ $t['first_name'] }} <small>{{ $t['required'] }}</small>
                        <input name="first_name" value="{{ old('first_name', $registration->first_name) }}" required maxlength="120" autocomplete="given-name">
                        @error('first_name') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="field">
                        {{ $t['preferred_name'] }}
                        <input name="preferred_name" value="{{ old('preferred_name', $registration->preferred_name) }}" maxlength="255">
                    </label>

                    <label class="field">
                        {{ $t['email'] }}
                        <input name="email" type="email" value="{{ old('email', $registration->email) }}" maxlength="255" autocomplete="email">
                        @error('email') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="field">
                        {{ $t['phone'] }} <small>{{ $t['required'] }}</small>
                        <input name="phone" value="{{ old('phone', $registration->phone) }}" required maxlength="80" autocomplete="tel">
                        @error('phone') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="field">
                        {{ $t['date_of_birth'] }}
                        <input name="date_of_birth" type="date" value="{{ old('date_of_birth', optional($registration->date_of_birth)->format('Y-m-d')) }}">
                    </label>

                    <label class="field">
                        {{ $t['nationality'] }} <small>{{ $t['required'] }}</small>
                        <input name="nationality" value="{{ old('nationality', $registration->nationality) }}" required maxlength="80">
                        @error('nationality') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="field">
                        {{ $t['position'] }} <small>{{ $t['required'] }}</small>
                        <select name="role" required>
                            <option value=""></option>
                            @foreach ($roleOptions as $value => $label)
                                <option value="{{ $value }}" @selected(old('role', $registration->role) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('role') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="field full">
                        {{ $t['address'] }}
                        <input name="address" value="{{ old('address', $registration->address) }}" maxlength="255" autocomplete="street-address">
                    </label>

                    <label class="field">
                        {{ $t['available_date'] }}
                        <input name="start_date" type="date" value="{{ old('start_date', optional($registration->start_date)->format('Y-m-d')) }}">
                    </label>

                    <label class="field">
                        {{ $t['trade'] }}
                        <input name="trade" value="{{ old('trade', $registration->trade) }}" maxlength="120">
                    </label>

                    <label class="field">
                        {{ $t['emergency_name'] }}
                        <input name="emergency_contact_name" value="{{ old('emergency_contact_name', $registration->emergency_contact_name) }}" maxlength="255">
                    </label>

                    <label class="field">
                        {{ $t['emergency_phone'] }}
                        <input name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $registration->emergency_contact_phone) }}" maxlength="80" autocomplete="tel">
                    </label>

                    <label class="field full">
                        {{ $t['identity'] }} <small>{{ $t['required'] }}</small>
                        <input name="identity_document" type="file" accept="image/*,.pdf" capture="environment" @if (! $registration->documents->where('document_type', 'id')->count()) required @endif>
                        @error('identity_document') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="field full">
                        {{ $t['certifications'] }}
                        <input name="certifications[]" type="file" accept="image/*,.pdf" multiple>
                        <small>{{ $t['cert_hint'] }}</small>
                        @error('certifications.*') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label class="consent full">
                        <input name="consent" type="checkbox" value="1" required>
                        <span>{{ $t['consent'] }}</span>
                    </label>
                    @error('consent') <span class="error full">{{ $message }}</span> @enderror

                    <button class="full" type="submit">{{ $t['submit'] }}</button>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
