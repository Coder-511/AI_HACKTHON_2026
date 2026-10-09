@php
    $passwordConfirmed = app(\App\Services\AdminVerification::class)->passwordConfirmed(request());
@endphp
<aside class="notice admin-security" aria-label="{{ app()->getLocale() === 'ar' ? 'حالة أمان الجلسة' : 'Session security status' }}">
    <div>
        <span class="badge badge-neutral"><span lang="ar">التحقق الثنائي: مُتحقَّق لهذه الجلسة</span><span lang="en">Two-factor: verified for this session</span></span>
        <span class="badge badge-neutral" data-admin-password-status data-confirmed-at="{{ is_int(session('auth.password_confirmed_at')) ? session('auth.password_confirmed_at') : 0 }}" data-server-now="{{ now()->getTimestamp() * 1000 }}">
            @if($passwordConfirmed)
                <span lang="ar">كلمة المرور: تأكيد حديث</span><span lang="en">Password: recently confirmed</span>
            @else
                <span lang="ar">كلمة المرور: سيُطلب تأكيدها قبل الحفظ</span><span lang="en">Password: confirmation required before saving</span>
            @endif
        </span>
        <p class="field-help"><span lang="ar">تأكيد كلمة المرور صالح لمدة 15 دقيقة. عند الحاجة سيُطلب منك التأكيد ثم العودة إلى صفحة المراجعة. لا يُحفظ أو يُعاد إرسال أي قرار تلقائياً.</span><span lang="en">Password confirmation lasts 15 minutes. When needed, you will be asked to confirm and returned to the review page. No decision is saved or resubmitted automatically.</span></p>
        <a class="button button-secondary button-small" data-admin-confirm-required @if($passwordConfirmed) hidden @endif href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('password.confirm', now()->addMinutes(30), ['context' => request()->route()->getName(), 'report' => request()->route('report')?->getRouteKey()]) }}"><span lang="ar">أكّد كلمة المرور قبل بدء الإجراء</span><span lang="en">Confirm password before starting an action</span></a>
    </div>
</aside>
