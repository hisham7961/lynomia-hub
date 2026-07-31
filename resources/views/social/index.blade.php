@extends('layouts.app')
@section('title', 'مركز السوشال ميديا')
@section('content')
@php
    $k = $st['kpi'];
    $num = fn ($v) => number_format((float) $v, ((float) $v == (int) $v) ? 0 : 1);
    $ico = ['Instagram' => '📸', 'TikTok' => '🎵', 'X (Twitter)' => '𝕏', 'Snapchat' => '👻', 'YouTube' => '▶️',
            'Facebook' => '👍', 'LinkedIn' => '💼', 'Telegram' => '✈️', 'قناة WhatsApp' => '💬'];
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>اللوحات</span><span aria-hidden="true">‹</span><b>مركز السوشال ميديا</b></nav>
        <h2>📣 مركز السوشال ميديا</h2>
        <div class="sub">النمو من السلسلة الزمنية، والتفاعل محسوباً لا مُدخَلاً — آخر {{ $days }} يوماً</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form class="filters" method="GET">
            <label class="vh" for="fd">المدى</label>
            <select class="inp" id="fd" name="d" onchange="this.form.submit()">
                @foreach ([7 => 'أسبوع', 30 => 'شهر', 90 => '٣ أشهر', 365 => 'سنة'] as $dv => $dl)
                    <option value="{{ $dv }}" @selected($days === $dv)>{{ $dl }}</option>
                @endforeach
            </select>
        </form>
        <a class="btn ghost sm" href="{{ route('m.index', 'social') }}">الحسابات ←</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'posts') }}">المنشورات ←</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">👥</span><b>{{ $num($k['followers']) }}</b>
        <span>إجمالي المتابعين · {{ $k['accounts'] }} حساباً</span></div>
    <div class="stat"><span class="ico">{{ $k['delta'] >= 0 ? '📈' : '📉' }}</span>
        <b class="{{ $k['delta'] < 0 ? 'txt-bad' : '' }}">{{ $k['delta'] >= 0 ? '+' : '' }}{{ $num($k['delta']) }}</b>
        <span>نمو {{ $days }} يوماً{{ $k['pct'] !== null ? ' · ' . ($k['pct'] >= 0 ? '+' : '') . $k['pct'] . '٪' : '' }}</span></div>
    <div class="stat"><span class="ico">💥</span><b>{{ $k['rate'] !== null ? $k['rate'] . '٪' : '—' }}</b>
        <span>متوسط معدّل التفاعل</span></div>
    <div class="stat"><span class="ico">📝</span><b>{{ $k['posts'] }}</b>
        <span>منشوراً خلال المدة · وصول {{ $num($k['reach']) }}</span></div>
    @if ($k['spend'] > 0)
        <div class="stat"><span class="ico">💸</span><b>{{ $num($k['spend']) }}</b><span>إنفاق إعلاني مسجَّل</span></div>
    @endif
    @if ($k['unlinked'] || $k['stalled'])
        <div class="stat"><span class="ico">🔌</span><b class="txt-bad">{{ $k['unlinked'] + $k['stalled'] }}</b>
            <span>حساباً بلا تغذيةٍ آلية حيّة</span></div>
    @endif
</div>

{{-- ═══ الحسابات ═══ --}}
<div class="card pad0">
    <h3 class="cardtitle" style="padding:12px 14px 0">👥 الحسابات ونموّها</h3>
    <div class="sub" style="padding:0 14px 8px">
        المتابعون من آخر نقطةٍ في السلسلة لا من حقلٍ يُدهس — والمنحنى لكل حساب.
    </div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الحساب</th><th>المتابعون</th><th>النمو</th><th>المنحنى</th>
            <th>نحو الهدف</th><th>التفاعل</th><th>التغذية الآلية</th></tr></thead>
        <tbody>
        @forelse ($st['accounts'] as $a)
            <tr>
                <td><a href="{{ route('m.show', ['social', $a['id']]) }}">
                        <b>{{ $ico[$a['platform']] ?? '🌐' }} {{ $a['handle'] }}</b></a>
                    <div class="sub">{{ $a['platform'] }} · {{ $a['published'] }} منشوراً</div></td>
                <td><b>{{ $num($a['followers']) }}</b></td>
                <td>
                    @if ($a['growth']['delta'] === null)
                        <span class="sub">لا سلسلة بعد</span>
                    @else
                        <span class="bdg {{ $a['growth']['tone'] ?: 'g' }}">
                            {{ $a['growth']['delta'] >= 0 ? '+' : '' }}{{ $num($a['growth']['delta']) }}
                            @if ($a['growth']['pct'] !== null)({{ $a['growth']['pct'] }}٪)@endif
                        </span>
                    @endif
                </td>
                <td>
                    @if (count($a['spark']) > 1)
                        <span style="display:inline-flex;align-items:flex-end;gap:2px;height:26px">
                            @foreach ($a['spark'] as $s)
                                <span title="{{ $s['at']->format('Y-m-d') }} — {{ $num($s['value']) }}"
                                      style="display:block;width:4px;border-radius:2px;background:var(--p);height:{{ max(8, $s['pct']) }}%"></span>
                            @endforeach
                        </span>
                    @else
                        <span class="sub">نقطة واحدة</span>
                    @endif
                </td>
                <td>
                    @if ($a['goal'] > 0)
                        @php $gp = min(100, (int) round($a['followers'] * 100 / $a['goal'])); @endphp
                        <div class="pbar sm" style="min-width:70px"><span style="width:{{ $gp }}%"></span></div>
                        <span class="sub">{{ $gp }}٪ من {{ $num($a['goal']) }}</span>
                    @else
                        <span class="sub">بلا هدف</span>
                    @endif
                </td>
                <td>{{ $a['rate'] !== null ? $a['rate'] . '٪' : '—' }}</td>
                <td><span class="bdg {{ $a['feed']['tone'] }}">{{ $a['feed']['label'] }}</span>
                    <div class="sub">{{ $a['feed']['at'] ? 'آخر قياس ' . $a['feed']['at']->diffForHumans() : 'لا قياس' }}</div></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty"><span class="big">📣</span>لا حسابات بعد —
                <a href="{{ route('m.create', 'social') }}">أضف أول حساب</a></td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

