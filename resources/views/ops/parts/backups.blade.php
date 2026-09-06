    {{-- (WP-2.7 · spec §3.13) النسخ الاحتياطي: آخرُ نسخةٍ بحجمها وتشفيرها وعمرها +
         أحدثُ الملفات + تاريخُ التشغيل **بنجاحه وفشله** من سلسلة ('ops','backup','run')
         — الملفّاتُ وحدها لا تحكي الفشل: نسخةٌ فاشلة لا تترك ملفاً. ولا استعادةَ من
         الويب عمداً — الاستعادةُ خطوةٌ مقصودة من كتيّب التشغيل. يتوقع $bk/$bkAt
         من hub_screen المختومة (٦٠ ثانية). --}}
    <div class="card kid" id="backups">
        <h3>💾 النسخ الاحتياطي</h3>
        @if (! empty($bk['latest']))
            <div><b><bdi class="mono ltr">{{ $bk['latest']['name'] }}</bdi></b>
                <span class="bdg {{ $bk['latest']['enc'] ? 'ok' : 'g' }}">{{ $bk['latest']['enc'] ? '🔐 مشفّرة' : 'غير مشفّرة' }}</span>
                <div class="sub">{{ $fmt($bk['latest']['size']) }}
                    · منذ {{ now()->diffForHumans(\Illuminate\Support\Carbon::createFromTimestamp($bk['latest']['mtime']), true) }}
                    · {{ $bk['total'] }} ملفاً على القرص</div></div>
            @if (count($bk['files']) > 1)
                <details style="margin-top:6px">
                    <summary class="sub" style="cursor:pointer">أحدث {{ count($bk['files']) }} ملفات — الأحدث أولاً</summary>
                    <div class="tblwrap"><table class="mini">
                        <thead><tr><th scope="col">الملف</th><th scope="col">الحجم</th><th scope="col">التشفير</th><th scope="col">العمر</th></tr></thead>
                        <tbody>
                        @foreach ($bk['files'] as $bf)
                            <tr><td><bdi class="mono ltr" style="font-size:11px;word-break:break-all">{{ $bf['name'] }}</bdi></td>
                                <td class="mono">{{ $fmt($bf['size']) }}</td>
                                <td>{{ $bf['enc'] ? '🔐' : '—' }}</td>
                                <td class="sub" style="white-space:nowrap">{{ \Illuminate\Support\Carbon::createFromTimestamp($bf['mtime'])->diffForHumans() }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </details>
            @endif
        @else
            <div class="sub">لا نسخ بعد — خذ أول نسخة الآن بالزر:</div>
        @endif

        {{-- تاريخُ التشغيل من metric_points: أحدثُ ١٥ تشغيلاً صراحةً — نجاحاً وفشلاً --}}
        @if (count($bk['runs'] ?? []))
            <div class="sub" style="margin-top:8px"><b>تاريخ التشغيل</b> — أحدث {{ count($bk['runs']) }} تشغيلاً@if ($bk['failed_runs'])، منها <span class="bdg bad">{{ $bk['failed_runs'] }} فاشلاً</span>@endif:</div>
            <div class="tblwrap"><table class="mini">
                <thead><tr><th scope="col">متى</th><th scope="col">النتيجة</th><th scope="col">المدّة</th></tr></thead>
                <tbody>
                @foreach ($bk['runs'] as $bkR)
                    <tr><td class="sub" style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($bkR['at'])->diffForHumans() }}</td>
                        <td>@if ($bkR['result'] === 'ok')<span class="bdg ok">✓ نجحت</span>@else<span class="bdg bad">✗ {{ $bkR['result'] }}</span>@endif
                            @if ($bkR['note'])<div class="sub">{{ $bkR['note'] }}</div>@endif</td>
                        <td class="mono sub">{{ $bkR['ms'] !== null ? $bkR['ms'] . 'ms' : '—' }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @else
            <div class="sub" style="margin-top:8px">لا تاريخَ تشغيلٍ مسجَّلاً بعد — سيبدأ القياس من الآن مع أول نبضة نسخ.</div>
        @endif

        <form method="POST" action="{{ route('ops.backup') }}" style="margin-top:10px"
              data-confirm="أخذ نسخة احتياطية كاملة الآن؟ (قد تستغرق لحظات)">
            @csrf<button class="btn ghost xs">💾 نسخة احتياطية الآن</button>
        </form>
        <div class="sub" style="margin-top:6px">⛔ لا استعادةَ من هذه الشاشة عمداً — الاستعادةُ خطوةٌ مقصودة
            تُنفَّذ بخطوات <a href="{{ route('ops.runbooks') }}">📘 كتيّب التشغيل</a>.</div>
        @include('partials.cc.freshness', ['at' => $bkAt, 'ttl' => 60])
    </div>
