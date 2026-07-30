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
        <h3>ودجات اللوحة</h3>
        <table class="mini">
            @forelse ($board->widgets as $w)
                <tr>
                    <td>{{ \App\Support\WidgetRegistry::labels()[$w->widget_key] ?? $w->widget_key }}</td>
                    <td style="width:1%">
                        <form method="POST" action="{{ route('boards.widget.remove', [$board->id, $w->id]) }}">
                            @csrf @method('DELETE')<button class="btn ghost xs">إزالة</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="empty">لا ودجات بعد — أضف من القائمة المجاورة</td></tr>
            @endforelse
        </table>
    </div>

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
