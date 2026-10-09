@php
    $locale = app()->getLocale();
    $currentUser = auth()->user();
    $submissionAccess = $currentUser?->submissionAccess();
    $accountRestricted = $submissionAccess && $submissionAccess['account_state'] !== 'active';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#173f35">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Moshkelti'))</title>
    <link rel="stylesheet" href="{{ asset('css/moshkelti.css') }}">
    <script src="{{ asset('js/moshkelti.js') }}" defer></script>
    @stack('scripts')
</head>
<body>
    <a class="skip-link" href="#main"><span lang="ar">انتقل إلى المحتوى</span><span lang="en">Skip to content</span></a>
    <div class="demo-bar"><div class="container"><span class="demo-dot" aria-hidden="true"></span><span lang="ar">نسخة تجريبية • الجهات البلدية والاستجابات محاكاة</span><span lang="en">Demo platform • Municipalities and responses are simulated</span></div></div>
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark" aria-hidden="true">{{ $locale === 'ar' ? 'م' : 'M' }}<span></span></span>
                <span><strong lang="ar">مشكلتي</strong><strong lang="en">Moshkelti</strong><small lang="ar">صوتك يصنع الفرق</small><small lang="en">Your voice, a better place</small></span>
            </a>
            <div class="header-tools">
                <button class="language-toggle" type="button" data-language-toggle aria-label="{{ __("Switch language") }}"><span lang="ar">English <b aria-hidden="true">↗</b></span><span lang="en">العربية <b aria-hidden="true">↗</b></span></button>
                <button class="menu-toggle" type="button" data-menu-toggle aria-controls="primary-nav" aria-expanded="false"><span lang="ar">القائمة</span><span lang="en">Menu</span><span aria-hidden="true">☰</span></button>
            </div>
            <nav class="primary-nav" id="primary-nav" aria-label="{{ __("Main navigation") }}">
                <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif><span lang="ar">الرئيسية</span><span lang="en">Home</span></a>
                @unless($currentUser?->is_admin)
                <a href="{{ route('reports.index') }}" @if(request()->routeIs('reports.index')) aria-current="page" @endif><span lang="ar">البلاغات</span><span lang="en">Reports</span></a>
                @endunless
                <a href="{{ route('news.index') }}" @if(request()->routeIs('news.index')) aria-current="page" @endif><span lang="ar">المستجدات</span><span lang="en">News</span></a>
                @auth
                    @unless($currentUser->is_admin)
                    <a href="{{ route('reports.mine') }}" @if(request()->routeIs('reports.mine')) aria-current="page" @endif><span lang="ar">بلاغاتي</span><span lang="en">My reports</span></a>
                    @endunless
                    @if($currentUser->is_admin && ! $accountRestricted)
                        <a href="{{ route('admin.index') }}" @if(request()->routeIs('admin.index', 'admin.reports.*')) aria-current="page" @endif><span lang="ar">لوحة الإدارة</span><span lang="en">Admin dashboard</span></a>
                        <a href="{{ route('admin.users') }}" @if(request()->routeIs('admin.users')) aria-current="page" @endif><span lang="ar">الحسابات والقيود</span><span lang="en">Accounts and restrictions</span></a>
                        <a href="{{ route('reports.index') }}" @if(request()->routeIs('reports.index')) aria-current="page" @endif><span lang="ar">العرض العام</span><span lang="en">Public view</span></a>
                    @endif
                    @if($currentUser->canSubmitIssues())
                        <a class="button button-small" href="{{ route('reports.create') }}"><span aria-hidden="true">＋</span><span lang="ar">قدّم بلاغاً</span><span lang="en">Report an issue</span></a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}" class="inline-form">@csrf<button class="nav-button" type="submit"><span lang="ar">خروج</span><span lang="en">Log out</span></button></form>
                @else
                    <a href="{{ route('login') }}" @if(request()->routeIs('login', 'admin.login')) aria-current="page" @endif><span lang="ar">دخول</span><span lang="en">Log in</span></a>
                    <a class="button button-small" href="{{ route('register') }}"><span lang="ar">انضم إلينا</span><span lang="en">Join us</span></a>
                @endauth
            </nav>
        </div>
    </header>
    @if($submissionAccess && ! $submissionAccess['can_submit'] && (! $currentUser->is_admin || $accountRestricted))
        <aside class="container restriction-banner" role="status">
            <span class="notice-icon" aria-hidden="true">!</span>
            <div>
                @if($currentUser->is_admin)
                    <strong><span lang="ar">الوصول الإداري موقوف بسبب قيد على الحساب</span><span lang="en">Administrative access is restricted</span></strong>
                    <p><span lang="ar">يمكنك تسجيل الدخول وقراءة الصفحات العامة. مراجعة البلاغات والمرفقات الخاصة والإجراءات الإدارية غير متاحة. تواصل مع مشغّل النظام لمراجعة القيد.</span><span lang="en">You can sign in and read public pages. Private reports, evidence and administrative actions are unavailable. Contact the system operator to review the restriction.</span></p>
                @else
                    <strong><span lang="ar">حسابك في وضع القراءة فقط</span><span lang="en">Your account is read-only</span></strong>
                    <p><span lang="ar">يمكنك متابعة الحالة. تقديم البلاغات والتعديلات غير متاح حالياً.</span><span lang="en">You can follow report status. Submissions and changes are currently unavailable.</span></p>
                @endif
                @if($submissionAccess['reason'] ?? null)
                    <p data-access-reason="{{ $submissionAccess['reason'] }}">{{ str_replace('_', ' ', $submissionAccess['reason']) }}</p>
                @endif
                @if($submissionAccess['available_at'])
                    <p><span lang="ar">الوقت المتبقي وفق ساعة الخادم:</span><span lang="en">Time remaining, based on the server clock:</span>
                        <strong class="countdown" data-countdown data-server-now="{{ $submissionAccess['server_now'] }}" data-available-at="{{ $submissionAccess['available_at'] }}" data-remaining-seconds="{{ $submissionAccess['remaining_seconds'] }}">{{ $submissionAccess['remaining_seconds'] }} <span lang="ar">ثانية</span><span lang="en">seconds</span></strong>
                    </p>
                @elseif($accountRestricted)
                    <p><span lang="ar">لا يوجد موعد تلقائي لإعادة التفعيل.</span><span lang="en">No automatic reactivation date is set.</span></p>
                @endif
                @if(! $currentUser->is_admin && $accountRestricted && Route::has('account.appeal'))
                    <details class="account-review"><summary><span lang="ar">طلب مراجعة قيد الحساب</span><span lang="en">Request an account restriction review</span></summary><form method="POST" action="{{ route('account.appeal') }}">@csrf<div class="field"><label for="account-appeal-reason"><span lang="ar">سبب طلب المراجعة</span><span lang="en">Reason for the review request</span></label><textarea id="account-appeal-reason" name="reason" required minlength="10" maxlength="2000" rows="3" dir="auto"></textarea></div><button class="button button-secondary button-small" type="submit"><span lang="ar">إرسال الطلب</span><span lang="en">Send request</span></button></form></details>
                @endif
            </div>
        </aside>
    @endif
    <main id="main" class="container main-content" tabindex="-1">
        @if(session('status') || session('success'))
            <div class="notice notice-success" role="status">{{ __(session('success') ?? session('status')) }}</div>
        @endif
        @if(session('error'))
            <div class="notice notice-error" role="alert">{{ __(session('error')) }}</div>
        @endif
        @if($errors->any())
            <div class="notice notice-error" role="alert" tabindex="-1" data-error-summary>
                <strong><span lang="ar">يرجى مراجعة البيانات التالية</span><span lang="en">Please check the following details</span></strong>
                <ul>@foreach($errors->all() as $error)<li>{{ __($error) }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>
    <footer class="site-footer">
        <div class="container footer-inner">
            <div><strong lang="ar">مشكلتي. مكان أفضل، معاً.</strong><strong lang="en">Moshkelti. Better places, together.</strong><p lang="ar">مبادرة تجريبية للمشاركة المدنية في الأردن.</p><p lang="en">A civic participation demo for Jordan.</p></div>
            <div class="footer-links"><a href="{{ route('privacy') }}"><span lang="ar">الخصوصية</span><span lang="en">Privacy</span></a><a href="{{ route('news.index') }}"><span lang="ar">المستجدات</span><span lang="en">News</span></a><span class="footer-note" lang="ar">ليست خدمة طوارئ</span><span class="footer-note" lang="en">Not an emergency service</span></div>
        </div>
    </footer>
</body>
</html>
