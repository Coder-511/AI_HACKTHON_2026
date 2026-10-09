@extends('layouts.app')
@section('title', __('Reports').' — '.__('Moshkelti'))
@section('content')
@php($isMine = $isMine ?? false)
<div class="page-heading">
    <div><p class="eyebrow"><span lang="ar">{{ $isMine ? 'مساحتك للمتابعة' : 'نبض الحي' }}</span><span lang="en">{{ $isMine ? 'Your follow-up space' : 'The neighbourhood pulse' }}</span></p><h1><span lang="ar">{{ $isMine ? 'بلاغاتي' : 'صوت الناس. أثر مشترك.' }}</span><span lang="en">{{ $isMine ? 'My reports' : 'Local voices. Shared progress.' }}</span></h1><p lang="ar">{{ $isMine ? 'تابع حالة بلاغاتك وافتح البلاغ للاطّلاع على التحديثات المتاحة لحسابك.' : 'بلاغات راجعها الفريق للنشر. لا تُعرض الأوصاف الخاصة أو المواقع الدقيقة أو المرفقات للعامة.' }}</p><p lang="en">{{ $isMine ? 'Follow your reports and open each one for the updates available to your account.' : 'Reports reviewed for publication. Private descriptions, precise locations and attachments are not public.' }}</p></div>
    @auth
        @if(auth()->user()->canSubmitIssues())
            <a class="button button-orange" href="{{ route('reports.create') }}"><span aria-hidden="true">＋</span><span lang="ar">بلاغ جديد</span><span lang="en">New report</span></a>
        @endif
    @endauth
</div>
<div class="collection-bar">
    <nav class="tabs" aria-label="{{ __("Report collections") }}">
        <a href="{{ route('reports.index') }}" @if(! $isMine) aria-current="page" @endif><span lang="ar">البلاغات العامة</span><span lang="en">Public reports</span></a>
        @auth
            @unless(auth()->user()->is_admin)
                <a href="{{ route('reports.mine') }}" @if($isMine) aria-current="page" @endif><span lang="ar">بلاغاتي</span><span lang="en">My reports</span></a>
            @endunless
        @endauth
    </nav>
    <span class="muted"><span lang="ar">{{ $reports->total() }} بلاغ</span><span lang="en">{{ $reports->total() }} reports</span></span>
</div>
<div class="report-grid">
    @forelse($reports as $report)
        <article class="panel report-card">
            <div class="report-card-top"><span class="badge badge-neutral" data-category="{{ $report->category }}">{{ str_replace('_', ' ', $report->category ?? '') }}</span><span class="badge" data-status="{{ $report->status }}">{{ str_replace('_', ' ', $report->status) }}</span></div>
            <h2 dir="auto"><a href="{{ route('reports.show', $report->id) }}">{{ $report->public_title ?: ($isMine ? __('Report in progress') : __('Community report')) }}</a></h2>
            <p dir="auto">{{ $report->public_summary ?: ($isMine ? __('Open the report to follow its status.') : __('No published summary.')) }}</p>
            <div class="report-card-bottom"><span class="tracking-code">{{ $report->tracking_code }}</span><time datetime="{{ $report->created_at?->toIso8601String() }}">{{ $report->created_at?->format('Y/m/d') }}</time><a href="{{ route('reports.show', $report->id) }}" aria-label="{{ __('View report') }} {{ $report->tracking_code }}"><span class="direction-arrow" aria-hidden="true">←</span></a></div>
        </article>
    @empty
        <div class="empty-state"><span class="empty-symbol" aria-hidden="true">◎</span><h2><span lang="ar">{{ $isMine ? 'رحلتك تبدأ ببلاغ' : 'لا توجد بلاغات منشورة بعد' }}</span><span lang="en">{{ $isMine ? 'Your journey starts with a report' : 'No published reports yet' }}</span></h2><p lang="ar">{{ $isMine ? 'ستظهر بلاغاتك هنا لتتابعها خطوة بخطوة.' : 'ستظهر هنا البلاغات المعتمدة للنشر بعد مراجعتها.' }}</p><p lang="en">{{ $isMine ? 'Your reports will appear here so you can follow every step.' : 'Reports will appear here after they are reviewed and approved for publication.' }}</p></div>
    @endforelse
</div>
@if($reports->hasPages())
    <nav class="pagination" aria-label="{{ __("Report pages") }}">
        @if($reports->previousPageUrl())<a class="button button-secondary button-small" href="{{ $reports->previousPageUrl() }}"><span lang="ar">السابق</span><span lang="en">Previous</span></a>@endif
        <span><span lang="ar">صفحة {{ $reports->currentPage() }} من {{ $reports->lastPage() }}</span><span lang="en">Page {{ $reports->currentPage() }} of {{ $reports->lastPage() }}</span></span>
        @if($reports->nextPageUrl())<a class="button button-secondary button-small" href="{{ $reports->nextPageUrl() }}"><span lang="ar">التالي</span><span lang="en">Next</span></a>@endif
    </nav>
@endif
@endsection
