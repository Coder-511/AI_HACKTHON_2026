@extends('layouts.app')
@section('title', __('Your voice, a better place').' — '.__('Moshkelti'))
@section('content')
@if(! request()->routeIs('news.index'))
    <section class="hero">
        <div class="hero-copy">
            <div class="eyebrow"><span class="small-dot" aria-hidden="true"></span><span lang="ar">من حارتك، يبدأ التغيير</span><span lang="en">Change starts in your neighbourhood</span></div>
            <h1><span lang="ar">مشكلتك اليوم.<br><em>أثرنا بكرة.</em></span><span lang="en">Your issue today.<br><em>A better tomorrow.</em></span></h1>
            <p class="hero-description" lang="ar">شارع يحتاج صيانة؟ إنارة غائبة؟ خلّينا نسمعك. وثّق المشكلة، تابع بلاغك، وكن جزءاً من تحسين المكان الذي نسميه بيتاً.</p>
            <p class="hero-description" lang="en">A damaged road? A broken streetlight? Make it heard. Document the issue, follow its progress, and help improve the place we call home.</p>
            <div class="actions">
                @auth
                    @if(auth()->user()->is_admin)
                        @if(auth()->user()->canReviewReports())
                            <a class="button" href="{{ route('admin.index') }}"><span lang="ar">لوحة الإدارة</span><span lang="en">Admin dashboard</span></a>
                        @endif
                    @elseif(auth()->user()->canSubmitIssues())
                        <a class="button button-orange" href="{{ route('reports.create') }}"><span lang="ar">بلّغ عن مشكلة</span><span lang="en">Report an issue</span><span aria-hidden="true">＋</span></a>
                    @else
                        <a class="button" href="{{ route('reports.mine') }}"><span lang="ar">تابع بلاغاتي</span><span lang="en">Follow my reports</span></a>
                    @endif
                @else
                    <a class="button button-orange" href="{{ route('login') }}"><span lang="ar">بلّغ عن مشكلة</span><span lang="en">Report an issue</span><span aria-hidden="true">＋</span></a>
                @endauth
                <a class="text-link" href="{{ route('reports.index') }}"><span lang="ar">استكشف البلاغات</span><span lang="en">Explore reports</span><span class="direction-arrow" aria-hidden="true">←</span></a>
            </div>
            <p class="hero-footnote"><span aria-hidden="true">◈</span><span lang="ar">خصوصية أولاً. متابعة واضحة. مشاركة مسؤولة.</span><span lang="en">Privacy first. Clear progress. Responsible participation.</span></p>
        </div>
        <div class="civic-art" aria-hidden="true">
            <div class="art-orbit"></div><div class="art-sun"></div>
            <div class="art-caption"><span lang="ar">الأردن، أجمل بمشاركتك</span><span lang="en">Jordan, better with you</span><span>↗</span></div>
            <div class="city"><div class="building building-one"><i></i><i></i><i></i><i></i></div><div class="building building-two"><i></i><i></i><i></i><i></i><i></i><i></i></div><div class="building building-three"><i></i><i></i></div><div class="art-tree"></div><div class="art-road"></div><div class="art-path"></div></div>
            <div class="art-card"><span class="art-pin">◎</span><div><strong lang="ar">كل بلاغ، خطوة للأفضل</strong><strong lang="en">Every report is a step forward</strong><small lang="ar">شارك • تابع • اصنع فرقاً</small><small lang="en">Share • Follow • Make a difference</small></div></div>
        </div>
    </section>
    <section class="how-section" aria-labelledby="how-title">
        <div class="section-heading"><div><p class="eyebrow"><span lang="ar">بسيطة وواضحة</span><span lang="en">Simple and transparent</span></p><h2 id="how-title"><span lang="ar">من الملاحظة إلى المتابعة</span><span lang="en">From noticing to following up</span></h2></div><span class="muted" lang="ar">أنت البداية.</span><span class="muted" lang="en">It starts with you.</span></div>
        <div class="steps-grid">
            <article class="step-card"><span class="step-number">01</span><h3><span lang="ar">وثّق المشكلة</span><span lang="en">Document the issue</span></h3><p lang="ar">وصف واضح وموقع دقيق. أضف صوراً خالية من المعلومات الشخصية إن احتجت.</p><p lang="en">Add a clear description and location. Attach images without personal details if useful.</p></article>
            <article class="step-card"><span class="step-number">02</span><h3><span lang="ar">مراجعة مسؤولة</span><span lang="en">Responsible review</span></h3><p lang="ar">فرز آلي عبر موائم محلي يساعد فريق المراجعة. القرارات والمعلومات المنشورة تخضع للمراجعة.</p><p lang="en">Automated triage through a local adapter assists the review team. Decisions and public information are reviewed.</p></article>
            <article class="step-card"><span class="step-number">03</span><h3><span lang="ar">تابع كل خطوة</span><span lang="en">Follow every step</span></h3><p lang="ar">احتفظ برمز التتبع، واطّلع على الحالة والتحديثات من صفحة بلاغك.</p><p lang="en">Keep your tracking code and check your report page for status and updates.</p></article>
        </div>
    </section>
@endif
<section class="news-section" aria-labelledby="news-title">
    <div class="section-heading"><div><p class="eyebrow"><span lang="ar">نبقى على تواصل</span><span lang="en">Stay in the loop</span></p><h2 id="news-title"><span lang="ar">آخر المستجدات</span><span lang="en">Latest updates</span></h2></div><span class="badge"><span lang="ar">تحديثات المنصة التجريبية</span><span lang="en">Demo platform updates</span></span></div>
    <div class="news-grid">
        @forelse($news ?? [] as $item)
            <article class="panel news-card"><time class="eyebrow" datetime="{{ $item->published_at?->toIso8601String() }}">{{ $item->published_at?->format('Y/m/d') }}</time><h3 dir="auto">{{ __($item->title) }}</h3><div class="prose preserve-lines" dir="auto">{{ __($item->body) }}</div></article>
        @empty
            <div class="empty-state"><span class="empty-symbol" aria-hidden="true">↗</span><h3><span lang="ar">مساحة للأخبار القادمة</span><span lang="en">Room for what comes next</span></h3><p lang="ar">لا توجد تحديثات منشورة حالياً. ستظهر أخبار المنصة هنا عند نشرها.</p><p lang="en">No updates have been published yet. Platform news will appear here when available.</p></div>
        @endforelse
    </div>
</section>
@endsection
