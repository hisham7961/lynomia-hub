@extends('layouts.app')
@section('title', 'باني الحقول المخصصة')
@section('content')
<div class="hero">
    <div>
        <h2>🧩 باني الحقول المخصصة</h2>
        <div class="sub">أضف حقولاً لأي وحدة بلا كود — تظهر فوراً في نماذج الإضافة والتعديل وصفحة العرض، وتُستورد بمفاتيح cf_*</div>
    </div>
</div>

<div class="card" style="max-width:760px">
    <form method="GET" class="crow">
        <select class="inp" name="m" onchange="this.form.submit()" style="max-width:280px">
            <option value="">— اختر الوحدة —</option>
            @foreach (hub_modules() as $mk => $md)
                <option value="{{ $mk }}" @selected($module === $mk)>{{ $md['label'] }}</option>
            @endforeach
        </select>
        <noscript><button class="btn sm">عرض</button></noscript>
    </form>
</div>

@if ($module)
    <div class="card" style="max-width:760px">
        <h3>حقول «{{ hub_mod($module)['label'] }}» المخصصة <span class="bdg g">{{ count($fields) }}</span></h3>
        <table class="mini">
            @forelse ($fields as $f)
                <tr>
                    <td><b>{{ $f['label'] }}</b> @if (! empty($f['required']))<b class="req">*</b>@endif
                        <div class="sub">{{ $types[$f['type']] ?? $f['type'] }}{{ ($f['type'] ?? '') === 'ref' ? ' ← ' . ($refs[$f['ref']] ?? $f['ref']) : '' }}{{ ! empty($f['options']) ? ': ' . implode('، ', $f['options']) : '' }} · <span class="mono ltr">{{ $f['key'] }}</span></div></td>
                    <td class="acts">
                        <form method="POST" action="{{ route('fields.destroy', [$module, $f['key']]) }}" data-confirm="حذف الحقل من النماذج؟ القيم المخزنة تبقى.">
                            @csrf @method('DELETE')<button class="btn ghost xs" style="color:var(--bad)">حذف</button>
                        </form></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px">لا حقول مخصصة بعد لهذه الوحدة</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card" style="max-width:760px">
        <h3>＋ حقل جديد في «{{ hub_mod($module)['label'] }}»</h3>
        <form method="POST" action="{{ route('fields.store') }}">
            @csrf
            <input type="hidden" name="m" value="{{ $module }}">
            <div class="fg">
                <div class="fld"><label>اسم الحقل <b class="req">*</b></label>
                    <input class="inp" name="label" value="{{ old('label') }}" required maxlength="120" placeholder="مثال: رقم السجل التجاري">
                    @error('label')<span class="ferr">{{ $message }}</span>@enderror</div>
                <div class="fld"><label>النوع <b class="req">*</b></label>
                    <select class="inp" name="type">
                        @foreach ($types as $tk => $tl)<option value="{{ $tk }}" @selected(old('type') === $tk)>{{ $tl }}</option>@endforeach
                    </select></div>
                <div class="fld"><label>خيارات القائمة <span class="sub">(للقائمة فقط — مفصولة بفواصل)</span></label>
                    <input class="inp" name="options" value="{{ old('options') }}" placeholder="خيار أ، خيار ب، خيار ج">
                    @error('options')<span class="ferr">{{ $message }}</span>@enderror</div>
                <div class="fld"><label>نوع المرجع <span class="sub">(للمرجع فقط)</span></label>
                    <select class="inp" name="ref">
                        <option value=""></option>
                        @foreach ($refs as $rk => $rl)<option value="{{ $rk }}" @selected(old('ref') === $rk)>{{ $rl }}</option>@endforeach
                    </select>
                    @error('ref')<span class="ferr">{{ $message }}</span>@enderror</div>
                <div class="fld fw"><label class="chk"><input type="checkbox" name="required" value="1" @checked(old('required'))> حقل إلزامي</label></div>
            </div>
            <div class="formfoot"><button class="btn p">إضافة الحقل</button></div>
        </form>
    </div>
@endif
@endsection
