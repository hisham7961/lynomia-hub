@extends('layouts.app')
@section('title', 'قواعد الحظر والسماح')
@section('content')
{{-- (Work OS · الطور I · WP-I.3 · §39/§42) شاشةُ قواعد IP — للمالك وحدَه (٤٠٤
     لغيره في المتحكّم). كلُّ فعلٍ خلف step-up وقيدِ تدقيقٍ بدلالة
     SECURITY_POLICY_CHANGED، وحمايةُ حبس آخرِ مالكٍ **خادميّة** لا واجهة. --}}
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span>@include('security.parts.root_crumb')<span aria-hidden="true">‹</span><b>قواعد الحظر والسماح</b></nav>
        <h2>⛔ قواعد الحظر والسماح</h2>
        <div class="sub">دفاعُ IP التكيّفي: حظرٌ وسماحٌ يدويٌّ وآليّ، مؤقتٌ ودائم — allow يفوز block دائماً، والمالكُ المصادَق لا يُحظر أبداً</div>
    </div>
    <a class="btn ghost sm" href="{{ route('security.ips') }}">🌐 ذكاء العناوين</a>
</div>

{{-- بطاقةُ الحالة الصادقة (عائلة C15): التطبيقُ السلطةُ القاطعة ويعمل بلا تبعية؛
     والحافّةُ لا تُدّعى — «غير مُهيّأ» حتى يكتمل اعتمادٌ حقيقيّ (EdgeDefense). --}}
<div class="card" style="margin-bottom:12px">
    <h3 style="margin:0 0 10px">🧭 حالةُ الفرض الصادقة</h3>
    <div class="crow" style="gap:10px;flex-wrap:wrap">
        <div>
            <span class="bdg ok">حظرُ التطبيق: {{ $edge['app']['label'] }}</span>
            <div class="sub" style="margin-top:4px">{{ $edge['app']['why'] }}</div>
        </div>
        <div>
            <span class="bdg {{ $edge['edge']['state'] === 'ready' ? 'ok' : 'wn' }}">حظرُ حافّة الشبكة: {{ $edge['edge']['label'] }}</span>
            <div class="sub" style="margin-top:4px">{{ $edge['edge']['why'] }}</div>
        </div>
    </div>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'حظرٌ حيّ', 'value' => $kpi['blocks'], 'tone' => $kpi['blocks'] ? 'wn' : 'ok'],
    ['label' => 'سماحٌ حيّ', 'value' => $kpi['allows']],
    ['label' => 'قواعد آليّة حيّة', 'value' => $kpi['auto'],
     'hint' => 'من سلّم التصعيد الآليّ (١٥ ← ٦٠ ← ١٤٤٠ دقيقة)'],
    ['label' => 'إصابات القواعد', 'value' => $kpi['hits'], 'hint' => 'كم طلباً صُدّ بقواعد الحظر'],
]])

<div class="card" style="margin-bottom:12px">
    <h3 style="margin:0 0 8px">➕ قاعدةٌ جديدة <span class="sub">(يتطلب تأكيدَ الهوية — ويُرفض خادمياً ما يحبس آخرَ مالك)</span></h3>
    <form method="POST" action="{{ route('security.blocks.store') }}" class="crow" style="gap:8px;flex-wrap:wrap;align-items:flex-end">
        @csrf
        <label style="min-width:220px">العنوان أو الشبكة (CIDR)
            <input class="inp mono ltr" name="ip" required maxlength="64"
                   placeholder="203.0.113.7 أو 192.168.1.0/24" value="{{ old('ip', $prefill) }}">
        </label>
        <label>النمط
            <select class="inp" name="mode">
                <option value="block" @selected(old('mode') !== 'allow')>⛔ حظر</option>
                <option value="allow" @selected(old('mode') === 'allow')>✅ سماح (يفوز أي حظر)</option>
            </select>
        </label>
        <label>المدة بالدقائق <span class="sub">(فارغة = دائمة)</span>
            <input class="inp mono ltr" name="minutes" type="number" min="1" max="527040" value="{{ old('minutes') }}">
        </label>
        <label style="min-width:220px">السبب
            <input class="inp" name="reason" maxlength="400" value="{{ old('reason') }}" placeholder="لماذا؟ — يُختم في التدقيق">
        </label>
        <button class="btn sm" type="submit">حفظ القاعدة</button>
    </form>
