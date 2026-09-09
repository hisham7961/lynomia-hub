@extends('layouts.app')
@section('title', 'القنوات')
@section('content')

<div class="hero">
    <div>
        <h2>💬 القنوات</h2>
        <div class="sub">فضاءاتُ الفريق — كلُّ قناةٍ محادثةٌ لها أعضاؤها وجمهورُها. رسائلُها تُبنى على نفس محرّك التعليقات.</div>
    </div>
</div>

<div class="grid" style="grid-template-columns:2fr 1fr;gap:16px;align-items:start">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h3 style="margin:0">قنواتي <span class="bdg g">{{ $channels->count() }}</span></h3>
            <a class="btn ghost sm" href="{{ route('conversations.directory') }}">🧭 دليلُ القنوات</a>
        </div>
        @forelse ($channels as $ch)
            @php $fav = in_array($ch->id, $favIds ?? [], true); $u = ($unread ?? [])[$ch->id] ?? 0; @endphp
            <div class="row" style="display:flex;gap:6px;align-items:center;padding:8px 6px;border-top:1px solid var(--brd)">
                <form method="POST" action="{{ route('conversations.favorite', $ch->id) }}" class="inline">
                    @csrf<button class="lnkbtn" type="submit" title="{{ $fav ? 'إزالةٌ من المفضّلة' : 'إضافةٌ للمفضّلة' }}" style="font-size:15px">{{ $fav ? '⭐' : '☆' }}</button>
                </form>
                <a href="{{ route('conversations.show', $ch->id) }}" style="flex:1;min-width:0;display:flex;gap:8px;align-items:center;text-decoration:none;color:inherit">
                    <span class="ava sm">#</span>
                    <b style="flex:1;min-width:0">{{ $ch->title ?: 'قناةٌ بلا اسم' }}</b>
                    @if ($u > 0)<span class="bdg g" title="رسائلُ غير مقروءة">{{ $u > 99 ? '99+' : $u }}</span>@endif
                    @if (in_array($ch->audience, ['client', 'both'], true))
                        <span class="bdg wn" title="قناةٌ يبلغها العميلُ عبر بوابته">👥 العميل</span>
                    @else
                        <span class="bdg" title="قناةٌ داخليّة">🔒</span>
                    @endif
                </a>
            </div>
        @empty
            <div class="sub" style="padding:10px 0">لا قنواتٍ بعد — أنشئ قناةً أو تصفّح الدليل.</div>
        @endforelse

        @if (! empty($archived) && count($archived))
            <details style="margin-top:12px">
                <summary class="sub" style="cursor:pointer">🗄️ الأرشيف <span class="bdg">{{ count($archived) }}</span></summary>
                @foreach ($archived as $ch)
                    <div class="row" style="display:flex;gap:6px;align-items:center;padding:8px 6px;border-top:1px solid var(--brd);opacity:.75">
                        <span class="ava sm">#</span>
                        <b style="flex:1;min-width:0">{{ $ch->title ?: 'قناةٌ بلا اسم' }}</b>
                        @if (($myRoles[$ch->id] ?? null) === 'owner')
                            <form method="POST" action="{{ route('conversations.archive', $ch->id) }}" class="inline">
                                @csrf<button class="lnk sub" type="submit">↩ إعادة</button>
                            </form>
                        @else
                            <span class="sub">مؤرشفة</span>
                        @endif
                    </div>
                @endforeach
            </details>
        @endif
    </div>

    <div class="card">
        <h3>➕ قناةٌ جديدة</h3>
        <form method="POST" action="{{ route('conversations.store') }}">
            @csrf
            <label class="lbl">اسمُ القناة</label>
            <input class="inp" name="title" required maxlength="200" placeholder="مثال: هندسة المنصّة">
            @error('title')<div class="err">{{ $message }}</div>@enderror

            <label class="lbl" style="margin-top:8px">الجمهور</label>
            <select class="inp" name="audience">
                <option value="internal">🔒 داخليّة (الفريق فقط)</option>
                <option value="client">👥 يبلغها العميل</option>
            </select>
            <div class="sub" style="margin-top:4px">القناةُ داخليّةٌ ما لم تُشرَّع صراحةً — لا تسرّبَ بالسهو.</div>

            <label class="lbl" style="margin-top:8px">مدى الظهور</label>
            <select class="inp" name="visibility">
                <option value="private">🔒 مغلقة (بالدعوة فقط)</option>
                <option value="company">🏢 الشركة (تظهر في الدليل لأهل الشركة)</option>
                <option value="public">🌐 عامّة (تظهر في الدليل للجميع)</option>
            </select>
            <div class="sub" style="margin-top:4px">قنواتُ الدليل يُنضَمُّ إليها ذاتيّاً؛ المغلقةُ بالدعوة فقط.</div>

            <button class="btn p" type="submit" style="margin-top:10px">إنشاء</button>
        </form>
    </div>
</div>

@endsection
