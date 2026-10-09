@extends('layouts.app')
@section('title', __('Reset your password').' — '.__('Moshkelti'))

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="reset-heading">
    <h1 id="reset-heading" class="section-title"><span lang="ar">إعادة تعيين كلمة المرور</span><span lang="en">Reset your password</span></h1>
    <form method="POST" action="{{ route('password.update') }}" class="form-section">
        @csrf
        <input name="token" type="hidden" value="{{ $token }}">
        <div class="field">
            <label for="email" ><span lang="ar">البريد الإلكتروني</span><span lang="en">Email address</span></label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required maxlength="254" autocomplete="email" dir="ltr">
        </div>
        <div class="field">
            <label for="password" ><span lang="ar">كلمة المرور الجديدة</span><span lang="en">New password</span></label>
            <input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="password-help" dir="ltr">
            <p id="password-help" class="field-help"><span lang="ar">استخدم من 12 إلى 128 حرفاً.</span><span lang="en">Use 12–128 characters.</span></p>
        </div>
        <div class="field">
            <label for="password_confirmation" ><span lang="ar">تأكيد كلمة المرور الجديدة</span><span lang="en">Confirm new password</span></label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" dir="ltr">
        </div>
        <button type="submit" class="button"><span lang="ar">إعادة تعيين كلمة المرور</span><span lang="en">Reset password</span></button>
    </form>
    <a href="{{ route('password.request') }}" class="text-link"><span lang="ar">طلب رابط إعادة تعيين جديد</span><span lang="en">Request a new reset link</span></a>
</section>
@endsection
