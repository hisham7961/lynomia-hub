{{-- خريطةُ الانكشاف: من يطاله اختراقُ حسابٍ واحد — بعلاقاتٍ فعلية لا رقمٍ أسود --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🗺️ خريطة الانكشاف — نطاقُ الأثر</h3>
        <div class="crow" style="gap:6px">
            <span class="bdg {{ $exposureSummary['high'] ? 'bad' : 'ok' }}">{{ $exposureSummary['exposed'] }} حسابٌ منكشف</span>
            <span class="bdg wn">{{ $exposureSummary['owners'] }} مالك</span>
            <span class="bdg {{ $exposureSummary['no2fa'] ? 'bad' : 'ok' }}">{{ $exposureSummary['no2fa'] }} بلا تحقّقٍ ثنائي</span>
            <span class="bdg">{{ $exposureSummary['live'] }} حيٌّ الآن</span>
        </div>
    </div>
    <div class="sub" style="margin:4px 0 10px">الحساباتُ عالية الامتياز مرتَّبةٌ بالأخطر أولاً — لو سُرق أحدُها، ماذا يطال؟ الدرجةُ مفسَّرةُ العوامل.</div>
    @if (empty($exposure))
        <div class="sub">لا حساباتٍ عالية الامتياز خارج المالك — انكشافٌ ضيّق.</div>
    @else
    <div class="tblwrap">
        <table class="tbl">
            <thead><tr><th>الحساب</th><th>الدور والنطاق</th><th>الرايات الحسّاسة</th><th>حيّ/أجهزة</th><th>الانكشاف</th></tr></thead>
            <tbody>
            @foreach ($exposure as $x)
                <tr>
                    <td>
                        <b>{{ $x['name'] }}</b>{!! $x['is_owner'] ? ' <span class="bdg bad">مالك</span>' : '' !!}
                        @if (! $x['twofa'])<span class="bdg wn" title="بلا تحقّقٍ بخطوتين">بلا 2FA</span>@endif
                        @if ($x['locked'])<span class="bdg">مقفل</span>@endif
                        <div class="sub" style="font-size:11px">{{ $x['email'] }}</div>
                    </td>
                    <td>{{ $x['role'] }}<div class="sub" style="font-size:11px">{{ $x['scope_label'] }}</div></td>
                    <td>
                        @forelse ($x['flag_labels'] as $fl)<span class="bdg wn" style="margin:1px">{{ $fl }}</span>@empty<span class="sub">—</span>@endforelse
                    </td>
                    <td>{{ $x['live'] }} / {{ $x['devices'] }}</td>
                    <td>
                        <span class="bdg {{ $x['band'] }}">{{ $x['score'] }}</span>
                        <div class="sub" style="font-size:11px;line-height:1.7">{{ implode(' · ', $x['factors']) }}</div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
