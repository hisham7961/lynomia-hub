{{-- بطاقة يوم العمل — تتوقع $data من Workday::mine: emp/att/entries/hours/projects/clients.
     موبايل أولاً: زرٌّ واحدٌ كبير، والسياق (وضع/مشروع) اختياريٌّ بجانبه. --}}
@php
    $wAtt = $data['att'] ?? null;
    // عدّادٌ حيٌّ لمدّة العمل منذ الحضور: تُحسَب الثواني المنقضيةُ **خادميّاً** لحظةَ العرض،
    // ثمّ يعدُّ المتصفّحُ صعوداً منها بفارقِ ساعتِه هو — فلا حسابَ منطقةٍ زمنيّةٍ ولا انحرافَ
    // ساعةٍ على العميل. يظهرُ ما دام حاضراً ولم ينصرف؛ عند الانصراف تحلُّ «ساعاتُ اليوم» محلَّه.
    $wElapsed = null;
    if ($wAtt && $wAtt->time_in && ! $wAtt->time_out) {
        try {
            $wDate = $wAtt->date instanceof \Illuminate\Support\Carbon
                ? $wAtt->date->toDateString() : (string) $wAtt->date;
            $wStart = \Illuminate\Support\Carbon::parse($wDate . ' ' . $wAtt->time_in, config('app.timezone'));
            $wElapsed = max(0, $wStart->diffInSeconds(now(), false));
        } catch (\Throwable $e) {
            $wElapsed = null;
        }
    }
@endphp
<div class="card" id="myworkday">
    <h3 class="cardtitle">🕗 يومي
        @if ($wAtt?->status)<span class="bdg {{ hub_tone($wAtt->status) }}">{{ $wAtt->status }}</span>@endif
    </h3>

    @if (! $wAtt || ! $wAtt->time_in)
        {{-- لم يبدأ اليوم: حضورٌ بضغطةٍ وسياقٍ اختياري --}}
        <form method="POST" action="{{ route('workday.in') }}" class="crow">
            @csrf
            <input type="hidden" name="geo" id="wd-geo" value="">
            <label class="vh" for="wd-mode">وضع العمل</label>
            <select class="inp" id="wd-mode" name="mode" style="max-width:150px">
                @foreach (['مكتب', 'عن بعد', 'موقع عميل', 'عمل ميداني', 'مهمة خارجية'] as $m)
                    <option value="{{ $m }}">{{ $m }}</option>
                @endforeach
            </select>
            @if (! empty($data['projects']))
                <label class="vh" for="wd-proj">المشروع</label>
                <select class="inp" id="wd-proj" name="project_id" style="max-width:190px">
                    <option value="">— المشروع (اختياري) —</option>
                    @foreach ($data['projects'] as $pid => $pn)<option value="{{ $pid }}">{{ $pn }}</option>@endforeach
                </select>
            @endif
            @if (! empty($data['clients']))
                <label class="vh" for="wd-cli">العميل</label>
                <select class="inp" id="wd-cli" name="client_id" style="max-width:170px">
                    <option value="">— العميل (لموقع عميل) —</option>
                    @foreach ($data['clients'] as $kid => $kn)<option value="{{ $kid }}">{{ $kn }}</option>@endforeach
                </select>
            @endif
            <button class="btn p">▶ تسجيل الحضور</button>
        </form>
        @if (setting('work.geo', '0') === '1')
            <span class="sub fhint">يُلتقط موقعُك لحظةَ الحضور فقط إن سمحتَ به — لا تتبّعَ بعدها.</span>
            <script>
            if (navigator.geolocation) navigator.geolocation.getCurrentPosition(function (p) {
                var g = document.getElementById('wd-geo');
                if (g) g.value = p.coords.latitude.toFixed(5) + ',' + p.coords.longitude.toFixed(5)
                    + '±' + Math.round(p.coords.accuracy) + 'م';
            }, function () {}, { timeout: 5000 });
            </script>
        @endif
    @else
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
            <div><div class="sub">حضور</div><b class="mono">{{ $wAtt->time_in }}</b>
                @if ($wAtt->mode)<span class="sub">· {{ $wAtt->mode }}</span>@endif</div>
            @unless (is_null($wElapsed))
                {{-- العدّادُ الحيّ: مدّةُ العمل منذ الحضور، يعدُّ صعوداً كلَّ ثانية --}}
                <div><div class="sub">⏱ مدّة العمل</div>
                    <b class="mono" id="wd-live-timer" data-elapsed="{{ $wElapsed }}" title="منذ تسجيل الحضور">—</b></div>
            @endunless
            @if ($wAtt->time_out)
                <div><div class="sub">انصراف</div><b class="mono">{{ $wAtt->time_out }}</b></div>
                <div><div class="sub">ساعات اليوم</div><b class="mono">{{ $wAtt->hours }}</b></div>
            @endif
            <div><div class="sub">بنود تقريري اليوم</div>
                <b>{{ $data['entries'] }}</b> <span class="sub">({{ $data['hours'] }} س)</span></div>
            <span class="spacer"></span>
            <a class="btn ghost sm" href="{{ route('reports.mine') }}">📝 تقرير اليوم</a>
            <a class="btn ghost sm" href="{{ route('m.create', 'updates') }}">＋ بند عمل</a>
            @if (! $wAtt->time_out)
                <form method="POST" action="{{ route('workday.out') }}" class="inline"
                      data-confirm="تسجيل الانصراف الآن؟{{ $data['entries'] === 0 ? ' تقريرُك اليومي ما زال فارغاً.' : '' }}">
                    @csrf
                    <button class="btn danger sm">⏹ انصراف</button>
                </form>
            @endif
        </div>
        @if (! $wAtt->time_out && $data['entries'] === 0)
            <div class="sub" style="margin-top:6px">لم تكتب بندَ عملٍ بعد — بندٌ لكل مشروعٍ عملتَ عليه اليوم،
                وساعاتُه تدخل مهمتَه تلقائياً.</div>
        @endif
        @unless (is_null($wElapsed))
            <script>
            (function () {
                var el = document.getElementById('wd-live-timer');
                if (! el || el.dataset.ticking) return;   // لا نُكرّر المؤقّتَ على العنصر نفسِه
                el.dataset.ticking = '1';
                // الأساسُ من الخادم (ثوانٍ منقضية عند العرض) + فارقُ ساعةِ المتصفّحِ منذ التحميل
                var base = Date.now();
                var start = parseInt(el.getAttribute('data-elapsed'), 10) || 0;
                function two(n) { return (n < 10 ? '0' : '') + n; }
                function tick() {
                    var s = start + Math.floor((Date.now() - base) / 1000);
                    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
                    el.textContent = h + ':' + two(m) + ':' + two(s % 60);
                }
                tick();
                setInterval(tick, 1000);
            })();
            </script>
        @endunless
    @endif
</div>
