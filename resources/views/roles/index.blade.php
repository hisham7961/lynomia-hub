@extends('layouts.app')
@section('title', 'الأدوار والصلاحيات')
@section('content')
@component('partials.pagehead', ['icon' => '🎭', 'title' => 'الأدوار والصلاحيات', 'crumb' => 'النظام',
    'sub' => 'ماذا يبلغ كل دور بالأرقام — لا اسمُه ونطاقُه فقط'])
    <a class="btn ghost sm" href="{{ route('access.index') }}">🔎 تشخيص الوصول</a>
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
                        <a class="btn ghost xs" href="{{ route('access.role', $r) }}" title="معاينة تنقّل الدور">🔭 معاينة</a>
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

{{-- ═══════ السؤالُ المقلوب: مَن يكتب في كلِّ وحدة؟ (M-F12) ═══════ --}}
@php
    $thin = $coverage->where('thin', true);
    $tot  = $coverage->first()['total'] ?? 0;
@endphp
<div class="card" style="margin-top:12px">
    <h3 style="margin:0 0 4px">🔁 من يكتب في كل وحدة</h3>
    <div class="sub" style="margin-bottom:10px">
        الجدولُ أعلاه يسأل «كم وحدةً يبلغ هذا <b>الدور</b>؟». وهذا يسأل عكسَه:
        «كم <b>شخصاً</b> يستطيع الكتابةَ في هذه الوحدة؟» — وهو السؤالُ الذي يفسّر
        بقاءَ وحدةٍ فارغةً مهما طال العمل. <b>وحدةٌ لا يبلغها إلّا واحدٌ أو اثنان
        تُوسَم «ضيّقة»</b>، وذلك ليس عطباً بالضرورة: بنيةٌ تحتيّةٌ أو سجلٌّ حسّاس
        ضيقُه مقصود. والقرارُ لك — لكن بعد أن تراه.
        @if ($thin->count())
            <br><b class="wn">{{ $thin->count() }}</b> من {{ $coverage->count() }} وحدةً ضيّقةٌ الآن
            (من أصل {{ $tot }} موظّفاً غيرَ موقوف).
        @endif
    </div>

    {{-- **والعددُ وحدَه لا يُفرَز**: «٥٥ ضيّقة» رقمٌ صادقٌ لا يُفعَل به شيء.
         فيُقسَم بأدلّةٍ تُفحَص إلى ثلاثِ سلالٍ — وكلُّ صنفٍ يُعرَض مع دليله كي
         يقدر القارئُ أن **يخالف** بنظرة، لا أن يُملى عليه. --}}
    @php
        $tri = collect($triage ?? []);
        $cls = ['guarded' => ['🔒 ضيقُه حارس', 'g'], 'idle' => ['💤 لا تُستعمل', 'g'],
                'blocked' => ['🚧 معطَّلةٌ فعلاً', 'wn']];
    @endphp
    @if ($tri->count())
        <div class="crow" style="gap:8px;margin-bottom:10px;flex-wrap:wrap">
            @foreach ($cls as $k => [$lbl, $tone])
                @php $n = $tri->where('class', $k)->count(); @endphp
                <span class="bdg {{ $n ? $tone : '' }}">{{ $lbl }}: <b>{{ $n }}</b></span>
            @endforeach
        </div>
        @php $real = $tri->where('class', 'blocked'); @endphp
        <div class="sub" style="margin-bottom:10px">
            @if ($real->count())
                <b>{{ $real->count() }}</b> وحدةً فقط تُستعمل فعلاً وكتّابُها اثنان — <b>هذه وحدَها</b>
                تستحقّ قراراً. والباقي حارسٌ بحقٍّ أو لا يُستعمل، فتوسيعُه لا يغيّر شيئاً.
                <br>للتوسيع: <code class="mono">php artisan hub:roles-widen-core-work "اسم الدور"</code>
            @else
                ✅ لا وحدةَ معطَّلةً فعلاً — كلُّ ضيّقٍ هنا حارسٌ بحقٍّ أو لا يُستعمل بعد.
            @endif
        </div>
    @endif

    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الوحدة</th><th>يقرأ</th><th>يكتب</th><th>الأدوار التي تمنح الكتابة</th></tr></thead>
        <tbody>
        @foreach ($coverage as $mk => $c)
            <tr>
                <td><b>{{ $c['label'] }}</b> <span class="sub mono">{{ $mk }}</span>
                    @if ($c['thin'])
                        <span class="bdg wn" title="لا يبلغها إلا واحدٌ أو اثنان">ضيّقة</span>
                        @php $t = ($triage ?? collect())[$mk] ?? null; @endphp
                        @if ($t)
                            <span class="bdg {{ $cls[$t['class']][1] ?? '' }}" title="{{ $t['why'] }}">{{ $cls[$t['class']][0] ?? '' }}</span>
                            <div class="sub" style="font-size:12px">{{ $t['why'] }}</div>
                        @endif
                    @endif</td>
                <td class="mono">{{ $c['viewers'] }}</td>
                <td class="mono">{{ $c['writers'] }}</td>
                <td class="sub">{{ $c['writer_roles'] ? implode('، ', $c['writer_roles']) : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endsection
