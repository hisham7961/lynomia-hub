{{-- زر التعيين — يتوقع $row (المرشح). يظهر لعرضٍ وظيفي لم يُعيَّن بعد --}}
@php $rcMeta = (array) ($row->meta ?? []); @endphp
@if (empty($rcMeta['employee_id']) && ! in_array((string) $row->stage, ['مرفوض'], true)
     && hub_can(auth()->user(), 'recruit', 'e') && hub_can(auth()->user(), 'hr', 'a'))
    <div class="card">
        <h3 class="cardtitle">🎉 التعيين</h3>
        <div class="sub" style="margin-bottom:8px">
            ينشئ الملف الوظيفي من بيانات المرشح (الاسم، الوظيفة، القسم، البريد، الهاتف، الراتب المتوقع)
            وينقل المرحلة إلى «تم التعيين» — بلا إعادة إدخال.
        </div>
        <form method="POST" action="{{ route('recruit.hire', $row->id) }}">
            @csrf
            @if (hub_flag(auth()->user(), 'users'))
                {{-- حسابُ النظام مع التعيين لا بعده بأسبوع: يُختار الدور الآن،
                     وتُعرض كلمةٌ مؤقتة مرةً واحدة، ويُشعَر من يدير المستخدمين لمراجعة الصلاحيات --}}
                <div class="fld" style="max-width:340px;margin-bottom:8px">
                    <label>أنشئ له حساباً بالنظام بدور</label>
                    <select class="inp" name="role_id">
                        <option value="">بلا حساب الآن</option>
                        @foreach (\App\Models\Role::when(! hub_is_owner(), fn ($q) => $q->where('is_owner', false))->orderBy('name')->get() as $rrole)
                            <option value="{{ $rrole->id }}">{{ $rrole->name }}{{ $rrole->is_owner ? ' — مالك النظام' : '' }}</option>
                        @endforeach
                    </select>
                    <small class="mut">تُنشأ كلمةُ مرورٍ مؤقتة تُعرض مرةً واحدة، ويصل من يدير المستخدمين إشعارٌ لمراجعة صلاحياته.</small>
                </div>
            @endif
            <button class="btn">🎉 عيّن المرشح</button>
        </form>
    </div>
@elseif (! empty($rcMeta['employee_id']))
    <div class="card">
        <h3 class="cardtitle">🎉 التعيين</h3>
        <div class="sub">عُيّن — <a href="{{ route('m.show', ['hr', $rcMeta['employee_id']]) }}">ملفه الوظيفي ↗</a></div>
    </div>
@endif
