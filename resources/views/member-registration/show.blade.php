<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Member Registration - SMART COMPANY</title>
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
            background:
                radial-gradient(circle at 10% 0%, rgba(20, 184, 166, 0.24), transparent 28rem),
                linear-gradient(135deg, #0f172a 0%, #111827 52%, #1f2937 100%);
        }

        main {
            width: min(980px, calc(100% - 2rem));
            margin: 0 auto;
            padding: 2rem 0;
        }

        .shell {
            overflow: hidden;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
        }

        header {
            display: grid;
            gap: 0.75rem;
            padding: 1.25rem;
            color: #f8fafc;
            background: linear-gradient(135deg, #0f172a, #115e59);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .mark {
            display: grid;
            width: 2.8rem;
            height: 2.8rem;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 12px;
            font-weight: 800;
        }

        h1, p {
            margin: 0;
        }

        h1 {
            font-size: clamp(1.5rem, 3vw, 2.2rem);
            line-height: 1.1;
        }

        .meta {
            color: #ccfbf1;
            font-size: 0.92rem;
        }

        .content {
            display: grid;
            gap: 1.25rem;
            padding: 1.25rem;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .summary div {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.8rem;
            background: #f9fafb;
        }

        .summary span {
            display: block;
            color: #6b7280;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        label {
            display: grid;
            gap: 0.35rem;
            color: #374151;
            font-size: 0.88rem;
            font-weight: 700;
        }

        input {
            width: 100%;
            min-height: 2.8rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 0 0.85rem;
            color: #111827;
            font: inherit;
            font-weight: 500;
        }

        input:focus {
            outline: 2px solid #14b8a6;
            outline-offset: 1px;
            border-color: #0f766e;
        }

        .full {
            grid-column: 1 / -1;
        }

        .consent {
            display: flex;
            align-items: flex-start;
            gap: 0.6rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 0.85rem;
            background: #f9fafb;
            font-weight: 600;
        }

        .consent input {
            width: 1rem;
            min-height: 1rem;
            margin-top: 0.2rem;
        }

        button {
            min-height: 3rem;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: #ffffff;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }

        .success {
            border: 1px solid #99f6e4;
            border-radius: 12px;
            padding: 1rem;
            background: #ecfdf5;
            color: #065f46;
            font-weight: 700;
        }

        .error {
            color: #b91c1c;
            font-size: 0.8rem;
        }

        @media (max-width: 720px) {
            .summary,
            form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <main>
        <section class="shell">
            <header>
                <div class="brand">
                    <div class="mark">NS</div>
                    <div>
                        <p class="meta">SMART COMPANY ERP</p>
                        <h1>Member Registration</h1>
                    </div>
                </div>
                <p class="meta">Secure onboarding intake for workers, vendors, visitors, and staff.</p>
            </header>

            <div class="content">
                @if ($submitted)
                    <div class="success">
                        Registration submitted. The admin team will review identity, documents, and site readiness.
                    </div>
                @endif

                <section class="summary" aria-label="Registration assignment">
                    <div>
                        <span>Registration</span>
                        {{ $registration->registration_number }}
                    </div>
                    <div>
                        <span>Company</span>
                        {{ $registration->company?->name ?? 'Pending assignment' }}
                    </div>
                    <div>
                        <span>Site</span>
                        {{ $registration->site?->code ?? 'Pending assignment' }}
                    </div>
                </section>

                <form method="POST" action="{{ route('member-registration.store', $registration->invite_token) }}">
                    @csrf

                    <label>
                        Full name
                        <input name="full_name" value="{{ old('full_name', $registration->full_name) }}" required maxlength="255">
                        @error('full_name') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label>
                        Preferred name
                        <input name="preferred_name" value="{{ old('preferred_name', $registration->preferred_name) }}" maxlength="255">
                    </label>

                    <label>
                        Email
                        <input name="email" type="email" value="{{ old('email', $registration->email) }}" maxlength="255">
                        @error('email') <span class="error">{{ $message }}</span> @enderror
                    </label>

                    <label>
                        Phone
                        <input name="phone" value="{{ old('phone', $registration->phone) }}" maxlength="80">
                    </label>

                    <label>
                        Nationality
                        <input name="nationality" value="{{ old('nationality', $registration->nationality) }}" maxlength="80">
                    </label>

                    <label>
                        Role
                        <input name="role" value="{{ old('role', $registration->role) }}" maxlength="120">
                    </label>

                    <label>
                        Trade
                        <input name="trade" value="{{ old('trade', $registration->trade) }}" maxlength="120">
                    </label>

                    <label>
                        Visa type
                        <input name="visa_type" value="{{ old('visa_type', $registration->visa_type) }}" maxlength="60">
                    </label>

                    <label>
                        Visa expires on
                        <input name="visa_expires_on" type="date" value="{{ old('visa_expires_on', optional($registration->visa_expires_on)->format('Y-m-d')) }}">
                    </label>

                    <label>
                        Safety training expires on
                        <input name="safety_training_expires_on" type="date" value="{{ old('safety_training_expires_on', optional($registration->safety_training_expires_on)->format('Y-m-d')) }}">
                    </label>

                    <label>
                        Emergency contact name
                        <input name="emergency_contact_name" value="{{ old('emergency_contact_name', $registration->payload['self_registration']['emergency_contact_name'] ?? '') }}" maxlength="255">
                    </label>

                    <label>
                        Emergency contact phone
                        <input name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $registration->payload['self_registration']['emergency_contact_phone'] ?? '') }}" maxlength="80">
                    </label>

                    <label class="consent full">
                        <input name="consent" type="checkbox" value="1" required>
                        I confirm that the information is accurate and may be used for site access, safety, attendance, and compliance review.
                    </label>

                    <button class="full" type="submit">Submit Registration</button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
