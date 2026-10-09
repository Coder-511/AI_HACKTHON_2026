@extends('layouts.app')

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="confirm-heading">
    <h1 id="confirm-heading" class="section-title"><span lang="ar">تأكيد كلمة المرور</span><span lang="en">Confirm your password</span></h1>
    <p class="field-help"><span lang="ar">أدخل كلمة مرورك الحالية للمتابعة إلى إجراءات الحساب الحساسة.</span><span lang="en">Enter your current password to continue with sensitive account actions.</span></p>
    <form method="POST" action="{{ route('password.confirm.store') }}" class="form-section">
        @csrf
        <div class="field">
            <label for="password" ><span lang="ar">كلمة المرور الحالية</span><span lang="en">Current password</span></label>
            <input id="password" name="password" type="password" required maxlength="128" autocomplete="current-password" dir="ltr">
        </div>
        <button type="submit" class="button"><span lang="ar">تأكيد كلمة المرور</span><span lang="en">Confirm password</span></button>
    </form>
</section>
@endsection
