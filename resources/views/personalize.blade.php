@extends('layouts.app')
@section('title', 'التخصيص')
@section('content')
<div class="hero">
    <div>
        <h2>🎛️ التخصيص</h2>
        <div class="sub">
            النظام على ذوقك أنت: شاشة بدايتك، قائمتك، لوحتك — تخصيصك يخصك وحدك ولا يغيّر شيئاً لزملائك.
            <b>الإخفاء تنظيمٌ للعرض لا صلاحية</b> — ما تخفيه يبقى متاحاً بالرابط المباشر والبحث.
        </div>
    </div>
    <form method="POST" action="{{ route('prefs.reset') }}" data-confirm="مسح كل تخصيصك والعودة للافتراضي؟">
        @csrf<button class="btn ghost sm">↺ الضبط الافتراضي</button>
    </form>
</div>

@php
    $hidden = (array) hub_pref('nav.hidden', []);
    $hiddenTop = (array) hub_pref('nav.hidden_top', []);
    $names = (array) hub_pref('nav.names', []);
    $order = (array) hub_pref('nav.order', []);
    $dashHidden = (array) hub_pref('dash.hidden', []);
    $home = (string) hub_pref('home', '');
@endphp

<form method="POST" action="{{ route('prefs.update') }}">
    @csrf

    <div class="card">
        <h3>🏁 شاشة البداية</h3>
        <div class="sub" style="margin-bottom:8px">أين تهبط بعد تسجيل الدخول؟</div>
        <label class="vh" for="pf-home">شاشة البداية</label>
        <select class="inp" id="pf-home" name="home" style="max-width:340px">
            <option value="dashboard" @selected($home === '' || $home === 'dashboard')>🏠 لوحة التحكم (الافتراضي)</option>
            <optgroup label="الصفحات">
                @foreach ($top as $l)
                    <option value="{{ $l['key'] }}" @selected($home === $l['key'])>{{ $l['label'] }}</option>
                @endforeach
            </optgroup>
            <optgroup label="الوحدات">
                @foreach ($groups as $g)
                    @foreach ($g['items'] as $it)
                        <option value="m:{{ $it['key'] }}" @selected($home === 'm:' . $it['key'])>{{ $g['icon'] }} {{ $it['label'] }}</option>
                    @endforeach
                @endforeach
            </optgroup>
        </select>
    </div>

    <div class="card" style="margin-top:12px">
        <h3>📌 روابط القائمة العلوية</h3>
        <div class="sub" style="margin-bottom:8px">أزل ما لا تستخدمه — يختفي من قائمتك أنت فقط.</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px">
            @foreach ($top as $l)
                <label class="chk"><input type="checkbox" name="hidden_top[]" value="{{ $l['key'] }}" @checked(in_array($l['key'], $hiddenTop, true))> إخفاء {{ $l['label'] }}</label>
            @endforeach
        </div>
    </div>

    <div class="card" style="margin-top:12px">
        <h3>🗂️ مجموعات الوحدات</h3>
        <div class="sub" style="margin-bottom:8px">
            رتّب المجموعات بالأرقام (الأصغر أولاً)، وأخفِ ما لا يلزمك، وسمِّ الوحدات بلغتك —
            «العملاء» تصير «الزبائن» إن كانت هذه لغة شغلك.
        </div>
        @foreach ($groups as $gi => $g)
            <details style="border:1px solid var(--brd);border-radius:10px;padding:8px 12px;margin-bottom:8px" {{ $gi === 0 ? 'open' : '' }}>
                <summary style="cursor:pointer;font-weight:700">
                    {{ $g['icon'] }} {{ $g['g'] }}
                    <span class="sub">({{ count($g['items']) }} وحدة)</span>
                </summary>
                <div style="margin:8px 0">
                    <label class="lbl" for="ord-{{ $gi }}">ترتيب المجموعة</label>
                    <input class="inp ltr" id="ord-{{ $gi }}" type="number" name="order[{{ $g['g'] }}]" min="0" max="99" style="max-width:90px"
                           value="{{ ($oi = array_search($g['g'], $order, true)) === false ? '' : $oi }}" placeholder="—">
                </div>
                <table class="tbl">
                    <thead><tr><th>الوحدة</th><th style="width:110px">إخفاء</th><th style="width:220px">تسمية بديلة</th></tr></thead>
                    <tbody>
                    @foreach ($g['items'] as $it)
                        <tr>
                            <td>{{ $it['label'] }}</td>
                            <td><label class="chk"><input type="checkbox" name="hidden[]" value="{{ $it['key'] }}" @checked(in_array($it['key'], $hidden, true))> <span class="vh">إخفاء {{ $it['label'] }}</span></label></td>
                            <td><label class="vh" for="nm-{{ $it['key'] }}">تسمية بديلة لـ{{ $it['label'] }}</label>
                                <input class="inp" id="nm-{{ $it['key'] }}" name="names[{{ $it['key'] }}]" maxlength="40"
                                       value="{{ $names[$it['key']] ?? '' }}" placeholder="{{ $it['label'] }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </details>
        @endforeach
    </div>

    <div class="card" style="margin-top:12px">
        <h3>🔕 كتم الإشعارات</h3>
        <div class="sub" style="margin-bottom:8px">أوقف أنواع الإشعارات التي لا تريدها — لن تصلك أصلاً. (الموافقات والإسناد والرسائل تبقى دائماً.)</div>
        @php $muted = (array) hub_pref('mute', []); @endphp
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px">
            @foreach (\App\Models\HubNotification::MUTEABLE as $mk => $ml)
                <label class="chk"><input type="checkbox" name="mute[]" value="{{ $mk }}" @checked(in_array($mk, $muted, true))> كتم: {{ $ml }}</label>
            @endforeach
        </div>
    </div>

    <div class="card" style="margin-top:12px">
        <h3>🏠 بطاقات لوحة التحكم</h3>
        <div class="sub" style="margin-bottom:8px">أخفِ البطاقات التي لا تعنيك — تعود من هنا متى شئت.</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px">
            @foreach ($cards as $ck => $cl)
                <label class="chk"><input type="checkbox" name="dash_hidden[]" value="{{ $ck }}" @checked(in_array($ck, $dashHidden, true))> إخفاء {{ $cl }}</label>
            @endforeach
        </div>
    </div>

    <div class="toolbar" style="margin-top:12px">
        <button class="btn p">💾 حفظ التخصيص</button>
    </div>
</form>
@endsection
