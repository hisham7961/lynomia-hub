@extends('layouts.app')
@section('title', 'لوحاتي')
@section('content')
<div class="hero"><div><h2>🧩 لوحاتي</h2>
    <div class="sub">ابنِ لوحةً من الودجات المتاحة لك — واجعل إحداها افتراضية تُفتح معك.</div></div></div>

<div class="card">
    <h3>لوحة جديدة</h3>
    <form method="POST" action="{{ route('boards.store') }}" class="row">
        @csrf
        <div class="fld fw"><label>اسم اللوحة <span class="req">*</span></label>
            <input class="inp" name="name" required maxlength="80" placeholder="مثال: متابعة التشغيل اليومية"></div>
        <button class="btn">إنشاء</button>
    </form>
</div>

<div class="card" style="margin-top:12px">
    <table class="tbl">
        <thead><tr><th>اللوحة</th><th>الودجات</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @forelse ($boards as $b)
            <tr>
                <td><a href="{{ route('dashboard', ['d' => $b->id]) }}"><b>{{ $b->name }}</b></a>
                    @if ($b->role_id)<div class="sub">لوحة دور</div>@endif</td>
                <td>{{ $b->widgets_count }}</td>
                <td>
                    @if ($b->is_default)<span class="bdg ok">افتراضية</span>@endif
                    @if ($b->shared)<span class="bdg g">منشورة</span>@endif
                </td>
                <td class="acts">
                    @if ($b->editableBy(auth()->user()))
                        <a class="btn ghost xs" href="{{ route('boards.edit', $b->id) }}">تعديل</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty">لا لوحات بعد — أنشئ أولاها من الأعلى</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
