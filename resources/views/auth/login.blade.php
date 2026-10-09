@extends('layouts.app')

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="login-heading">
    <h1 id="login-heading" class="section-title"><span lang="ar">تسجيل الدخول إلى مشكلتي</span><span lang="en">Sign in to Moshkelti</span></h1>
    <form method="POST" action="{{ route('login.store') }}" class="form-section">
        @csrf
        <div class="field">
            <label for="email" ><span lang="ar">البريد الإلكتروني</span><span lang="en">Email address</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="254" autocomplete="username" dir="ltr">
        </div>
        <div class="field">
            <label for="password" ><span lang="ar">كلمة المرور</span><span lang="en">Password</span></label>
            <input id="password" name="password" type="password" required maxlength="128" autocomplete="current-password" dir="ltr">
        </div>
        <label class="checkbox-label"><input name="remember" type="checkbox" value="1" > <span lang="ar">تذكرني</span><span lang="en">Remember me</span></label>
        <button type="submit" class="button"><span lang="ar">تسجيل الدخول</span><span lang="en">Sign in</span></button>
    </form>
    <div class="form-actions">
        <a href="{{ route('password.request') }}" class="text-link"><span lang="ar">نسيت كلمة المرور؟</span><span lang="en">Forgot your password?</span></a>
        <a href="{{ route('register') }}" class="text-link"><span lang="ar">إنشاء حساب</span><span lang="en">Create an account</span></a>
    </div>
</section>
@endsection
