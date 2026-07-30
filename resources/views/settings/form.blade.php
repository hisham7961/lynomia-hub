@extends('layouts.app')
@section('title', 'إعدادات النظام')
@section('content')
<form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" style="max-width:680px">
    @csrf
    @foreach ($groups as $gLabel => $items)
        <div class="card" style="margin-bottom:14px">
            <h3 style="margin-bottom:10px">{{ $gLabel }}</h3>
            <div class="fg">
                @foreach ($items as $key => [$label, $type])
                    @php $input = str_replace('.', '_', $key); $val = old($input, $values[$key]); @endphp
                    <div class="fld fw">
                        <label>{{ $label }}</label>
                        @if ($type === 'color')
                            <input type="color" name="{{ $input }}" value="{{ $val ?: '#0E7C66' }}" style="width:70px;height:38px;border:1px solid var(--ln);border-radius:9px;background:none;cursor:pointer">
                        @elseif ($type === 'onoff')
                            <label style="display:flex;gap:8px;align-items:center;cursor:pointer">
                                <input type="checkbox" name="{{ $input }}" value="1" @checked($val)> <span class="sub">مفعّل</span>
                            </label>
                        @elseif ($type === 'img')
                            @if ($val)
                                <div class="crow" style="margin-bottom:6px">
                                    <img src="{{ asset('storage/' . $val) }}" alt="" style="height:44px;border-radius:9px;border:1px solid var(--ln)">
                                    <label class="sub" style="cursor:pointer"><input type="checkbox" name="{{ $input }}_clear" value="1"> إزالة الشعار</label>
                                </div>
                            @endif
                            <input class="inp" type="file" name="{{ $input }}" accept="image/*">
                            @error($input)<span class="ferr">{{ $message }}</span>@enderror
                        @elseif ($type === 'number')
                            <input class="inp ltr" type="number" name="{{ $input }}" value="{{ $val }}" style="max-width:180px">
                        @elseif ($type === 'pass')
                            <input class="inp ltr" type="password" name="{{ $input }}" autocomplete="new-password"
                                   placeholder="{{ $val ? '•••••••• (محفوظ — اتركه فارغاً للإبقاء عليه)' : '' }}">
                        @else
                            <input class="inp" name="{{ $input }}" value="{{ $val }}">
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
    <div class="formfoot"><button class="btn p">حفظ كل الإعدادات</button></div>
    <div class="sub" style="margin-top:10px">لأي إعداد آخر من الطرفية: <span class="mono ltr">php artisan hub:set key value</span></div>
</form>

<div class="card" style="max-width:680px">
    <h3>🔌 اختبار اتصال أودو</h3>
    <p class="sub">بعد حفظ بيانات الربط الأربعة، جرّب الاتصال — وعند نجاحه تظهر بطاقة «أودو» في صفحات المشاريع والشركات والعملاء للربط الذكي.</p>
    @error('odoo')<div class="ferr" style="margin:6px 0">{{ $message }}</div>@enderror
    <form method="POST" action="{{ route('settings.odoo.test') }}" style="margin-top:8px">
        @csrf<button class="btn sm">🔌 اختبار الاتصال الآن</button>
    </form>
</div>
@endsection
