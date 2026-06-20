<style>
    .fi-simple-layout {
        min-height: 100vh;
        background:
            radial-gradient(circle at top left, rgba(20, 184, 166, 0.18), transparent 30rem),
            linear-gradient(135deg, #0f172a 0%, #111827 48%, #1f2937 100%);
    }

    .fi-simple-main-ctn {
        padding: 2rem 1rem;
    }

    .fi-simple-main {
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
    }

    .fi-simple-page-content {
        gap: 1.25rem;
        padding: 1.5rem;
    }

    .smart-admin-login-intro {
        display: grid;
        gap: 0.9rem;
        margin: -0.25rem -0.25rem 0;
        padding: 1rem;
        border-radius: 14px;
        color: #f8fafc;
        background:
            linear-gradient(135deg, rgba(15, 23, 42, 0.95), rgba(17, 94, 89, 0.9)),
            #0f172a;
    }

    .smart-admin-login-brand {
        display: flex;
        align-items: center;
        gap: 0.8rem;
    }

    .smart-admin-login-mark {
        display: grid;
        width: 2.7rem;
        height: 2.7rem;
        place-items: center;
        border: 1px solid rgba(255, 255, 255, 0.32);
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.1);
        font-weight: 800;
        letter-spacing: 0.04em;
    }

    .smart-admin-login-title {
        margin: 0;
        font-size: 0.82rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #99f6e4;
    }

    .smart-admin-login-copy {
        margin: 0.1rem 0 0;
        font-size: 1.05rem;
        font-weight: 800;
        color: #ffffff;
    }

    .smart-admin-login-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .smart-admin-login-meta li {
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 999px;
        padding: 0.32rem 0.62rem;
        background: rgba(255, 255, 255, 0.08);
        color: #d1fae5;
        font-size: 0.76rem;
        font-weight: 700;
    }
</style>

<section class="smart-admin-login-intro" aria-label="SMART COMPANY admin access">
    <div class="smart-admin-login-brand">
        <div class="smart-admin-login-mark">NS</div>
        <div>
            <p class="smart-admin-login-title">SMART COMPANY ERP</p>
            <p class="smart-admin-login-copy">NAHSHON MEP 관리자 접근</p>
        </div>
    </div>

    <ul class="smart-admin-login-meta">
        <li>Staging</li>
        <li>PostgreSQL</li>
        <li>Filament Admin</li>
    </ul>
</section>
