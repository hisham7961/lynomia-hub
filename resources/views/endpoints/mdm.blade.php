@extends('layouts.app')
@section('title', 'تكامل MDM')
@section('content')
{{-- (مسارُ التصحيح §8/§9) تكاملُ MDM (Intune/Jamf) — الوصلةُ تحمل الإعدادَ لا
     السرَّ (السرُّ في VaultSecret مشفَّراً). كلُّ المزوّدات اليومَ **رصدٌ فقط**:
     جسرُ الفرض الحيّ مؤجَّلٌ — لا حجبَ منفذٍ يُزعَم (C15). للمالك وحدَه. --}}
<div class="hero">
    <div>
        <h2>🛡️ تكامل MDM</h2>
        <div class="sub">
            الفرضُ الحقيقيُّ لسياساتِ USB يجري عبر مزوّدِ MDM (Intune/Jamf) على مستأجرك.
            الوصلةُ هنا تحمل الإعدادَ والمستأجرَ ومرجعَ السرّ — لا السرَّ نفسَه.
        </div>
    </div>
    <div><a class="btn ghost sm" href="{{ route('endpoints.index') }}">→ الأسطول</a></div>
</div>

{{-- شريطُ الصدق — يبقى ما بقي جسرُ الفرض الحيّ مؤجَّلاً (لا فرضَ زائفاً أبداً) --}}
<div class="flash wn">
    ⚠️ <b>رصدٌ فقط — لا حجبَ منفذٍ يُزعَم.</b>
    جسرُ الفرض الحيّ (Microsoft Graph / Jamf API) مؤجَّلٌ صراحةً؛ فحتى مع اعتماداتٍ مكتملةٍ
    ووصلةٍ مُفعَّلة، يبقى الوضعُ الفعليُّ رصداً وإبلاغاً — الوكيلُ يرصد أحداثَ USB، ولا منفذَ يُحجَب.
</div>

@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif
@if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

<div class="card">
    <h3 class="cardtitle">🔗 وصلاتُ التكامل</h3>
    @if ($connections->isEmpty())
        <div class="sub">لا وصلةَ تكاملٍ بعد — الأسطولُ يعمل بوضع «رصدٌ فقط» الافتراضيّ الصادق.</div>
    @else
        @php
            $providerLabel = ['intune' => 'Microsoft Intune', 'jamf' => 'Jamf Pro'];
        @endphp
        <table class="mini">
            <thead><tr><th>المزوّد</th><th>الشركة</th><th>المستأجر</th><th>سرّ؟</th><th>الحالة</th><th>آخر صحّة</th><th>آخر مزامنة</th><th></th></tr></thead>
            <tbody>
            @foreach ($connections as $c)
                <tr>
                    <td><b>{{ $providerLabel[$c->provider] ?? $c->provider }}</b></td>
                    <td class="sub">{{ $c->company?->name_ar ?? 'عامّة (كل الشركات)' }}</td>
                    <td class="mono" dir="ltr">{{ $c->external_tenant ?? '—' }}</td>
                    {{-- حضورٌ لا قيمة: لا يُعرَض السرُّ أبداً --}}
                    <td>{{ $c->secret_id ? '✔︎ في الخزنة' : '—' }}</td>
                    <td>
                        @if ($c->enabled)
                            <span class="bdg g">مُفعَّلة</span> <span class="bdg wn">رصدٌ فقط</span>
                        @else
                            <span class="bdg wn">مطفأة</span>
                        @endif
                    </td>
                    <td class="sub">{{ $c->last_health_status ?? '—' }}@if ($c->last_health_at)<div class="sub">{{ $c->last_health_at->format('m-d H:i') }}</div>@endif</td>
                    <td class="sub">{{ $c->last_sync_status ?? '—' }}@if ($c->last_sync_at)<div class="sub">{{ $c->last_sync_at->format('m-d H:i') }}</div>@endif</td>
                    <td class="acts">
                        <form method="post" action="{{ route('endpoints.mdm.toggle', $c->id) }}" style="display:inline">@csrf<button class="btn sm">{{ $c->enabled ? 'إطفاء' : 'تفعيل' }}</button></form>
                        <form method="post" action="{{ route('endpoints.mdm.health', $c->id) }}" style="display:inline">@csrf<button class="btn sm ghost">فحص صحّة</button></form>
                        <form method="post" action="{{ route('endpoints.mdm.sync', $c->id) }}" style="display:inline">@csrf<button class="btn sm ghost">مزامنة</button></form>
                        <form method="post" action="{{ route('endpoints.mdm.delete', $c->id) }}" style="display:inline"
                              onsubmit="return confirm('سحبُ وصلةِ {{ $c->provider }}؟')">@csrf<button class="btn sm danger">سحب</button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="card">
    <h3 class="cardtitle">➕ وصلةٌ جديدة</h3>
    <div class="sub" style="margin-bottom:8px">
        السرُّ (إن أُدخل) يُخزَّن في الخزنة مشفَّراً وتحمل الوصلةُ مرجعَه فقط — لا يُكتب خاماً.
        يتطلب تصعيدَ هوية (step-up).
    </div>
    <form method="post" action="{{ route('endpoints.mdm.store') }}"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        @csrf
        <select name="provider" class="inp" required>
            <option value="intune">Microsoft Intune</option>
            <option value="jamf">Jamf Pro</option>
        </select>
        <select name="company_id" class="inp">
            <option value="">— عامّة (كل الشركات) —</option>
            @foreach ($companies as $co)<option value="{{ $co->id }}">{{ $co->name_ar }}</option>@endforeach
        </select>
        <input class="inp" name="external_tenant" placeholder="المستأجر / النسخة (ليس سرّاً)" dir="ltr" style="width:220px">
        <input class="inp" name="secret" type="password" placeholder="السرّ (يُشفَّر في الخزنة)" dir="ltr" style="width:220px" autocomplete="new-password">
        <label class="sub"><input type="checkbox" name="enabled" value="1"> تفعيلٌ فوريّ</label>
        <button class="btn sm">إنشاء</button>
    </form>
</div>
@endsection
