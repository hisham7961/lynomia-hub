    <div class="card kid">
        <b>🔎 فاحصا السلامة</b>
        <div class="sub" style="margin-bottom:8px">
            <b>سلسلة التدقيق</b> تُثبت أن لا أحدَ عبث بالسجل — وتُفحص أسبوعياً آلياً،
            وهنا تُفحص فوراً. و<b>انحراف المخطّط</b> يكشف عموداً ينتظره الكودُ ولا وجودَ له
            في قاعدتك — علّةُ «Unknown column» قبل أن تُطفئ شاشة.
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <form method="POST" action="{{ route('ops.verifyaudit') }}">
                @csrf<button class="btn ghost xs">🔗 افحص سلسلة التدقيق</button>
            </form>
            <form method="POST" action="{{ route('ops.schemacheck') }}">
                @csrf<button class="btn ghost xs">🧬 افحص المخطّط</button>
            </form>
        </div>
        @php
            /* (WP-5.5 · §1.6) آخرُ فحصٍ كامل من تاريخ audit_verifications — قراءةُ
               صفٍّ واحد؛ الفحصُ الكامل نفسُه لا يجري في طلبٍ أبداً (الزرُّ أو المجدول) */
            $lastVerify = null;
            try {
                if (hub_has_col('audit_verifications', 'id')) {
                    $lastVerify = \Illuminate\Support\Facades\DB::table('audit_verifications')
                        ->orderByDesc('started_at')->orderByDesc('id')->first();
                }
            } catch (\Throwable $e) {
            }
        @endphp
        <div class="sub" style="margin-top:8px">
            @if ($lastVerify)
                آخر فحص كامل:
                <span class="bdg {{ ['ok' => 'ok', 'warn' => 'wn', 'fail' => 'bad'][$lastVerify->result] ?? '' }}">{{ ['ok' => 'سليمة', 'warn' => 'سليمة بملاحظات', 'fail' => 'فشل'][$lastVerify->result] ?? $lastVerify->result }}</span>
                {{ \Illuminate\Support\Carbon::parse($lastVerify->started_at)->diffForHumans() }}
                · {{ $lastVerify->mode === 'manual' ? 'يدويّ' : 'آليّ' }}
                · {{ number_format((int) $lastVerify->checked_rows) }} قيد
                · <span class="mono ltr">{{ (int) $lastVerify->duration_ms }}ms</span>@if ($lastVerify->first_bad_id) · أول قيد متأثر <span class="mono ltr">#{{ $lastVerify->first_bad_id }}</span>@endif
            @else
                لم يُشغَّل الفحص الكامل بعد — يجري أسبوعياً آلياً، أو الآن بالزرّ أعلاه؛ وكلُّ تشغيلٍ يؤرَّخ.
            @endif
        </div>
    </div>
