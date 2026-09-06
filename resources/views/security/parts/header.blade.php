<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز الأمان</b></nav>
        <h2>🛡️ مركز الأمان</h2>
        <div class="sub">ما المكسور الآن، ومن يصلحه، ومن هو داخلٌ في هذه اللحظة</div>
    </div>
    <form method="POST" action="{{ route('security.lockdown') }}"
          data-confirm="{{ $lockdown ? 'رفع قفل الطوارئ وإعادة الوصول للجميع؟' : 'تفعيل قفل الطوارئ؟ كل الجلسات غير المالكة ستُعلّق فوراً!' }}">
        @csrf
        <button class="btn {{ $lockdown ? '' : 'ghost' }} sm" type="submit"
                style="{{ $lockdown ? 'background:var(--bad);border-color:var(--bad);color:#fff' : 'color:var(--bad);border-color:var(--bad)' }}">
            {{ $lockdown ? '🔓 رفع قفل الطوارئ (مفعّل الآن!)' : '🔒 قفل طوارئ' }}
        </button>
    </form>
</div>

@if ($lockdown)<div class="flash bad" style="position:static;margin-bottom:12px">⚠️ قفل الطوارئ مفعّل — لا يستطيع أحد سوى المالكين الوصول للنظام الآن</div>@endif
