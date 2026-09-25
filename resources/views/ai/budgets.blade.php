@extends('layouts.app')
@section('title', 'ميزانيّات الذكاء')
@section('content')

{{-- ═══ الميزانيّاتُ — حجزٌ قبل الإنفاقِ لا حسابٌ بعدَه ═══

     ميزانيّةٌ تُفحَص **بعد** الإنفاقِ ليست ميزانيّة. ولذلك يُحجَز التقديرُ قبل
     النداء، ويُلتزَم بالفعليِّ بعدَه، ويُفرَج عمّا لم يقع. --}}

@php($fmt = fn ($micro) => $micro === null ? '—' : number_format($micro / \App\Support\Ai\Governance\AiCost::SCALE, 4))

<div class="hero">
    <div>
        <h2>🧾 ميزانيّاتُ الذكاءِ وحصصُه</h2>
        <div class="sub">
            <b>حجزٌ قبل النداء، والتزامٌ بعدَه.</b> والقرارُ يقع في القاعدةِ بجملةٍ
            شرطيّةٍ واحدة — <b>فطلبان متزامنان عند الحافّةِ لا يمرّان معاً</b>.
        </div>
    </div>
</div>

@include('ai._sections')

<div class="cards">
    <div class="stat"><span class="ico bdg">💵</span><b>المالُ عددٌ صحيح</b>
        <span>يُخزَّن بالميكرو (١٫٠٠ = ١٬٠٠٠٬٠٠٠) — <b>فعدّادٌ متسابقٌ لا يحتمل خطأَ تقريبٍ عائم</b>.</span></div>
    <div class="stat"><span class="ico bdg">❓</span><b>مجهولٌ ≠ صفر</b>
        <span>كلفةٌ لا تُقدَّر <b>لا تمرّ تحت سقفِ مالٍ مفروض</b>، وعمليّةٌ بلا كلفةٍ
        معروفةٍ تُعَدّ عدّاً مستقلّاً لا تُطوى في الصفر.</span></div>
    <div class="stat"><span class="ico bdg">👁️</span><b>مراقبةٌ قبل الفرض</b>
        <span>ميزانيّةٌ بلا فرضٍ تقيس ولا تمنع — <b>مسارُ هجرةٍ آمنٌ</b> قبل أن يُقطَع عملٌ برقمٍ خُمِّن.</span></div>
    <div class="stat"><span class="ico bdg">⏳</span><b>الحجزُ ينقضي</b>
        <span>مسارٌ مات بين الحجزِ والالتزامِ يُفرَج عنه بعد
        {{ \App\Support\Ai\Governance\AiBudgets::RESERVATION_TTL_MIN }} دقيقة — <b>فلا تُخنَق ميزانيّةٌ بمالٍ لم يُنفَق</b>.</span></div>
</div>

@if ($budgets->isEmpty())
    <div class="card empty">
        <b>لا ميزانيّةَ معرَّفة.</b>
        <div class="sub">ولا سقفَ إذاً — الإنفاقُ محكومٌ بحرّاسِ الطلبِ الواحدِ وحدَها
        (سقفُ رموزِ المخرَج وعددُ النداءات). أضِف ميزانيّةً أدناه.</div>
    </div>
