@extends('layouts.app')
@section('title', 'متابعة البلاغ | Report progress — Moshkelti')
@section('content')
@php
    $private = $private ?? false;
    $boundReport = request()->route('report');
    $ownerId = $report->user_id ?? (is_object($boundReport) ? ($boundReport->user_id ?? null) : null);
    $isOwner = $isOwner ?? (auth()->check() && (string) $ownerId === (string) auth()->id());
    $access = auth()->user()?->submissionAccess();
    $statusOnly = $statusOnly ?? ($isOwner && ($access['state'] ?? $access['account_state'] ?? '') === 'banned');
    $canEdit = $private && $isOwner && ! $statusOnly && $report->status === 'needs_more_information' && auth()->user()->canSubmitIssues();
    $reportEvents = $events ?? ($report->relationLoaded('events') ? $report->getRelation('events') : []);
    $reportAttachments = $private && ! $statusOnly && $report->relationLoaded('attachments') ? $report->getRelation('attachments') : [];
@endphp
<div class="breadcrumb"><a href="{{ $isOwner ? route('reports.mine') : route('reports.index') }}"><span lang="ar">البلاغات</span><span lang="en">Reports</span></a><span aria-hidden="true">/</span><span class="tracking-code">{{ $report->tracking_code }}</span></div>
<div class="page-heading"><div><p class="eyebrow"><span lang="ar">خطوة بخطوة</span><span lang="en">Step by step</span></p><h1 class="detail-title" dir="auto">{{ $statusOnly ? 'حالة البلاغ / Report status' : ($private ? $report->title : ($report->public_title ?: 'بلاغ مجتمعي / Community report')) }}</h1><div class="detail-meta"><span class="badge" data-status="{{ $report->status }}" @if(! $statusOnly) data-report-status data-poll-url="{{ route('reports.show', $report->id) }}" @endif>{{ str_replace('_', ' ', $report->status) }}</span><span class="tracking-code">{{ $report->tracking_code }}</span><time datetime="{{ $report->created_at?->toIso8601String() }}">{{ $report->created_at?->format('Y/m/d') }}</time><span class="badge badge-neutral"><span lang="ar">{{ $private ? 'عرض خاص' : 'عرض عام' }}</span><span lang="en">{{ $private ? 'Private view' : 'Public view' }}</span></span></div><p class="field-help" data-poll-feedback role="status"></p></div><a class="button button-secondary button-small" href="{{ route('reports.show', $report->id) }}"><span lang="ar">تحديث الصفحة</span><span lang="en">Refresh page</span></a></div>
@if($statusOnly)
    <div class="notice"><span lang="ar">حسابك مقيّد. تتوفر حالة البلاغ فقط.</span><span lang="en">Your account is restricted. Only the report status is available.</span></div>
    @if($report->guidance)<section class="panel detail-body"><h2><span lang="ar">إرشادات المتابعة</span><span lang="en">Follow-up guidance</span></h2><p class="prose preserve-lines" dir="auto">{{ $report->guidance }}</p></section>@endif
