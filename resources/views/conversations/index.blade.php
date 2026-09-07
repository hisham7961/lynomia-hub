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
        <h3>قنواتي <span class="bdg g">{{ $channels->count() }}</span></h3>
        @forelse ($channels as $ch)
            <a href="{{ route('conversations.show', $ch->id) }}" class="row" style="display:flex;gap:8px;align-items:center;
                    padding:10px 6px;border-top:1px solid var(--brd);text-decoration:none">
                <span class="ava sm">#</span>
                <b style="flex:1;min-width:0">{{ $ch->title ?: 'قناةٌ بلا اسم' }}</b>
                @if (in_array($ch->audience, ['client', 'both'], true))
                    <span class="bdg wn" title="قناةٌ يبلغها العميلُ عبر بوابته">👥 جمهورُ العميل</span>
                @else
                    <span class="bdg" title="قناةٌ داخليّة">🔒 داخليّة</span>
                @endif
            </a>
        @empty
            <div class="sub" style="padding:10px 0">لا قنواتٍ بعد — أنشئ أولَ قناة من الجانب.</div>
        @endforelse
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

            <button class="btn p" type="submit" style="margin-top:10px">إنشاء</button>
        </form>
    </div>
</div>

@endsection
