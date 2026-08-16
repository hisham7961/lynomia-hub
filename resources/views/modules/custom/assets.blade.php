{{-- مساحةُ عمل العهدة: هويّةٌ (كودٌ وملصق) ← حيازةٌ ← مواصفاتٌ ← تصاريح ← سجل --}}
@include('partials.custody_card')

{{-- بطاقة الإهلاك وتكلفة الملكية — تتوقع $row (الأصل). قسط ثابت من السعر والعمر --}}
@php
    $adPrice = (float) ($row->price ?? 0);
    $adLife = (int) ($row->life ?? 0);
    $adStart = $row->buy_date ? \Illuminate\Support\Carbon::parse($row->buy_date) : null;
    $adMonths = ($adStart && $adLife > 0) ? min($adLife * 12, (int) $adStart->diffInMonths(now())) : null;
    $adMonthly = ($adPrice > 0 && $adLife > 0) ? $adPrice / ($adLife * 12) : null;
    $adBook = ($adMonthly !== null && $adMonths !== null) ? max(0, $adPrice - $adMonthly * $adMonths) : null;
    $adMaint = (float) \App\Models\AssetMaintenance::whereNull('deleted_at')
        ->where('asset_id', $row->id)->sum('cost');
    $adCur = setting('app.currency', 'د.ك');
@endphp
@if ($adPrice > 0 || $adMaint > 0)
    <div class="card">
        <h3 class="cardtitle">🧮 الإهلاك وتكلفة الملكية</h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            @if ($adPrice > 0)
                <div><div class="sub">سعر الشراء</div><b class="mono">{{ number_format($adPrice, 2) }} {{ $adCur }}</b></div>
            @endif
            @if ($adBook !== null)
                <div><div class="sub">القيمة الدفترية (قسط ثابت)</div>
                    <b class="mono">{{ number_format($adBook, 2) }} {{ $adCur }}</b>
                    <div class="sub">إهلاك شهري {{ number_format($adMonthly, 2) }} × {{ $adMonths }} شهراً</div></div>
            @elseif ($adPrice > 0)
                <div class="sub" style="align-self:center">أدخل «تاريخ الشراء» و«العمر الافتراضي (سنوات)» لحساب الإهلاك.</div>
            @endif
            @if ($adMaint > 0)
                <div><div class="sub">إجمالي كلفة الصيانة</div>
                    <b class="mono">{{ number_format($adMaint, 2) }} {{ $adCur }}</b>
                    @if ($adPrice > 0 && $adMaint >= $adPrice * 0.5)
                        <div><span class="bdg wn">صيانته بلغت {{ (int) round($adMaint / $adPrice * 100) }}٪ من سعره — راجع جدوى الاستبدال</span></div>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
