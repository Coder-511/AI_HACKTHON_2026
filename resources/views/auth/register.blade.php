@extends('layouts.app')

@section('content')
<section class="panel form-panel narrow-content" aria-labelledby="register-heading">
    <div>
        <h1 id="register-heading" class="section-title"><span lang="ar">انضم إلى مشكلتي</span><span lang="en">Join Moshkelti</span></h1>
        <p class="field-help"><span lang="ar">التسجيل بالرقم الوطني والتحقق عبر البريد المسجل لدى مزود الهوية. لا يظهر الرقم الوطني في التقارير.</span><span lang="en">Register with your national ID and verify through the email held by the identity provider. Your national ID never appears in reports.</span></p>
        @if(app()->environment('local') && config('identity.driver') === 'mock_database')
            <p class="field-help"><span lang="ar">بيئة تجريبية ببيانات وهمية، وليست متصلة بقاعدة حكومية. أرقام الاختبار: 0000000001 و0000000002 و0000000003. رسائل التحقق في سجل البريد المحلي المحمي.</span><span lang="en">Synthetic local registry, not a government connection. Test IDs: 0000000001, 0000000002, 0000000003. Verification messages use the protected local mail log.</span></p>
        @endif
    </div>
    @if($challengePending)
    <form method="POST" action="{{ route('register.store') }}" class="form-section">
        @csrf
        <div class="field">
            <label for="code"><span lang="ar">رمز التحقق</span><span lang="en">Verification code</span></label>
            <input id="code" name="code" type="text" required minlength="6" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" dir="ltr">
        </div>
        <div class="field">
            <label for="password"><span lang="ar">كلمة المرور</span><span lang="en">Password</span></label>
            <input id="password" name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="password-help" dir="ltr">
            <p id="password-help" class="field-help"><span lang="ar">استخدم من 12 إلى 128 حرفاً.</span><span lang="en">Use 12–128 characters.</span></p>
        </div>
        <div class="field">
            <label for="password_confirmation"><span lang="ar">تأكيد كلمة المرور</span><span lang="en">Confirm password</span></label>
            <input id="password_confirmation" name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password" dir="ltr">
        </div>
        <button type="submit" class="button"><span lang="ar">التحقق وإنشاء الحساب</span><span lang="en">Verify and create account</span></button>
    </form>
    @endif
    <form method="POST" action="{{ route('register.identity') }}" class="form-section">
        @csrf
        <div class="field">
            <label for="national_id"><span lang="ar">الرقم الوطني (10 أرقام)</span><span lang="en">National ID (10 digits)</span></label>
            <input id="national_id" name="national_id" type="text" required minlength="10" maxlength="10" inputmode="numeric" autocomplete="off" dir="ltr" aria-describedby="identity-help">
            <p id="identity-help" class="field-help"><span lang="ar">يُرسل الرمز إلى البريد المسجل لدى المزود؛ لا يمكن اختيار بريد بديل. يلزم ربط حكومي معتمد للاستخدام الفعلي.</span><span lang="en">The code goes only to the provider-held email; you cannot choose another address. Real use requires an approved identity integration.</span></p>
        </div>
        <div class="field">
            <label><input type="checkbox" name="registry_consent" value="1" required> <span lang="ar">أوافق على التحقق من هويتي باستخدام بيانات السجل وفق إشعار الخصوصية.</span><span lang="en">I consent to checking my identity against the registry under the privacy notice.</span></label>
            <a href="{{ route('privacy') }}" class="text-link"><span lang="ar">إشعار الخصوصية</span><span lang="en">Privacy notice</span></a>
        </div>
        <button type="submit" class="button"><span lang="ar">طلب رمز تحقق</span><span lang="en">{{ $challengePending ? 'Request a new code / restart' : 'Request verification code' }}</span></button>
    </form>
    <p class="field-help"><span lang="ar">لديك حساب بالفعل؟</span><span lang="en">Already have an account?</span> <a href="{{ route('login') }}" class="text-link"><span lang="ar">تسجيل الدخول</span><span lang="en">Sign in</span></a></p>
</section>
@endsection
