@extends('layouts.app')
@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="register-heading">
    <h1 id="register-heading" class="section-title"><span lang="ar">انضم إلى مشكلتي</span><span lang="en">Join Moshkelti</span></h1>
    <p class="field-help"><span lang="ar">الرقم الوطني ورقم الهاتف ← رمز تحقق ← كلمة المرور. يجب أن يطابق الهاتف بيانات السجل.</span><span lang="en">National ID + mobile → verification code → password. Your mobile must match the identity registry.</span></p>
    @if(app()->environment('local'))
        <p class="field-help"><span lang="ar">محاكاة محلية: لا نرسل رسائل هاتف فعلية. الاختبار: 0000000001 مع 0790000001 (أو 0000000002 مع 0790000002). الرمز في سجل SMS الخاص: storage/logs/sms.log. لا تستخدم بيانات حقيقية.</span><span lang="en">Local simulation: no real SMS is sent. Test: 0000000001 + 0790000001 (or 0000000002 + 0790000002). Codes are in private storage/logs/sms.log. Do not use real identity data.</span></p>
    @endif
    @if($stage === 'password')
    <form method="POST" action="{{ route('register.store') }}" class="form-section">
        @csrf
        <h2><span lang="ar">اختر كلمة المرور</span><span lang="en">Choose your password</span></h2>
        <div class="field"><label for="password"><span lang="ar">كلمة المرور</span><span lang="en">Password</span></label><input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" dir="ltr"></div>
        <p class="field-help"><span lang="ar">استخدم من 12 إلى 128 حرفاً.</span><span lang="en">Use 12–128 characters.</span></p>
        <div class="field"><label for="password_confirmation"><span lang="ar">تأكيد كلمة المرور</span><span lang="en">Confirm password</span></label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" dir="ltr"></div>
        <button type="submit" class="button"><span lang="ar">إنشاء الحساب</span><span lang="en">Create account</span></button>
    </form>
    @elseif($stage === 'code')
    <form method="POST" action="{{ route('register.verify') }}" class="form-section">
        @csrf
        <div class="field"><label for="code"><span lang="ar">رمز التحقق</span><span lang="en">Verification code</span></label><input id="code" name="code" type="text" required minlength="6" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" dir="ltr"></div>
        <button type="submit" class="button"><span lang="ar">التحقق والمتابعة</span><span lang="en">Verify and continue</span></button>
    </form>
    @endif
    <form method="POST" action="{{ route('register.identity') }}" class="form-section">
        @csrf
        <div class="field"><label for="national_id"><span lang="ar">الرقم الوطني (10 أرقام)</span><span lang="en">National ID (10 digits)</span></label><input id="national_id" name="national_id" type="text" required minlength="10" maxlength="10" inputmode="numeric" autocomplete="off" dir="ltr"></div>
        <div class="field"><label for="mobile_number"><span lang="ar">رقم الهاتف المسجل</span><span lang="en">Registry mobile number</span></label><input id="mobile_number" name="mobile_number" type="tel" required maxlength="20" autocomplete="tel" dir="ltr" placeholder="0790000001"></div>
        <label class="checkbox-label"><input type="checkbox" name="registry_consent" value="1" required><span lang="ar">أوافق على التحقق من بيانات السجل وفق إشعار الخصوصية.</span><span lang="en">I consent to the registry identity check under the privacy notice.</span></label>
        <a href="{{ route('privacy') }}">الخصوصية / Privacy</a>
        <button type="submit" class="button"><span lang="ar">طلب رمز تحقق</span><span lang="en">{{ $stage === 'identity' ? 'Request SMS code' : 'Restart / request new SMS code' }}</span></button>
    </form>
    <a href="{{ route('login') }}">تسجيل الدخول / Sign in</a>
</section>
@endsection
