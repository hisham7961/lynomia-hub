    {{-- (WP-2.7 · §25) مؤشرات الأخطاء: $errs تصل null حين يسقط جدول error_events —
         «غير متاح» أصدقُ من أصفارٍ تطمئن فوق جدولٍ غائب. مخبّأةٌ ٦٠ ثانية ($errsAt). --}}
    <div class="card kid">
        <h3>📉 مؤشرات ٧ أيام</h3>
        @if (is_null($errs))
            <div class="sub">⛔ غير متاح — تعذّرت قراءة سجل الأخطاء (جدولٌ غائب أو قاعدةٌ متعثّرة).</div>
        @else
            <div class="tblwrap"><table class="mini">
                <tr><td>تكرارات الأخطاء</td><td class="acts"><b>{{ $errs['week'] }}</b></td></tr>
                <tr><td>طلبات بطيئة (> ثانية)</td><td class="acts"><b>{{ $errs['slow'] }}</b></td></tr>
                <tr><td>أخطاء API</td><td class="acts"><b>{{ $errs['api'] }}</b></td></tr>
            </table></div>
            @include('partials.cc.freshness', ['at' => $errsAt, 'ttl' => 60])
        @endif
        <form method="POST" action="{{ route('ops.testerror') }}" style="margin-top:10px" data-confirm="توليد خطأ تجريبي للتحقق من الالتقاط؟">
            @csrf<button class="btn ghost xs">🧪 توليد خطأ تجريبي</button>
        </form>
    </div>
