    <div class="card kid">
        <h3>🧹 كاش النظام</h3>
        <div class="sub" style="margin-bottom:8px">
            بعد رفع نسخة جديدة أو تغيير الإعدادات وما زال يظهر القديم — امسح الكاش:
            الإعدادات والمسارات والقوالب المجمّعة وكاش البيانات، دفعةً واحدة.
        </div>
        <form method="POST" action="{{ route('ops.clearcache') }}"
              data-confirm="مسح كاش النظام كله الآن؟ (آمن — يُعاد بناؤه تلقائياً)">
            @csrf<button class="btn ghost xs">🧹 مسح الكاش الآن</button>
        </form>
    </div>
