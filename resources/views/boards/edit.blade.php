@extends('layouts.app')
@section('title', 'تعديل ' . $board->name)
@section('content')
<div class="hero"><div><h2>🧩 {{ $board->name }}</h2>
    <div class="sub">أضف الودجات وأزلها — <a href="{{ route('dashboard', ['d' => $board->id]) }}">اعرض اللوحة</a></div></div></div>

<div class="card">
    <form method="POST" action="{{ route('boards.update', $board->id) }}" class="row">
        @csrf @method('PUT')
        <div class="fld fw"><label>الاسم <span class="req">*</span></label>
            <input class="inp" name="name" required maxlength="80" value="{{ old('name', $board->name) }}"></div>
        <label class="chk"><input type="checkbox" name="is_default" value="1" @checked($board->is_default)> افتراضية</label>
        @if (hub_is_owner(auth()->user()))
            <label class="chk"><input type="checkbox" name="shared" value="1" @checked($board->shared)> منشورة للجميع</label>
        @endif
        <button class="btn">حفظ</button>
    </form>
</div>

<div class="kids" style="margin-top:12px">
    <div class="card kid">
        <h3>ودجات اللوحة <span class="sub" style="font-weight:400">اسحب لإعادة الترتيب</span></h3>
        <form method="POST" action="{{ route('boards.layout', $board->id) }}" id="layoutform">
            @csrf @method('PUT')
            <ul class="wlist" id="wlist">
                @foreach ($board->widgets as $w)
                    <li class="witem" draggable="true" data-id="{{ $w->id }}">
                        <input type="hidden" name="order[]" value="{{ $w->id }}">
                        <span class="grip" aria-hidden="true">⠿</span>
                        <span class="wname">{{ \App\Support\WidgetRegistry::labels()[$w->widget_key] ?? $w->widget_key }}</span>
                        <label class="sub">العرض
                            <select class="inp xs" name="w[{{ $w->id }}]">
                                @foreach ([3 => 'ربع', 4 => 'ثلث', 6 => 'نصف', 8 => 'ثلثان', 12 => 'كامل'] as $v => $t)
                                    <option value="{{ $v }}" @selected((int) $w->w === $v)>{{ $t }}</option>
                                @endforeach
                            </select></label>
                        <label class="sub">الطول
                            <select class="inp xs" name="h[{{ $w->id }}]">
                                @foreach ([1 => 'قصير', 2 => 'عادي', 3 => 'طويل', 4 => 'أطول'] as $v => $t)
                                    <option value="{{ $v }}" @selected((int) $w->h === $v)>{{ $t }}</option>
                                @endforeach
                            </select></label>
                        <span class="wmove">
                            <button type="button" class="btn ghost xs" data-move="up" aria-label="أعلى">▲</button>
                            <button type="button" class="btn ghost xs" data-move="down" aria-label="أسفل">▼</button>
                        </span>
                        {{-- النموذج خارج نموذج التخطيط، والزر يرتبط به بالسمة form --}}
                        <button class="btn ghost xs" form="rm-{{ $w->id }}"
                                onclick="return confirm('إزالة هذه الودجة من اللوحة؟')">إزالة</button>
                    </li>
                @endforeach
            </ul>
            @if ($board->widgets->count())
                <button class="btn" style="margin-top:10px">حفظ التخطيط</button>
            @else
                <div class="empty">لا ودجات بعد — أضف من القائمة المجاورة</div>
            @endif
        </form>
    </div>

    @foreach ($board->widgets as $w)
        <form method="POST" action="{{ route('boards.widget.remove', [$board->id, $w->id]) }}"
              id="rm-{{ $w->id }}" hidden>@csrf @method('DELETE')</form>
    @endforeach

    <div class="card kid">
        <h3>ودجات متاحة</h3>
        <table class="mini">
            @forelse ($available as $key => $def)
                <tr>
                    <td>{{ $def['label'] }}</td>
                    <td style="width:1%">
                        <form method="POST" action="{{ route('boards.widget.add', $board->id) }}">
                            @csrf<input type="hidden" name="widget_key" value="{{ $key }}">
                            <button class="btn ghost xs">إضافة</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="empty">كل الودجات المتاحة لك مضافة</td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <form method="POST" action="{{ route('boards.destroy', $board->id) }}"
          onsubmit="return confirm('حذف اللوحة «{{ $board->name }}»؟')">
        @csrf @method('DELETE')<button class="btn ghost xs">🗑️ حذف اللوحة</button>
    </form>
</div>
@endsection
