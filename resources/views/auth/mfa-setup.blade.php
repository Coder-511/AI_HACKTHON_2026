@extends('layouts.app')

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="mfa-heading">
    <h1 id="mfa-heading" class="section-title"><span lang="ar">إعداد المصادقة للمسؤول</span><span lang="en">Set up administrator authentication</span></h1>
    <p class="field-help"><span lang="ar">أضف حساباً يعتمد على الوقت في تطبيق المصادقة باستخدام مفتاح الإعداد هذا. حافظ على سريته.</span><span lang="en">Add a time-based account to your authenticator app using this setup key. Keep the key private.</span></p>
    <div class="notice">
        <p ><span lang="ar">مفتاح الإعداد اليدوي</span><span lang="en">Manual setup key</span></p>
        <code class="tracking-code" dir="ltr">{{ $secret }}</code>
        <p class="field-help"><span lang="ar">اختر رموزاً تعتمد على الوقت، SHA-1، و6 أرقام، وفترة 30 ثانية.</span><span lang="en">Choose time-based codes, SHA-1, 6 digits, and a 30-second interval.</span></p>
        <a href="{{ $provisioningUri }}" class="text-link"><span lang="ar">فتح في تطبيق المصادقة</span><span lang="en">Open in an authenticator app</span></a>
    </div>
    <p class="field-help"><span lang="ar">أدخل الرمز الحالي لإكمال الإعداد. تنتهي صلاحية المفتاح بعد 10 دقائق.</span><span lang="en">Enter the current code to finish setup. This setup key expires after 10 minutes.</span></p>
    <form method="POST" action="{{ route('mfa.enable') }}" class="form-section">
        @csrf
        <div class="field">
            <label for="code" ><span lang="ar">رمز المصادقة</span><span lang="en">Authenticator code</span></label>
            <input id="code" name="code" type="text" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" dir="ltr">
        </div>
        <button type="submit" class="button"><span lang="ar">تفعيل المصادقة</span><span lang="en">Enable authentication</span></button>
    </form>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="text-link"><span lang="ar">تسجيل الخروج</span><span lang="en">Sign out</span></button>
    </form>
</section>
@endsection
