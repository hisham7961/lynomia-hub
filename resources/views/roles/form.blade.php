@extends('layouts.app')
@section('title', $role ? 'تعديل دور' : 'دور جديد')
@section('content')
@include('partials.pagehead', ['icon' => '🎭', 'title' => $role ? 'تعديل دور' : 'دور جديد',
    'crumb' => 'الأدوار والصلاحيات', 'crumbUrl' => route('roles.index'), 'back' => route('roles.index'), 'backLabel' => 'الأدوار'])
@php $mx = $role ? (is_array($role->matrix) ? $role->matrix : (json_decode($role->matrix ?? '[]', true) ?: [])) : []; @endphp
<div class="card">
    <form method="POST" action="{{ $role ? route('roles.update', $role) : route('roles.store') }}">
        @csrf @if($role)@method('PUT')@endif
        <div class="fg">
            <div class="fld"><label for="rf-name">اسم الدور <b class="req">*</b></label><input class="inp" id="rf-name" name="name" value="{{ old('name', $role?->name) }}" required></div>
            <div class="fld"><label for="rf-scope">النطاق</label>
                <select class="inp" id="rf-scope" name="scope">
                    <option value="all" @selected(old('scope', $role?->scope) === 'all')>كل النظام</option>
                    <option value="proj" @selected(old('scope', $role?->scope ?? 'proj') === 'proj')>حسب المشروع</option>
                </select>
            </div>
        </div>
        <h3 style="margin-top:14px">مصفوفة الصلاحيات</h3>
        <div class="matrixwrap">
        <table class="tbl matrix">
            <thead><tr><th>الوحدة</th>@foreach ($ops as $l)<th>{{ $l }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach (hub_modules() as $mk => $md)
                <tr>
                    <td>{{ $md['label'] }} <button type="button" class="btn ghost xs" data-row-toggle aria-label="تبديل كل صلاحيات {{ $md['label'] }}">الكل</button></td>
                    @foreach ($ops as $op => $l)
                        {{-- aria-label: ٢٧٦ مربعاً بلا اسم يقرؤها قارئ الشاشة «خانة اختيار» فقط --}}
                        <td class="c"><input type="checkbox" name="matrix[{{ $mk }}][{{ $op }}]" value="1" aria-label="{{ $l }} — {{ $md['label'] }}" @checked(!empty($mx[$mk][$op]))></td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        @php $fr = $role ? (is_array($role->field_rules) ? $role->field_rules : (json_decode($role->field_rules ?? '[]', true) ?: [])) : []; @endphp
        <h3 style="margin-top:14px">🔬 صلاحيات مستوى الحقل <span class="sub">(اختياري — عادي: يرى ويعدل · قراءة فقط · مخفي تماماً)</span></h3>
        <div class="sub" style="margin-bottom:8px">مثال: أخفِ «الراتب» في ملفات الموظفين عن هذا الدور — يختفي من الجداول والصفحات والتصدير والـ API، ولا يُكتب حتى لو حُقن في الطلب. المالك يرى كل شيء دائماً.</div>
        @foreach (hub_modules() as $mk => $md)
            @php $set = collect($fr[$mk] ?? [])->filter()->count(); @endphp
            <details style="margin-bottom:6px" {{ $set ? 'open' : '' }}>
                <summary>{{ $md['label'] }} @if ($set)<span class="bdg wn">{{ $set }} قيد</span>@endif</summary>
                <div class="fg" style="padding:8px 4px">
                    @foreach ($md['fields'] as $f)
                        <div class="fld">
                            <label class="sub">{{ $f['label'] }}</label>
                            <select class="inp" name="fr[{{ $mk }}][{{ $f['key'] }}]">
                                <option value="">عادي</option>
                                <option value="ro" @selected(($fr[$mk][$f['key']] ?? '') === 'ro')>قراءة فقط</option>
                                <option value="hide" @selected(($fr[$mk][$f['key']] ?? '') === 'hide')>مخفي</option>
                            </select>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
        <div class="formfoot"><button class="btn p">حفظ الدور</button><a class="btn ghost" href="{{ route('roles.index') }}">إلغاء</a></div>
    </form>
</div>
@endsection
