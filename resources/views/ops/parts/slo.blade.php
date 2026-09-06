{{-- (WP-2.5 · spec §3.10/§26) أهدافُ مستوى الخدمة (SLO) وميزانيةُ الخطأ — يتوقّع $slo من
     OpsController::slo. مطفأٌ كلّياً ما لم تُضبط مفاتيح slo.*: لا عنوانَ ولا بطاقةَ ولا رقماً.
     الهدفُ الموسومُ «تقريبية» محسوبٌ من مدرَّجٍ لوغاريتمي بحدّ خطأ الحاوية ±7.5٪،
     وتاريخٌ لا يغطّي النافذةَ يطبع «لا توجد بيانات تاريخية كافية» بلا SLI ولا حكمِ امتثال. --}}
@if ($slo['on'] ?? false)
    @php /* تنسيقُ الأرقام: حتى خانتين عشريتين بلا أصفارٍ زائدة — 99.50 تُعرض 99.5 و2.00 تُعرض 2 */
         $sloFmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.'); @endphp
    <h3 class="secttl">🎯 أهداف مستوى الخدمة (SLO) <span class="sub">· نافذة {{ number_format($slo['days']) }} يوماً — الأهداف تُضبط من الإعدادات (slo.*)</span></h3>
    <div class="kids">
    @foreach ($slo['objectives'] as $so)
        <div class="card kid">
            <h3>{{ $so['icon'] }} {{ $so['name'] }}
                <span class="sub">· الهدف {{ $so['op'] }} {{ $sloFmt($so['target']) }}٪{{ $so['approx'] ? ' · تقريبية ±7.5٪' : '' }}</span></h3>
            <div class="sub">{{ $so['what'] }}</div>
            @if (! $so['enough'])
                <div class="sub" style="margin-top:6px">لا توجد بيانات تاريخية كافية — {{ $so['why'] }}.</div>
            @else
                <div style="margin-top:6px">
                    <span class="bdg {{ $so['ok'] ? 'ok' : 'bad' }}">{{ $so['ok'] ? '✓ ملتزم' : '⚠️ متجاوز' }}</span>
                    SLI الفعلي <b>{{ $sloFmt($so['sli']) }}٪</b> مقابل الهدف <b>{{ $sloFmt($so['target']) }}٪</b>
                    <span class="sub">(العيّنة {{ number_format($so['total']) }} {{ $so['unit'] }} · الفاشل {{ number_format($so['bad']) }})</span>
                </div>
                <div class="sub" style="margin-top:6px">ميزانية الخطأ: المسموح {{ $sloFmt($so['budget']['allowed']) }}
                    · المستهلك {{ number_format($so['budget']['consumed']) }} ({{ $so['budget']['consumed_pct'] === null ? 'نفدت — هدفُ 100٪ بلا ميزانية خطأ' : $sloFmt($so['budget']['consumed_pct']) . '٪' }})
                    · المتبقي {{ $sloFmt($so['budget']['remaining']) }} ({{ $sloFmt($so['budget']['remaining_pct']) }}٪)</div>
            @endif
        </div>
    @endforeach
    </div>
@endif