</div>

<div class="card">
    <h3 style="margin:0 0 8px">🧾 القواعد <span class="sub">(الأحدثُ قراراً أولاً — والمنتهي/الملغى يبقى أثراً ولا يُفرَض)</span></h3>
    <div class="tblwrap">
        <table class="tbl">
            <thead><tr>
                <th scope="col">القاعدة</th>
                <th scope="col">النمط</th>
                <th scope="col">المصدر</th>
                <th scope="col">الحالة</th>
                <th scope="col">الأجل</th>
                <th scope="col">إصابات</th>
                <th scope="col">السبب</th>
                <th scope="col">مَن</th>
                <th scope="col">إجراءات</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $rule)
                @php
                    $expired = $rule->expires_at !== null && ! $rule->expires_at->isFuture();
                    $liveRow = $rule->revoked_at === null && ! $expired;
                @endphp
                <tr>
                    <td><bdi class="mono ltr">{{ $rule->ip }}</bdi>@if ($rule->is_cidr) <span class="sub">CIDR</span>@endif</td>
                    <td><span class="bdg {{ $rule->mode === 'block' ? 'bad' : 'ok' }}">{{ $rule->mode === 'block' ? 'حظر' : 'سماح' }}</span></td>
                    <td class="sub">{{ $rule->origin === 'auto' ? 'آليّ (درجة ' . $rule->escalation_level . ')' : 'يدويّ' }}</td>
                    <td>
                        @if ($rule->revoked_at !== null)<span class="bdg">مُلغاة</span>
                        @elseif ($expired)<span class="bdg">منتهية</span>
                        @else<span class="bdg {{ $rule->mode === 'block' ? 'bad' : 'ok' }}">حيّة</span>@endif
                    </td>
                    <td class="sub">{{ $rule->expires_at === null ? 'دائمة' : $rule->expires_at->format('Y-m-d H:i') }}</td>
                    <td>{{ (int) $rule->hits ?: '—' }}</td>
                    <td class="sub">{{ $rule->reason ?: '—' }}</td>
                    <td class="sub">{{ $rule->author?->name ?? ($rule->origin === 'auto' ? 'النظام' : '—') }}</td>
                    <td>
                        @if ($liveRow)
                            <div class="crow" style="gap:6px;flex-wrap:wrap">
                                <form method="POST" action="{{ route('security.blocks.extend', $rule->id) }}" class="crow" style="gap:4px">
                                    @csrf
                                    <input class="inp mono ltr" name="minutes" type="number" min="1" max="527040"
                                           value="60" style="width:76px" aria-label="دقائق التمديد">
                                    <button class="btn ghost xs" type="submit">⏳ تمديد</button>
                                </form>
                                <form method="POST" action="{{ route('security.blocks.revoke', $rule->id) }}"
                                      data-confirm="إلغاء قاعدة {{ $rule->ip }}؟ يسري الأثرُ فوراً.">
                                    @csrf
                                    <button class="btn ghost xs" type="submit">♻️ إلغاء</button>
                                </form>
                            </div>
                        @else
                            <span class="sub">{{ $rule->revoked_at !== null ? 'أُلغيت ' . $rule->revoked_at->diffForHumans() . ($rule->revoker?->name ? ' — ' . $rule->revoker->name : '') : '—' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                @include('partials.empty', ['colspan' => 9, 'icon' => '🛡️',
                         'text' => 'لا قواعدَ بعد — الدفاعُ التكيّفي جاهزٌ وأولُ قاعدةٍ تسري فوراً'])
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
