@php
    $companyOptions = \App\Support\CurrentCompany::options();
    $currentCompanyId = \App\Support\CurrentCompany::id();
@endphp

@if (count($companyOptions) > 1)
    <style>
        .nsc-company-switcher select {
            appearance: none;
            border: 1px solid rgb(212 212 216);
            border-radius: 0.5rem;
            background-color: rgb(255 255 255);
            color: rgb(24 24 27);
            font-size: 0.875rem;
            font-weight: 500;
            line-height: 1.25rem;
            padding: 0.375rem 2rem 0.375rem 0.75rem;
            max-width: 14rem;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%2371717a' stroke-linecap='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            background-size: 1.25rem;
        }

        .nsc-company-switcher select:focus {
            outline: 2px solid rgb(59 130 246);
            outline-offset: 1px;
        }

        .dark .nsc-company-switcher select {
            border-color: rgb(63 63 70);
            background-color: rgb(39 39 42);
            color: rgb(244 244 245);
        }
    </style>

    <form method="POST" action="{{ route('company.switch') }}" class="nsc-company-switcher">
        @csrf
        <label for="nsc-company-switcher" class="fi-sr-only sr-only">회사 선택</label>
        <select id="nsc-company-switcher" name="company_id" onchange="this.form.submit()">
            @foreach ($companyOptions as $companyId => $companyName)
                <option value="{{ $companyId }}" @selected($companyId === $currentCompanyId)>
                    {{ $companyName }}
                </option>
            @endforeach
        </select>
    </form>
@endif
