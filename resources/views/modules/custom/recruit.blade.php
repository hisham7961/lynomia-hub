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
        <form method="POST" action="{{ route('recruit.hire', $row->id) }}" class="inline">
            @csrf<button class="btn">🎉 عيّن المرشح</button>
        </form>
    </div>
@elseif (! empty($rcMeta['employee_id']))
    <div class="card">
        <h3 class="cardtitle">🎉 التعيين</h3>
        <div class="sub">عُيّن — <a href="{{ route('m.show', ['hr', $rcMeta['employee_id']]) }}">ملفه الوظيفي ↗</a></div>
    </div>
@endif
