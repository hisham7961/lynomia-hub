@extends('layouts.app')
@section('title', 'الأدوار والصلاحيات')
@section('content')
@component('partials.pagehead', ['icon' => '🎭', 'title' => 'الأدوار والصلاحيات', 'crumb' => 'النظام',
    'sub' => 'ماذا يبلغ كل دور بالأرقام — لا اسمُه ونطاقُه فقط'])
    <a class="btn p sm" href="{{ route('roles.create') }}">＋ دور جديد</a>
@endcomponent
<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th>الدور</th><th>النطاق</th><th>المدى</th><th>الصلاحيات العامة</th><th>المستخدمون</th><th class="acts">إجراءات</th>
        </tr></thead>
        <tbody>
        @foreach ($roles as $r)
            @php
                $x = $reach[$r->id];
                $flags = is_array($r->flags) ? $r->flags : (json_decode($r->flags ?? '[]', true) ?: []);
                $on = array_keys(array_filter($flags));
            @endphp
            <tr>
                <td><b>{{ $r->name }}</b> @if($r->is_owner)<span class="bdg ok">مالك النظام</span>@endif</td>
                <td>{{ $r->scope === 'all' ? 'كل النظام' : 'حسب المشروع' }}</td>
                <td>
                    @if ($x['owner'])
                        <span class="bdg ok">كل شيء — يتجاوز المصفوفة</span>
                    @else
                        <span class="bdg">يرى {{ $x['v'] }}</span>
                        <span class="bdg {{ $x['e'] ? 'wn' : '' }}">يعدّل {{ $x['e'] }}</span>
                        <span class="bdg {{ $x['d'] ? 'bad' : '' }}">يحذف {{ $x['d'] }}</span>
                        @if ($x['fields'])<span class="bdg wn" title="قيود مستوى الحقل">🔬 {{ $x['fields'] }}</span>@endif
                    @endif
                </td>
                <td>
                    @forelse ($on as $f)
                        <span class="bdg {{ in_array($f, \App\Http\Controllers\Web\RoleController::RISKY_FLAGS, true) ? 'wn' : '' }}">
                            {{ \App\Http\Controllers\Web\RoleController::FLAGS[$f] ?? $f }}
                        </span>
                    @empty
                        <span class="sub">—</span>
                    @endforelse
                </td>
                <td>{{ $r->users_count }}</td>
                <td class="acts">
                    @unless ($r->is_owner)
                        <a class="btn ghost xs" href="{{ route('roles.edit', $r) }}">تعديل</a>
                        <form class="inline" method="POST" action="{{ route('roles.clone', $r) }}">@csrf<button class="btn ghost xs">استنسخ</button></form>
                        <form class="inline" method="POST" action="{{ route('roles.destroy', $r) }}" data-confirm="حذف الدور؟">@csrf @method('DELETE')<button class="btn ghost xs dn">حذف</button></form>
                    @else
                        <span class="sub">لا يُعدَّل ولا يُحذف</span>
                    @endunless
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
<div class="card" style="margin-top:12px">
    <div class="sub" style="line-height:2">
        <b>المدى</b> يُحصى من المصفوفة: كم وحدةً يبلغها الدور عرضاً وتعديلاً وحذفاً، و🔬 عدد قيود مستوى الحقل.
        و<b>دور المالك</b> يتجاوز المصفوفة والرايات كلها، فلا معنى لتحريره — ولا تُنزع ملكيته لأن إعادتها تحتاج مالكاً.<br>
        أسرع طريقٍ لدورٍ جديد: <b>استنسخ</b> أقرب دورٍ قائم وعدّل النسخة، أو ابدأ من <a href="{{ route('roles.create') }}">قالبٍ جاهز</a>.
    </div>
</div>
@endsection