@else
<div class="two-column">
    <div>
        <section class="panel detail-body"><h2><span lang="ar">{{ $private ? 'تفاصيل البلاغ' : 'الملخص المنشور' }}</span><span lang="en">{{ $private ? 'Report details' : 'Published summary' }}</span></h2><div class="prose preserve-lines" dir="auto">{{ $private ? $report->description : ($report->public_summary ?: 'لا يوجد ملخص منشور. / No published summary.') }}</div></section>
        @if($private && ($report->citizen_reason || $report->reason || $report->guidance))
            <section class="panel detail-body"><h2><span lang="ar">توضيح فريق المراجعة</span><span lang="en">Reviewer feedback</span></h2>@if($report->citizen_reason || $report->reason)<p class="prose preserve-lines" dir="auto">{{ $report->citizen_reason ?: $report->reason }}</p>@endif @if($report->guidance)<p class="prose preserve-lines" dir="auto">{{ $report->guidance }}</p>@endif</section>
        @endif
        @if($private && count($reportAttachments))
            <section class="panel detail-body"><h2><span lang="ar">المرفقات الخاصة</span><span lang="en">Private attachments</span></h2><p class="field-help"><span lang="ar">روابط مؤقتة للمستخدمين المخوّلين فقط.</span><span lang="en">Temporary links for authorized users only.</span></p><div class="attachment-grid">@foreach($reportAttachments as $attachment)@if(isset($attachmentUrls[$attachment->id]))<a class="attachment-link" href="{{ $attachmentUrls[$attachment->id] }}" rel="nofollow"><span aria-hidden="true">▧</span> <span lang="ar">صورة {{ $loop->iteration }}</span><span lang="en">Image {{ $loop->iteration }}</span></a>@endif @endforeach</div></section>
        @endif
        <section class="panel detail-body"><h2><span lang="ar">رحلة البلاغ</span><span lang="en">Report timeline</span></h2><ol class="timeline">@forelse($reportEvents as $event)<li><time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->format('Y/m/d H:i') }}</time><p class="preserve-lines" dir="auto">{{ $event->message }}</p></li>@empty<li><p><span lang="ar">لا توجد تحديثات متاحة بعد.</span><span lang="en">No updates are available yet.</span></p></li>@endforelse</ol></section>
        @if($canEdit)
            <form class="panel form-panel" method="POST" action="{{ route('reports.information', $report->id) }}">
                @csrf
                <input type="hidden" name="version" value="{{ $report->version }}">
                <h2><span lang="ar">أضف المعلومات المطلوبة</span><span lang="en">Add the requested information</span></h2><div class="field"><label for="additional-description"><span lang="ar">وصف محدّث</span><span lang="en">Updated description</span></label><textarea id="additional-description" name="description" minlength="20" maxlength="5000" required dir="auto" rows="5">{{ old('description', $report->description) }}</textarea><span class="field-help"><span lang="ar">حتى 5000 حرف. لا تضف بيانات شخصية.</span><span lang="en">Up to 5000 characters. Do not add personal information.</span></span>@error('description')<span class="field-error">{{ $message }}</span>@enderror</div><button type="submit" class="button"><span lang="ar">إرسال المعلومات</span><span lang="en">Send information</span></button>
            </form>
        @endif
    </div>
    <aside class="side-column"><section class="panel aside-panel"><h3><span lang="ar">بطاقة البلاغ</span><span lang="en">Report at a glance</span></h3><dl class="detail-list"><div><dt><span lang="ar">نوع المشكلة</span><span lang="en">Category</span></dt><dd data-category="{{ $report->category }}">{{ str_replace('_', ' ', $report->category ?? '') }}</dd></div><div><dt><span lang="ar">رمز التتبع</span><span lang="en">Tracking code</span></dt><dd class="tracking-code">{{ $report->tracking_code }}</dd></div>@if($private)<div><dt><span lang="ar">المحافظة</span><span lang="en">Governorate</span></dt><dd data-governorate="{{ $report->governorate }}">{{ $report->governorate }}</dd></div><div><dt><span lang="ar">إحداثيات خاصة</span><span lang="en">Private coordinates</span></dt><dd dir="ltr">{{ $report->latitude }}, {{ $report->longitude }}</dd></div>@endif</dl></section><section class="panel aside-panel aside-note"><h3><span lang="ar">متابعة شفافة</span><span lang="en">Clear follow-up</span></h3><p lang="ar">التحديثات هنا تخص بيئة تجريبية. الجهات والاستجابات البلدية محاكاة، وليست إشعاراً رسمياً.</p><p lang="en">Updates belong to a demo environment. Municipalities and responses are simulated and are not official notices.</p></section></aside>
</div>
@endif
@if($isOwner)
    <form class="panel form-panel" method="POST" action="{{ route('reports.appeal', $report->id) }}">
        @csrf
        <h2><span lang="ar">طلب مراجعة القرار</span><span lang="en">Request a decision review</span></h2><p class="muted"><span lang="ar">متاح لصاحب البلاغ، بما في ذلك الحسابات المقيّدة. اشرح سبب طلب المراجعة باحترام.</span><span lang="en">Available to the report owner, including restricted accounts. Explain your request respectfully.</span></p><div class="field"><label for="appeal-reason"><span lang="ar">سبب الاعتراض</span><span lang="en">Reason for appeal</span></label><textarea id="appeal-reason" name="reason" minlength="10" maxlength="2000" required dir="auto" rows="3">{{ old('reason') }}</textarea>@error('reason')<span class="field-error">{{ $message }}</span>@enderror</div><button type="submit" class="button button-secondary"><span lang="ar">إرسال طلب المراجعة</span><span lang="en">Send review request</span></button>
    </form>
@endif
@endsection
