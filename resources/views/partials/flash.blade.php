@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
{{-- كلمةُ المرور المؤقتة تُعرض مرةً واحدة ولا تُحفظ في أي مكان يُقرأ لاحقاً --}}
@if (session('temp_password'))
    <div class="flash wn">
        🔑 <b>كلمة مرورٍ مؤقتة — تُعرض مرةً واحدة.</b> سلّمها لصاحبها بقناةٍ آمنة؛ سيُطلب منه تبديلها عند أول دخول.<br>
        <span class="ltr mono">{{ session('temp_password_for') }}</span> ·
        <b class="ltr mono">{{ session('temp_password') }}</b>
    </div>
@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif
@if ($errors->any())
    <div class="flash bad"><b>تحقق من المدخلات:</b>
        {{-- v2.128: كل خطأ رابطٌ يقفز لحقله (المعرفات f-{key} من partial الحقول) --}}
        <ul>@foreach ($errors->getMessages() as $key => $msgs)
            <li><a href="#f-{{ $key }}" style="color:inherit;text-decoration:underline">{{ $msgs[0] }}</a></li>
        @endforeach</ul>
    </div>
@endif
