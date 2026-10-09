@extends('layouts.app')

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="forgot-heading">
    <h1 id="forgot-heading" class="section-title"><span lang="ar">نسيت كلمة المرور؟</span><span lang="en">Forgot your password?</span></h1>
    <p class="field-help"><span lang="ar">أدخل بريد حسابك لطلب رابط إعادة تعيين كلمة المرور.</span><span lang="en">Enter your account email to request a password reset link.</span></p>
    <form method="POST" action="{{ route('password.email') }}" class="form-section">
        @csrf
        <div class="field">
            <label for="email" ><span lang="ar">البريد الإلكتروني</span><span lang="en">Email address</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email" dir="ltr">
        </div>
        <button type="submit" class="button"><span lang="ar">إرسال رابط إعادة التعيين</span><span lang="en">Send reset link</span></button>
    </form>
    <a href="{{ route('login') }}" class="text-link"><span lang="ar">العودة إلى تسجيل الدخول</span><span lang="en">Back to sign in</span></a>
</section>
@endsection
