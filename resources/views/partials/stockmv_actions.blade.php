{{-- ترحيل/إلغاء حركة المخزون — يتوقع $row (الحركة) --}}
@php
    $mvMeta = (array) ($row->meta ?? []);
    $mvPosted = ! empty($mvMeta['posted_at']);
    $mvCanE = hub_can(auth()->user(), 'stockmv', 'e');
@endphp
@if ($mvCanE && $row->status !== 'ملغاة')
    <div class="card">
        <h3 class="cardtitle">📦 ترحيل الحركة</h3>
        @if ($mvPosted)
            <div class="sub" style="margin-bottom:8px">
                مُرحَّلة ✓ — تحرّك الرصيد بمقدار <b class="mono">{{ $mvMeta['delta'] ?? '' }}</b>.
                الإلغاء يعكس الأثر على رصيد الصنف.
            </div>
            <form method="POST" action="{{ route('stockmv.act', $row->id) }}" class="inline">
                @csrf<input type="hidden" name="do" value="cancel">
                <button class="btn ghost sm">↩️ ألغِ واعكس الأثر</button>
            </form>
        @else
            <div class="sub" style="margin-bottom:8px">
                الحركة مسودة — لا أثر لها على الرصيد حتى تُرحَّل.
                الترحيل يحرّك رصيد الصنف بإشارة النوع ويشتق حالته (نفد/منخفض/متاح).
            </div>
            <form method="POST" action="{{ route('stockmv.act', $row->id) }}" class="inline">
                @csrf<input type="hidden" name="do" value="confirm">
                <button class="btn">📦 رحّل الحركة</button>
            </form>
            <form method="POST" action="{{ route('stockmv.act', $row->id) }}" class="inline">
                @csrf<input type="hidden" name="do" value="cancel">
                <button class="btn ghost sm">إلغاء الحركة</button>
            </form>
        @endif
    </div>
@endif
