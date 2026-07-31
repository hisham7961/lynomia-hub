{{-- معاينة حية لنص الوثيقة بعد حل المتغيرات — كما سيراه الموقّع تماماً --}}
@if (count($missing))
    <div class="flash bad" style="margin:0 0 10px">⚠️ متغيرات إلزامية لم تُملأ بعد: {{ implode('، ', $missing) }}</div>
@endif
<div class="card" style="padding:22px 24px;max-height:60vh;overflow:auto">
    <div style="white-space:pre-wrap;line-height:2;font-size:14.5px">{{ $body }}</div>
</div>
<div class="sub" style="margin-top:8px">⟦القيم بين الأقواس⟧ متغيرات اختيارية لم تُملأ — تظهر هكذا في الوثيقة إن أُرسلت.</div>
