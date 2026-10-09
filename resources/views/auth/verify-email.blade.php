@extends('layouts.app')

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="verification-heading">
    <h1 id="verification-heading" class="section-title"><span lang="ar">تأكيد بريدك الإلكتروني</span><span lang="en">Verify your email address</span></h1>
    <p class="field-help"><span lang="ar">استخدم الرابط المرسل إلى بريدك لتأكيد حسابك في مشكلتي. يمكنك طلب رابط جديد أدناه.</span><span lang="en">Use the link sent to your email to verify your Moshkelti account. You can request a new link below.</span></p>
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <button type="submit" class="button"><span lang="ar">إعادة إرسال رابط التأكيد</span><span lang="en">Resend verification link</span></button>
    </form>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="text-link"><span lang="ar">تسجيل الخروج</span><span lang="en">Sign out</span></button>
    </form>
</section>
@endsection
