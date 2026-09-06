<h3 class="secttl">🔎 من يستهلك؟</h3>
<div class="kids">
    <div class="card kid">
        <h3>💽 من يستهلك القرص</h3>
        @if (count($consumers['disk']))
            {{-- (WP-2.7 · critic #14) .tblwrap لكل جداول مركز التشغيل --}}
            <div class="tblwrap"><table class="mini">
                @foreach ($consumers['disk'] as $d)
                    <tr><td>{{ $d['label'] }}<div class="sub mono ltr">{{ $d['path'] }} · {{ number_format($d['files']) }} ملفاً</div></td>
                        <td class="acts mono"><b>{{ $fmt($d['size']) }}</b></td></tr>
                @endforeach
            </table></div>
            <div class="sub" style="margin-top:6px">النسخ القديمة وسجلات النظام أكثر ما يملأ القرص صامتاً.</div>
        @else
            <div class="sub">لا مجلدات تخزين بعد.</div>
        @endif
    </div>

    <div class="card kid">
        <h3>📚 من يستهلك القاعدة <span class="sub">· أثقل {{ count($consumers['tables']) }} جدولاً صراحةً</span></h3>
        <div class="tblwrap"><table class="mini">
            @foreach ($consumers['tables'] as $t)
                <tr><td>{{ $t['label'] }}@if (! empty($t['platform']))<span class="bdg g">منصة</span>@endif
                        <div class="sub mono ltr">{{ $t['table'] }}</div></td>
                    <td class="acts mono"><b>{{ number_format($t['n']) }}</b> صف</td>
                    @if (! empty($t['size']))<td class="acts mono sub">{{ $fmt($t['size']) }}</td>@endif</tr>
            @endforeach
        </table></div>
    </div>

    <div class="card kid">
        <h3>🐌 من يستهلك الوقت <span class="sub">· أبطأ {{ count($consumers['slow']) }} مساراً (٧ أيام)</span></h3>
        @if (count($consumers['slow']))
            <div class="tblwrap"><table class="mini">
                @foreach ($consumers['slow'] as $s)
                    <tr><td class="mono ltr" style="word-break:break-all;font-size:11px">{{ \Illuminate\Support\Str::limit($s['url'] ?: '—', 64) }}
                            <div class="sub">{{ \Illuminate\Support\Str::limit((string) $s['sample'], 60) }}</div></td>
                        <td class="acts"><span class="bdg wn">{{ $s['hits'] }}×</span></td></tr>
                @endforeach
            </table></div>
            <div class="sub" style="margin-top:6px"><a href="{{ route('errors.index', ['k' => 'slow']) }}">كل الطلبات البطيئة ←</a></div>
        @else
            <div class="sub">✅ لا طلبات تجاوزت الثانية خلال ٧ أيام.</div>
        @endif
    </div>

    <div class="card kid">
        <h3>🔥 أكثر الصفحات استدعاءً <span class="sub">· أعلى {{ count($consumers['busy']) }} صفحات (٧ أيام)</span></h3>
        @if (count($consumers['busy']))
            <div class="tblwrap"><table class="mini">
                @foreach ($consumers['busy'] as $b)
                    <tr><td class="mono ltr" style="word-break:break-all;font-size:11px">{{ \Illuminate\Support\Str::limit($b['path'], 56) }}</td>
                        <td class="acts mono sub">{{ $b['users'] }} مستخدم</td>
                        <td class="acts"><b>{{ number_format($b['hits']) }}</b></td></tr>
                @endforeach
            </table></div>
            <div class="sub" style="margin-top:6px">هنا يذهب الحمل فعلاً — أي تحسينٍ لهذه الصفحات يُحسّ.</div>
        @else
            <div class="sub">لا زيارات مسجلة خلال ٧ أيام.</div>
        @endif
    </div>
</div>
