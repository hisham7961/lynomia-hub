@extends('layouts.app')
@section('title', 'تحرير قالب — ' . $tpl->name)
@section('content')
<div class="modhero" style="--mh:#7C6FB0">
    <span class="mhico">📑</span>
    <div>
        <div class="sub">قوالب التوقيع الإلكتروني</div>
        <h2>{{ $tpl->name }} <span class="bdg g">الإصدار {{ $tpl->version ?: 1 }}</span>
            @if ($tpl->archived_at)<span class="bdg wn">مؤرشف</span>@endif</h2>
    </div>
    <div class="spacer"></div>
    <div class="rh-acts">
        <a class="btn ghost sm" href="{{ route('esign.index') }}">← مركز التوقيع</a>
        <form method="POST" action="{{ route('esign.tpl.archive', $tpl->id) }}" class="inline">@csrf
            <button class="btn ghost sm" type="submit">{{ $tpl->archived_at ? '♻️ استعادة' : '🗄️ أرشفة' }}</button>
        </form>
    </div>
</div>

<div class="bgrid" style="display:grid;grid-template-columns:1fr 300px;gap:16px;align-items:start">
    <form method="POST" action="{{ route('esign.tpl.update', $tpl->id) }}" id="tplform" class="card" style="padding:18px">
        @csrf @method('PUT')
        <div class="fg" style="margin-bottom:12px">
            <div class="fld"><label>اسم القالب <b class="req">*</b></label>
                <input class="inp" name="name" required maxlength="160" value="{{ $tpl->name }}"></div>
            <div class="fld"><label>التصنيف</label>
                <input class="inp" name="kind" maxlength="80" value="{{ $tpl->kind }}"></div>
            <div class="fld fw"><label>وصف مختصر</label>
                <input class="inp" name="descr" maxlength="300" value="{{ $tpl->descr }}"></div>
        </div>

        {{-- محرر البنود المهيكلة: عنوان + نص لكل بند، إعادة ترتيب، وإدراج متغيرات برقاقة --}}
        <div id="blocks" data-init='@json($tpl->blocksOr())'></div>
        <input type="hidden" name="blocks" id="blocksjson">
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
            <button class="btn ghost sm" type="button" id="addblock">＋ بند جديد</button>
            <span class="spacer"></span>
            <button class="btn ghost sm" type="button" id="tplpreview">👁 معاينة</button>
            <button class="btn p sm" type="submit">💾 حفظ (يلقط إصداراً عند تغير النص)</button>
        </div>
        <div class="fld" style="margin-top:10px"><label>ملاحظة الإصدار <span class="sub">(اختياري — تُحفظ مع اللقطة)</span></label>
            <input class="inp" name="note" maxlength="300" placeholder="ما الذي تغيّر ولماذا؟"></div>
    </form>

    <div style="display:flex;flex-direction:column;gap:14px">
        {{-- مكتبة المتغيرات: انقر تُدرَج عند المؤشر — لا أسماء تقنية تُحفظ غيباً --}}
        <div class="card" style="padding:14px">
            <b>🧩 المتغيرات</b>
            <input class="inp" id="varsearch" placeholder="⌕ ابحث…" style="margin:8px 0;width:100%">
            <div id="varlist" style="max-height:44vh;overflow:auto">
                @foreach ($registry as $group => $vars)
                    <div class="sub" style="margin:8px 0 4px;font-weight:700">{{ $group }}</div>
                    @foreach ($vars as $v)
                        <button type="button" class="chip varchip" data-k="{{ $v['k'] }}"
                                title="{{ ($v['desc'] ?? '') }} — مثال: {{ $v['ex'] ?? '' }}{{ !empty($v['req']) ? ' (إلزامي)' : '' }}"
                                style="margin:2px;cursor:pointer">{{ $v['label'] }}{!! !empty($v['req']) ? ' <b class="req">*</b>' : '' !!}</button>
                    @endforeach
                @endforeach
            </div>
        </div>
        {{-- تاريخ الإصدارات --}}
        <div class="card" style="padding:14px">
            <b>🕐 الإصدارات</b>
            @forelse ($versions as $ver)
                <div style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--ln);font-size:12.5px">
                    <span class="bdg g">{{ $ver->version }}</span>
                    <span style="flex:1">{{ $ver->note ?: '—' }}</span>
                    <span class="sub">{{ $ver->created_at->format('m-d H:i') }}</span>
                </div>
            @empty
                <div class="sub" style="margin-top:6px">لا لقطات بعد — أول تعديل جوهري يلقط الإصدار الحالي.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="modal" id="pvmodal" hidden>
    <div class="modalbox" style="max-width:760px">
        <button class="mclose" type="button" onclick="document.getElementById('pvmodal').hidden=true" aria-label="إغلاق">✕</button>
        <div id="pvbody"></div>
    </div>
</div>
@endsection
