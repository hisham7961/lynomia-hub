{{-- (WP-2.7 · spec §3.1) الترويسة الواحدة: pagehead + بطاقات cc/kpis — الحالةُ العامة
     **ومنذ متى** (من سلسلة ops/health/rank التي تكتبها لقطة hub:ops-snapshot)،
     والمكوّناتُ الساقطة بأسمائها، والنسخةُ والبيئة (يعيدهما نموذجُ الصحّة سلفاً
     وكانا لا يُعرضان)، والعدّادُ الواحد للترحيلات المعلّقة — وسطرُ الطزاجة
     (cc/freshness) لخبيئة «منذ متى» البالغة ٦٠ ثانية. --}}
@component('partials.pagehead', ['icon' => '🖥️', 'title' => 'مركز مراقبة وتشغيل النظام', 'crumb' => 'النظام',
    'sub' => 'الفحص الخارجي: GET /healthz — يصلح لمراقبات Uptime'])
    <a class="btn ghost sm" href="{{ route('ops.runbooks') }}">📘 كتيّبات التشغيل</a>
    <a class="btn ghost sm" href="{{ route('errors.index') }}">🐞 مركز الأخطاء ←</a>
@endcomponent

@php
    $H = \App\Support\Health::class;
    $hdrBad = collect($health['components'])->filter(fn ($c) => $c['status'] !== $H::HEALTHY);
    $hdrDown = $hdrBad->contains(fn ($c) => $c['status'] === $H::UNAVAILABLE);
    // «منذ متى» من السلسلة وحدها — بلا سلسلةٍ بعدُ لا مدّةَ مُختلَقة، وحالةٌ لم
    // تلحقها لقطةٌ بعدُ (تغيّرت قبل دقائق) تُقال كذلك بصدق
    $hdrSince = ! empty($hdr['since'])
        ? 'الحالة هكذا ' . \Illuminate\Support\Carbon::parse($hdr['since'])->diffForHumans()
        : (! empty($hdr['tracked']) ? 'تغيّرت بعد آخر لقطة — اللقطة كل ٥ دقائق' : 'سيبدأ القياس من الآن');
@endphp
<div id="ops-hdr">
    @include('partials.cc.kpis', ['items' => [
        ['label' => 'الحالة العامة', 'value' => $H::LABELS[$health['status']],
         'tone' => $H::TONE[$health['status']], 'sub' => $hdrSince, 'url' => '#health',
         'hint' => 'الحالة نفسُها التي يقرؤها /healthz — و«منذ متى» من سلسلة لقطات التشغيل'],
        ['label' => 'مكوّنات غير سليمة', 'value' => $hdrBad->count(),
         'tone' => $hdrBad->isEmpty() ? 'ok' : ($hdrDown ? 'bad' : 'wn'),
         'sub' => $hdrBad->isEmpty() ? 'كل المكوّنات سليمة'
             : $hdrBad->map(fn ($c) => $c['label'])->take(4)->implode('، ') . ($hdrBad->count() > 4 ? '…' : ''),
         'url' => '#health'],
        ['label' => 'النسخة والبيئة', 'value' => 'v' . $health['version'],
         'sub' => 'البيئة: ' . ((($health['components']['config']['data']['env'] ?? null) ?: config('app.env'))
             . ' · PHP ' . PHP_VERSION),
         'url' => '#ops-env'],
        ['label' => 'ترحيلات معلّقة', 'value' => (string) ($mig['pending_n'] ?? '—'),
         'tone' => ($mig['pending_n'] ?? 0) > 0 ? 'bad' : 'ok',
         'sub' => 'العدّاد نفسُه الذي يقرؤه /healthz', 'url' => '#mig'],
    ]])
    @include('partials.cc.freshness', ['at' => $hdrAt, 'ttl' => 60])
</div>
<!-- ops-hdr-end -->
