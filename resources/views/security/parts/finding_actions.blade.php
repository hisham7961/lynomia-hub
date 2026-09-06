{{-- (WP-4.1) أزرارُ فعل النتيجة — تُعرض للمالك وحدَه (الحارسُ في المتحكّم أيضاً).
     الإقرارُ يوثّق «رأيتُها وأعمل عليها» ولا يُغلق؛ والإغلاقُ اليدويّ صادقٌ مع
     نفسه: إن بقي الشرطُ أعادت اللقطةُ اليومية فتحَ النتيجة. كلاهما قيدُ تدقيق. --}}
@if ($f->status === 'open')
    <form method="POST" action="{{ route('security.finding.ack', $f->id) }}" style="display:inline">
        @csrf<button class="btn ghost xs" title="إقرارٌ مسجَّل في التدقيق — النتيجة تبقى مرصودة">👁️ إقرار</button>
    </form>
@endif
@if ($f->status !== 'resolved')
    <form method="POST" action="{{ route('security.finding.resolve', $f->id) }}" style="display:inline"
          data-confirm="إغلاق النتيجة؟ إن بقي شرطُها ستُعيد اللقطةُ اليومية فتحَها.">
        @csrf<button class="btn ghost xs">✅ إغلاق</button>
    </form>
@endif