@else
<div class="card">
    <h3>الميزانيّات <span class="mut">({{ $budgets->count() }})</span></h3>
    <table class="tbl">
        <thead><tr>
            <th>الميزانيّة</th><th>النطاق</th><th>الفترة</th>
            <th>السقف</th><th>المُنفَق</th><th>المحجوز</th><th>المتبقّي</th>
            <th>بلا كلفةٍ مُقاسة</th><th>الحال</th><th></th>
        </tr></thead>
        <tbody>
        @foreach ($budgets as $b)
            @php($s = $status[(string) $b->id] ?? null)
            <tr>
                <td><b>{{ $b->label }}</b><br><span class="mut mono ltr">{{ $b->key }}</span>
                    @if ($b->notes)<div class="sub mut">{{ $b->notes }}</div>@endif</td>
                <td><span class="bdg">{{ $b->scope_type }}</span>
                    @if ($b->scope_id)<div class="mut mono ltr">{{ $b->scope_id }}</div>@endif</td>
                <td>{{ $b->period }}<div class="mut mono ltr">{{ $s['period_key'] ?? '—' }}</div></td>
                <td>
                    @if ($b->limit_micro !== null)
                        <span class="mono ltr">{{ $fmt($b->limit_micro) }}</span> {{ $b->currency }}
                    @endif
                    @if ($b->limit_requests)<div class="mut">≤ {{ $b->limit_requests }} طلب</div>@endif
                    @if ($b->limit_tokens)<div class="mut">≤ {{ $b->limit_tokens }} رمزاً</div>@endif
                    @if ($b->limit_micro === null && ! $b->limit_requests && ! $b->limit_tokens)
                        <span class="mut">—</span>
                    @endif
                </td>
                <td><span class="mono ltr">{{ $fmt($s['spent_micro'] ?? null) }}</span>
                    <div class="mut">{{ $s['requests'] ?? 0 }} طلباً · {{ $s['tokens'] ?? 0 }} رمزاً</div></td>
                <td><span class="mono ltr">{{ $fmt($s['reserved_micro'] ?? null) }}</span></td>
                <td>
                    @if (($s['available_micro'] ?? null) === null)
                        <span class="mut">بلا سقفِ مال</span>
                    @else
                        <span class="mono ltr">{{ $fmt($s['available_micro']) }}</span>
                        @if (($s['pct'] ?? null) !== null)
                            <div class="mut">{{ $s['pct'] }}٪ مُستهلَك</div>
                        @endif
                    @endif
                </td>
                <td>
                    @if (($s['unknown_cost_events'] ?? 0) > 0)
                        <span class="bdg wn">{{ $s['unknown_cost_events'] }}</span>
                    @else
                        <span class="mut">—</span>
                    @endif
                </td>
                <td>
                    {!! $b->enabled ? '<span class="bdg ok">فاعلة</span>' : '<span class="bdg">مُعطَّلة</span>' !!}
                    @if (! $b->enforce)<div><span class="bdg wn">تراقب ولا تمنع</span></div>@endif
                </td>
                <td>
                    @if ($canManage)
                    <div class="row" style="gap:6px;flex-wrap:wrap">
                        <form method="POST" action="{{ route('ai.budgets.toggle', $b) }}">@csrf
                            <button class="btn sm">{{ $b->enabled ? '⏸️ تعطيل' : '▶️ تفعيل' }}</button></form>
                        <form method="POST" action="{{ route('ai.budgets.destroy', $b) }}"
                              onsubmit="return confirm('إزالةُ الميزانيّة «{{ $b->label }}»؟')">
                            @csrf @method('DELETE')
                            <button class="btn sm danger">🗑️ إزالة</button></form>
                    </div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if ($canManage)
<div class="card">
    <h3>ميزانيّةٌ جديدة</h3>
    <div class="sub mut">
        <b>ابدأ بـ«تراقب ولا تمنع»</b> واقرأ أسبوعاً قبل أن تفرض — فرقمٌ خُمِّن
        ثمّ فُرض يقطع عملاً قائماً بلا تفسير.
    </div>
    <form method="POST" action="{{ route('ai.budgets.store') }}">@csrf
        <div class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-start">
            <label style="flex:1;min-width:170px">المفتاح
                <input name="key" required pattern="[a-z0-9._-]+" class="mono ltr" placeholder="monthly-global"></label>
            <label style="flex:1;min-width:170px">الاسم المعروض
                <input name="label" required maxlength="191" placeholder="السقفُ الشهريُّ العامّ"></label>
            <label style="min-width:130px">النطاق
                <select name="scope_type">
                    @foreach (\App\Support\Ai\Governance\AiBudgets::SCOPES as $sc)
                        <option value="{{ $sc }}">{{ $sc }}</option>
                    @endforeach
                </select></label>
            <label style="flex:1;min-width:170px">معرّفُ النطاق
                <input name="scope_id" class="mono ltr" placeholder="يُترَك فارغاً مع «global»"></label>
            <label style="min-width:120px">الفترة
                <select name="period">
                    @foreach (\App\Support\Ai\Governance\AiBudgets::PERIODS as $pr)
                        <option value="{{ $pr }}" @selected($pr === 'monthly')>{{ $pr }}</option>
                    @endforeach
                </select></label>
        </div>
        <div class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-start;margin-top:8px">
            <label style="min-width:160px">سقفُ المال ({{ \App\Support\Ai\Governance\AiCost::CURRENCY }})
                <input name="limit_amount" type="number" step="0.0001" min="0" placeholder="بلا سقفِ مال"></label>
            <label style="min-width:150px">سقفُ الطلبات
                <input name="limit_requests" type="number" min="1" placeholder="بلا سقفِ عدد"></label>
            <label style="min-width:150px">سقفُ الرموز
                <input name="limit_tokens" type="number" min="1" placeholder="بلا سقفِ رموز"></label>
            <label class="row" style="gap:6px;align-items:center">
                <input type="hidden" name="enforce" value="0">
                <input type="checkbox" name="enforce" value="1"> افرضها (وإلّا فهي تراقب ولا تمنع)</label>
        </div>
        <label style="display:block;margin-top:8px">ملاحظة
            <input name="notes" maxlength="1000" placeholder="سقفٌ تجريبيٌّ للربع الأوّل"></label>
        <div style="margin-top:10px"><button class="btn">➕ أضِف الميزانيّة</button></div>
    </form>
</div>
@endif

@endsection
