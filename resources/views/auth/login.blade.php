@extends('layouts.app')
@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="login-heading">
    <h1 id="login-heading" class="section-title"><span lang="ar">{{ $administratorLogin ? 'دخول الإدارة' : 'تسجيل الدخول إلى مشكلتي' }}</span><span lang="en">{{ $administratorLogin ? 'Administrator sign in' : 'Sign in to Moshkelti' }}</span></h1>
    <form method="POST" action="{{ route($administratorLogin ? 'admin.login.store' : 'login.store') }}" class="form-section">
        @csrf
        <div class="field">
            @if($administratorLogin)
                <label for="email"><span lang="ar">بريد الإدارة</span><span lang="en">Administrator email</span></label>
                <input id="email" name="email" type="email" required maxlength="254" autocomplete="username" dir="ltr">
            @else
                <label for="national_id"><span lang="ar">الرقم الوطني</span><span lang="en">National ID</span></label>
                <input id="national_id" name="national_id" type="text" required minlength="10" maxlength="10" inputmode="numeric" autocomplete="username" dir="ltr">
            @endif
        </div>
        <div class="field">
            <label for="password"><span lang="ar">كلمة المرور</span><span lang="en">Password</span></label>
            <input id="password" name="password" type="password" required maxlength="128" autocomplete="current-password" dir="ltr">
        </div>
        <label class="checkbox-label"><input name="remember" type="checkbox" value="1"> <span lang="ar">تذكرني</span><span lang="en">Remember me</span></label>
        <button type="submit" class="button"><span lang="ar">تسجيل الدخول</span><span lang="en">Sign in</span></button>
    </form>
    <div class="form-actions">
        <a href="{{ route('password.request') }}" class="text-link"><span lang="ar">استعادة كلمة المرور بالبريد المسجل</span><span lang="en">Recover password using registry email</span></a>
        <a href="{{ route('register') }}" class="text-link"><span lang="ar">إنشاء حساب</span><span lang="en">Create an account</span></a>
        @unless($administratorLogin)<a href="{{ route('admin.login') }}">دخول الإدارة / Administrator sign in</a>@endunless
    </div>
</section>
@endsection
