@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif
@if ($errors->any())
    <div class="flash bad"><b>تحقق من المدخلات:</b>
        {{-- v2.128: كل خطأ رابطٌ يقفز لحقله (المعرفات f-{key} من partial الحقول) --}}
        <ul>@foreach ($errors->getMessages() as $key => $msgs)
            <li><a href="#f-{{ $key }}" style="color:inherit;text-decoration:underline">{{ $msgs[0] }}</a></li>
        @endforeach</ul>
    </div>
@endif