@if ($k['unlinked'] || $k['stalled'])
    <div class="card" style="border-inline-start:4px solid var(--wn, #d68910)">
        <h3 class="cardtitle">🔌 «كله أوتو» يبدأ بمعرفة ما ليس أوتو</h3>
        <div class="sub" style="line-height:2">
            @if ($k['unlinked'])<b>{{ $k['unlinked'] }}</b> حساباً بلا أي قياسٍ مسجَّل، @endif
            @if ($k['stalled'])<b>{{ $k['stalled'] }}</b> حساباً كان آلياً وتوقّف مصدره، @endif
            وأرقامها لا تتحدّث من نفسها. طريقان:
            <br>① <b>الربط الآلي</b> — دع n8n (أو أي مصدر) يدفع الأرقام إلى
            <span class="mono ltr">POST /api/v1/metrics</span>؛ التفصيل في
            <a href="{{ route('integrations.guide') }}">دليل الربط</a>.
            <br>② <b>لقطة الآن</b> — سجّل القيم الحالية نقطةً في السلسلة لتبدأ الذاكرة فوراً،
            وبعدها كل حفظٍ عادي يضيف نقطته تلقائياً.
        </div>
        @if (hub_can(auth()->user(), 'social', 'e'))
            <form method="POST" action="{{ route('social.snapshot') }}" style="margin-top:10px"
                  data-confirm="تسجيل القيم الحالية كنقطة قياس الآن؟">
                @csrf<button class="btn ghost sm">📸 التقط لقطةً الآن</button>
            </form>
        @endif
    </div>
@endif

@if ($canPosts)
<div class="kids">
    <div class="card kid">
        <h3>🏆 أعلى المنشورات تفاعلاً</h3>
        @if (count($st['top']))
            <table class="mini">
                @foreach ($st['top'] as $p)
                    <tr><td><a href="{{ route('m.show', ['posts', $p['id']]) }}">{{ \Illuminate\Support\Str::limit($p['title'], 46) }}</a>
                            <div class="sub">{{ $p['type'] ?: '—' }}{{ $p['account'] ? ' · ' . $p['account'] : '' }}
                                {{ $p['pub_at'] ? ' · ' . $p['pub_at']->format('Y-m-d') : '' }}</div></td>
                        <td class="acts"><b>{{ $num($p['interactions']) }}</b>
                            <div class="sub">{{ $p['rate'] !== null ? $p['rate'] . '٪' : '—' }}</div></td></tr>
                @endforeach
            </table>
        @else
            <div class="sub">لا منشورات منشورة بعد.</div>
        @endif
    </div>

    <div class="card kid">
        <h3>🎬 أداء أنواع المحتوى <span class="sub">· أين يستحق الجهد</span></h3>
        @if (count($st['byType']))
            <table class="mini">
                @foreach ($st['byType'] as $t)
                    <tr><td>{{ $t['type'] }}<div class="sub">{{ $t['n'] }} منشوراً · {{ $num($t['inter']) }} تفاعلاً</div></td>
                        <td class="acts"><b>{{ $t['rate'] !== null ? round($t['rate'], 2) . '٪' : '—' }}</b></td></tr>
                @endforeach
            </table>
            <div class="sub" style="margin-top:6px">مرتّبةً بمعدّل التفاعل — لا بعدد المنشورات.</div>
        @else
            <div class="sub">لا بيانات كافية.</div>
        @endif
    </div>

    <div class="card kid">
        <h3>⏰ أفضل وقت للنشر</h3>
        @if (count($st['byHour']))
            <table class="mini">
                @foreach ($st['byHour'] as $h)
                    <tr><td>الساعة {{ sprintf('%02d:00', $h['hour']) }}<div class="sub">{{ $h['n'] }} منشوراً</div></td>
                        <td class="acts"><b>{{ $num($h['inter']) }}</b><div class="sub">متوسط التفاعل</div></td></tr>
                @endforeach
            </table>
            <div class="sub" style="margin-top:6px">مشتقٌّ من ساعة النشر الفعلية وتفاعلها — لا قاعدةً عامة.</div>
        @else
            <div class="sub">لا منشورات بتواريخ نشرٍ مسجّلة.</div>
        @endif
    </div>
</div>
@endif
@endsection
