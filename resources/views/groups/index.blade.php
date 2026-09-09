@extends('layouts.app')
@section('title', 'المجموعات')
@section('content')

<div class="hero">
    <div>
        <h2>👥 مجموعاتُ الرسائل</h2>
        <div class="sub">محادثاتٌ جماعيّةٌ داخليّةٌ خاصّة — لا تظهر في دليل القنوات، والإضافةُ تُنشئ مجموعةً جديدة حفظاً لخصوصيّة ما مضى.</div>
    </div>
</div>

@if (session('ok'))<div class="note ok">{{ session('ok') }}</div>@endif
@if ($errors->any())<div class="note wn">{{ $errors->first() }}</div>@endif

<div class="grid" style="grid-template-columns:2fr 1fr;gap:16px;align-items:start">
    <div class="card">
        <h3>مجموعاتي <span class="bdg g">{{ $groups->count() }}</span></h3>
        @forelse ($groups as $g)
            @php
                $u = ($unread ?? [])[$g->id] ?? 0;
                $names = ($memberNames[$g->id] ?? []);
                $label = $g->title ?: (count($names) ? collect($names)->take(4)->implode('، ') : 'مجموعة');
            @endphp
            <a href="{{ route('conversations.show', $g->id) }}" class="row"
               style="display:flex;gap:8px;align-items:center;padding:10px 6px;border-top:1px solid var(--brd);text-decoration:none;color:inherit">
                <span class="ava sm">👥</span>
                <b style="flex:1;min-width:0">{{ $label }}</b>
                <span class="sub">{{ $g->members_count }} أعضاء</span>
                @if ($u > 0)<span class="bdg g" title="رسائلُ غير مقروءة">{{ $u > 99 ? '99+' : $u }}</span>@endif
            </a>
        @empty
            <div class="sub" style="padding:10px 0">لا مجموعات بعد — أنشئ مجموعةً من الجانب.</div>
        @endforelse
    </div>

    <div class="card">
        <h3>➕ مجموعةٌ جديدة</h3>
        <form method="POST" action="{{ route('groups.store') }}">
            @csrf
            <label class="lbl">المشاركون</label>
            <select class="inp" name="participants[]" multiple size="6" required>
                @foreach ($candidates as $uid => $name)
                    <option value="{{ $uid }}">{{ $name }}</option>
                @endforeach
            </select>
            <div class="sub" style="margin-top:4px">زملاءُ الفريق الداخليُّون فقط — لا يُضاف عميل.</div>

            <label class="lbl" style="margin-top:8px">اسمٌ (اختياريّ)</label>
            <input class="inp" name="title" maxlength="200" placeholder="مثال: تنسيق الإطلاق">

            <label class="lbl" style="margin-top:8px">رسالةُ افتتاحٍ (اختياريّة)</label>
            <textarea class="inp" name="body" rows="2" maxlength="4000" placeholder="ابدأ الحديث…"></textarea>

            <button class="btn p" type="submit" style="margin-top:10px">إنشاءُ المجموعة</button>
        </form>
    </div>
</div>

@endsection
