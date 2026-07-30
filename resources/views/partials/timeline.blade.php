{{--
    الخط الزمني الموحَّد لسجل — مخرجات hub_timeline().
    مصدره أربعة جداول تتشارك (module, record_id): التدقيق والتعليقات والمرفقات والإصدارات.
    $timeline  مصفوفة الأحداث (مطلوب)
--}}
@if (count($timeline))
    <div class="card">
        <h3>🧾 ما جرى على هذا السجل</h3>
        <div class="tl">
            @foreach ($timeline as $e)
                <div class="tlrow">
                    <span class="tlico">{{ $e['ico'] }}</span>
                    <span class="tlmain">
                        <b>{{ $e['label'] }}</b>
                        @if ($e['title'] !== '')
                            —
                            @if ($e['url'])
                                <a href="{{ hub_safe_url($e['url']) }}">{{ $e['title'] }}</a>
                            @else
                                {{ $e['title'] }}
                            @endif
                        @endif
                        @if ($e['who'])<span class="sub"> · {{ $e['who'] }}</span>@endif
                    </span>
                    <span class="mono sub tlat">{{ \Illuminate\Support\Carbon::parse($e['at'])->format('Y-m-d H:i') }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
