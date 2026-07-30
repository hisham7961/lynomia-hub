@extends('layouts.app')
@section('title', $role ? 'تعديل دور' : 'دور جديد')
@section('content')
@php $mx = $role ? (is_array($role->matrix) ? $role->matrix : (json_decode($role->matrix ?? '[]', true) ?: [])) : []; @endphp
<div class="card">
    <form method="POST" action="{{ $role ? route('roles.update', $role) : route('roles.store') }}">
        @csrf @if($role)@method('PUT')@endif
        <div class="fg">
            <div class="fld"><label>اسم الدور <b class="req">*</b></label><input class="inp" name="name" value="{{ old('name', $role?->name) }}" required></div>
            <div class="fld"><label>النطاق</label>
                <select class="inp" name="scope">
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
                    <td>{{ $md['label'] }} <button type="button" class="btn ghost xs" data-row-toggle>الكل</button></td>
                    @foreach ($ops as $op => $l)
                        <td class="c"><input type="checkbox" name="matrix[{{ $mk }}][{{ $op }}]" value="1" @checked(!empty($mx[$mk][$op]))></td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        <div class="formfoot"><button class="btn p">حفظ الدور</button><a class="btn ghost" href="{{ route('roles.index') }}">إلغاء</a></div>
    </form>
</div>
@endsection
