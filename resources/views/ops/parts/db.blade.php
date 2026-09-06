    {{-- (WP-2.7 · spec §3.14) ترحيلات القاعدة: العدّادُ **الواحد** المتّفق
         (hub_pending_migrations — الذي يقرؤه /healthz ومكوّنُ «مخطّط القاعدة» نفسُه)
         + الدفعةُ الحالية وآخرُ تطبيقٍ وآخرُ تشغيلٍ من قيد التدقيق. يتوقع $mig/$migAt
         من hub_screen المختومة (٦٠ ثانية — وfresh=1 بعد الترحيل يُبطلها فوراً). --}}
    <div class="card kid" id="mig">
        <h3>🛢️ ترحيلات القاعدة</h3>
        @if (! ($mig['ok'] ?? false))
            <div class="sub">⛔ غير متاح — تعذّرت قراءة جدول الترحيلات نفسِه (قاعدةٌ لم تُهيّأ بعد؟).</div>
        @else
            @if ($mig['pending_n'] > 0)
                <div class="sub" style="margin-bottom:8px">
                    <b class="txt-bad"><span id="mig-pending">{{ $mig['pending_n'] }}</span> ترحيلاً معلقاً</b> — الكود يسبق القاعدة،
                    وهذه الفجوة سبب أخطاء «عمود غير معروف». اضغط للتطبيق:
                </div>
                <ul class="sub mono ltr" style="margin:0 0 10px;padding-inline-start:18px;max-height:120px;overflow:auto">
                    @foreach ($mig['pending'] as $m)<li>{{ $m }}</li>@endforeach
                </ul>
                <form method="POST" action="{{ route('ops.migrate') }}"
                      data-confirm="تشغيل الترحيلات المعلقة على قاعدة البيانات الآن؟ (الترحيلات إضافية غير مدمرة)">
                    @csrf<button class="btn xs">🛢️ تشغيل الترحيلات الآن</button>
                </form>
            @else
                <div class="sub">✅ القاعدة مطابقة للكود — لا ترحيلات معلقة (العدّاد <span id="mig-pending">{{ $mig['pending_n'] }}</span>).</div>
            @endif
            <div class="tblwrap"><table class="mini" style="margin-top:8px">
                <tr><td>الدفعة الحالية</td>
                    <td class="acts mono"><b id="mig-batch">{{ (int) $mig['batch'] }}</b>
                        <span class="sub">· فيها {{ $mig['batch_n'] }} ترحيلاً</span></td></tr>
                <tr><td>آخر ترحيل مطبَّق</td>
                    <td class="acts"><bdi class="mono ltr" style="font-size:11px;word-break:break-all">{{ $mig['last_applied'] ?: '—' }}</bdi></td></tr>
                <tr><td>آخر تشغيل من المركز</td>
                    <td class="acts sub">{{ $mig['last_run_at'] ? \Illuminate\Support\Carbon::parse($mig['last_run_at'])->diffForHumans() : 'لم يُشغَّل من المركز بعد' }}</td></tr>
            </table></div>
        @endif
        @if (session('migrate_out'))
            <pre class="mono ltr" style="margin-top:10px;font-size:11px;max-height:180px;overflow:auto;white-space:pre-wrap">{{ session('migrate_out') }}</pre>
        @endif
        @include('partials.cc.freshness', ['at' => $migAt, 'ttl' => 60])
    </div>
