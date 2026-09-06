{{-- (WP-2.7 · spec §3.16) ارتباطُ الإصدار: لكل نشرٍ (deployments.deployed_at) نافذتان
     متساويتان قبل/بعد — تكراراتُ error_events ونسبةُ أخطاء دلاء HTTP، بحدٍّ أدنى
     للعيّنة قبل أي حكم. يتوقع $rel/$relAt من hub_screen المختومة (٦٠ ثانية). --}}
<div class="card" id="releases">
    <h3 class="cardtitle">🚀 الإصدارات وأثرها
        <span class="sub">· أحدث ٥ نشرات — نافذة {{ number_format($rel['hours'] ?? 24) }} ساعة قبل/بعد،
            والحدّ الأدنى للحكم {{ number_format($rel['min_n'] ?? 100) }} طلباً في كل نافذة</span></h3>
    @if (! ($rel['ok'] ?? false))
        <div class="sub">⛔ غير متاح — تعذّرت قراءة سجلّ النشر.</div>
    @elseif (! count($rel['rows']))
        @include('partials.empty', ['text' => 'لا نشراتٍ مسجَّلةً بعد — تُكتشف آلياً عند تغيّر النسخة (لقطة التشغيل كل ٥ دقائق) أو تُسجَّل يدوياً من وحدة النشر', 'icon' => '🚀'])
    @else
        <div class="tblwrap">
            <table class="mini">
                <thead><tr>
                    <th scope="col">النسخة</th>
                    <th scope="col">متى</th>
                    <th scope="col">تكرارات الأخطاء قبل ← بعد</th>
                    <th scope="col">نسبة أخطاء HTTP قبل ← بعد</th>
                    <th scope="col">الحكم</th>
                </tr></thead>
                <tbody>
                @foreach ($rel['rows'] as $rr)
                    <tr>
                        <td><bdi class="mono ltr">{{ $rr['ver'] }}</bdi>
                            @if ($rr['env'])<div class="sub">{{ $rr['env'] }}@if ($rr['migrations']) · ترحيلات {{ $rr['migrations'] }}@endif</div>@endif</td>
                        <td class="sub" style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($rr['at'])->diffForHumans() }}</td>
                        <td class="mono">{{ $rr['before']['errs'] === null ? '—' : number_format($rr['before']['errs']) }}
                            ← {{ $rr['after']['errs'] === null ? '—' : number_format($rr['after']['errs']) }}</td>
                        <td class="mono">{{ $rr['before']['rate'] === null ? '—' : $rr['before']['rate'] . '٪' }}
                            ← {{ $rr['after']['rate'] === null ? '—' : $rr['after']['rate'] . '٪' }}
                            <div class="sub">العيّنة {{ number_format($rr['before']['n']) }} / {{ number_format($rr['after']['n']) }}</div></td>
                        <td>@if (! $rr['enough'])
                                <span class="sub">لا توجد بيانات تاريخية كافية</span>
                            @elseif (($rr['cmp']['pct'] ?? null) !== null && $rr['cmp']['pct'] > 0)
                                <span class="bdg bad">⚠️ ‎+{{ $rr['cmp']['pct'] }}٪ أخطاء بعد النشر</span>
                            @elseif (($rr['cmp']['pct'] ?? null) === null && (float) ($rr['after']['rate'] ?? 0) > (float) ($rr['before']['rate'] ?? 0))
                                <span class="bdg wn">ارتفاعٌ من الصفر — {{ $rr['after']['rate'] }}٪</span>
                            @else
                                <span class="bdg ok">✓ لا تدهور</span>
                            @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @include('partials.cc.freshness', ['at' => $relAt, 'ttl' => 60])
</div>
