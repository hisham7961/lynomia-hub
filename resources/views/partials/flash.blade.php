@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif
@if ($errors->any())
    <div class="flash bad"><b>تحقق من المدخلات:</b>
        <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
