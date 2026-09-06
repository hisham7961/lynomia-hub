    <div class="card kid">
        {{-- (WP-2.7 · critic #19) السقفُ معلَن: «آخر ٢٥ سطراً» لا قائمةٌ توهم بالكمال --}}
        <h3>📜 آخر أخطاء ملف السجل <span class="sub">· آخر ٢٥ سطر خطأ من laravel.log بلا SSH</span></h3>
        @if (count($logLines))
            <div class="mono ltr" style="font-size:10.5px;max-height:220px;overflow:auto;background:var(--bg2);border-radius:10px;padding:8px;white-space:pre-wrap;word-break:break-all">@foreach ($logLines as $l){{ $l }}
@endforeach</div>
        @else
            <div class="sub">✅ لا أسطر أخطاء في نهاية ملف السجل.</div>
        @endif
    </div>
