@extends('layouts.app')

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="mfa-heading">
    <h1 id="mfa-heading" class="section-title"><span lang="ar">تأكيد دخول المسؤول</span><span lang="en">Verify administrator sign in</span></h1>
    <p class="field-help"><span lang="ar">أدخل الرمز الحالي المكون من 6 أرقام من تطبيق المصادقة.</span><span lang="en">Enter the current 6-digit code from your authenticator app.</span></p>
    <form method="POST" action="{{ route('mfa.verify') }}" class="form-section">
        @csrf
        <div class="field">
            <label for="code" ><span lang="ar">رمز المصادقة</span><span lang="en">Authenticator code</span></label>
            <input id="code" name="code" type="text" required inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" dir="ltr">
        </div>
        <button type="submit" class="button"><span lang="ar">تأكيد الرمز</span><span lang="en">Verify code</span></button>
    </form>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="text-link"><span lang="ar">تسجيل الخروج</span><span lang="en">Sign out</span></button>
    </form>
</section>
@endsection
