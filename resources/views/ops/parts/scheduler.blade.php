    {{-- (WP-2.3) جدولُ المجدولات بتاريخها: آخرُ تشغيلٍ ونتيجتُه، واتجاهُ المدّة من تاريخ
         النبضات في metric_points ('ops', <job>, 'run')، وآخرُ نجاحٍ وفشل، والفشلُ المتتالي،
         والموعدُ المتوقّع. البياناتُ تُشتقّ هنا لا في OpsController — المتحكّمُ ملكُ WP-2.6
         المتوازية، والقسمُ يملك قراءتَه كما يملك cc/trend رسمَه. آخرُ ٣٠ تشغيلاً لكل مجدولةٍ
         (استعلامٌ مفهرسٌ محدود لكلٍّ منها) لا نافذةٌ زمنية — مهمّةُ الـ٥ دقائق تكتب ٤ آلاف
         صفٍّ في أسبوعين ولا يلزم الرسمَ منها إلا ذيلُها. --}}
    @php
        // (WP-2.7) تاريخُ التشغيل (١٠ استعلاماتِ metric_points — واحدٌ لكل مجدولة)
        // خلف hub_screen المختومة ٦٠ ثانية: التاريخُ لا يتغيّر إلا بنبضةٍ جديدة.
        // **الحالةُ الحيّة تبقى حيّة**: status/late/at من $beats (نموذج الصحّة) تُدمج
        // عند الرسم خارج الخبيئة — فالمتأخّرةُ لا تختبئ ٦٠ ثانية خلف تاريخها.
        $ccKeys = array_column($beats, 'key');
        $ccHistW = hub_screen('ops.sched', 60, function () use ($ccKeys) {
            $ccHasMp = \Illuminate\Support\Facades\Schema::hasTable('metric_points');
            $hist = [];
            foreach ($ccKeys as $ccK) {
                $ccRuns = $ccHasMp
                    ? \Illuminate\Support\Facades\DB::table('metric_points')
                        ->where('module', 'ops')->where('record_id', $ccK)->where('metric', 'run')
                        ->orderByDesc('at')->orderByDesc('id')->limit(30)
                        ->get(['value', 'at', 'meta'])->reverse()->values()
                    : collect();
                $ccFails = 0; $ccLastOk = null; $ccLastFail = null; $ccSeries = [];
                foreach ($ccRuns as $ccR) {
                    $ccMeta = json_decode((string) $ccR->meta, true) ?: [];
                    if (($ccMeta['result'] ?? 'ok') === 'ok') { $ccLastOk = $ccR->at; $ccFails = 0; }
                    else { $ccLastFail = $ccR->at; $ccFails++; }
                    // مدّةٌ غائبة تُخزَّن -1 (critic #30) — لا تدخل رسمَ الاتجاه ولا تُرسم صفراً كاذباً
                    if ((float) $ccR->value >= 0) $ccSeries[] = ['at' => $ccR->at, 'value' => (float) $ccR->value];
                }
                $hist[$ccK] = [
                    'fails' => $ccFails, 'fails_capped' => $ccFails > 0 && $ccFails === $ccRuns->count(),
                    'last_ok' => $ccLastOk, 'last_fail' => $ccLastFail,
                    'spark' => hub_metric_spark($ccSeries, 20),
                ];
            }

            return $hist;
        }, ['metric_points'], true);
        $ccRows = [];
        foreach ($beats as $ccB) {
            $ccRows[] = $ccB + ($ccHistW['data'][$ccB['key']] ?? [
                'fails' => 0, 'fails_capped' => false, 'last_ok' => null, 'last_fail' => null, 'spark' => [],
            ]) + [
                'next' => $ccB['at'] ? \Illuminate\Support\Carbon::parse($ccB['at'])->addMinutes((int) $ccB['every']) : null,
            ];
        }
        // فرزٌ حقيقيّ خلف رؤوس cc/th — aria-sort لا يكذب؛ بلا ?sort= يبقى ترتيبُ Health::JOBS
        $ccSort = (string) request()->query('sort', '');
        $ccAsc = strtolower((string) request()->query('dir')) === 'asc';
        if (in_array($ccSort, ['at', 'ms', 'fails'], true)) {
            usort($ccRows, function ($a, $b) use ($ccSort, $ccAsc) {
                $va = $a[$ccSort] ?? null; $vb = $b[$ccSort] ?? null;
                // 'at' نصٌّ ISO يُقارَن حرفياً؛ 'ms' و'fails' أرقامٌ — مقارنةُ نصٍّ تجعل ٩ > ١٠
                $cmp = ($va === null) <=> ($vb === null)
                    ?: ($ccSort === 'at' ? ((string) $va <=> (string) $vb) : ((float) $va <=> (float) $vb))
                    ?: strcmp($a['key'], $b['key']);
                return $ccAsc ? $cmp : -$cmp;
            });
        }
    @endphp
    <div class="card kid">
        <h3>⏰ المجدولات <span class="sub">· التاريخُ من نبضات آخر ٣٠ تشغيلاً</span></h3>
        @if (! $ccRows)
            @include('partials.empty', ['text' => 'لا مجدولات معرَّفة', 'icon' => '⏰'])
        @else
            <div class="tblwrap">
                <table class="mini">
                    <thead><tr>
                        <th scope="col">المجدولة</th>
                        @include('partials.cc.th', ['col' => 'at', 'label' => 'آخر تشغيل', 'default' => ''])
                        @include('partials.cc.th', ['col' => 'ms', 'label' => 'المدّة واتجاهها', 'default' => ''])
                        <th scope="col">آخر نجاح / فشل</th>
                        @include('partials.cc.th', ['col' => 'fails', 'label' => 'فشل متتالٍ', 'default' => ''])
                        <th scope="col">الموعد المتوقّع</th>
                        <th scope="col">الحالة</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($ccRows as $b)
                        @php
                            $bad = ! empty($b['result']) && $b['result'] !== 'ok';
                            $next = $b['next'];
                        @endphp
                        <tr>
                            <td>{{ $b['label'] }}</td>
                            <td>@if ($b['at']){{ \Illuminate\Support\Carbon::parse($b['at'])->diffForHumans() }}@if ($bad) <b class="txt-bad">نتيجة: {{ $b['result'] }}</b>@endif @else <span class="sub">لم تعمل بعد</span> @endif</td>
                            <td>
                                @if ($b['spark'])
                                    <div style="display:flex;align-items:center;gap:8px">
                                        <span style="white-space:nowrap">{{ $b['ms'] !== null ? $b['ms'] . 'ms' : '—' }}</span>
                                        <div style="display:flex;align-items:flex-end;gap:2px;height:26px;min-width:70px" role="img"
                                             aria-label="اتجاه مدّة آخر {{ count($b['spark']) }} تشغيلاً">
                                            @foreach ($b['spark'] as $p)
                                                <span title="{{ $p['at'] }} — {{ rtrim(rtrim(number_format($p['value'], 0), '0'), '.') }}ms"
                                                      style="display:block;flex:1;min-width:3px;border-radius:2px 2px 0 0;background:var(--p);height:{{ max(12, $p['pct'] * 26 / 100) }}px"></span>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    <span class="sub">لا مدّة مسجَّلة بعد</span>
                                @endif
                            </td>
                            <td class="sub" style="white-space:nowrap">
                                {{ $b['last_ok'] ? '✓ ' . \Illuminate\Support\Carbon::parse($b['last_ok'])->diffForHumans() : '—' }}
                                / {{ $b['last_fail'] ? '✗ ' . \Illuminate\Support\Carbon::parse($b['last_fail'])->diffForHumans() : '—' }}
                            </td>
                            <td>@if ($b['fails'] > 0)<span class="bdg {{ $b['fails'] >= 3 ? 'bad' : 'wn' }}">{{ $b['fails'] }}{{ $b['fails_capped'] ? '+' : '' }}</span>@else <span class="sub">٠</span>@endif</td>
                            <td class="sub" style="white-space:nowrap">@if ($next){{ $next->diffForHumans() }}@else — @endif</td>
                            <td class="acts"><span class="bdg {{ ($b['status'] ?? '') === \App\Support\Health::UNAVAILABLE ? 'bad' : ($b['late'] ? 'wn' : 'ok') }}">{{ ($b['status'] ?? '') === \App\Support\Health::UNKNOWN ? '— لم تنبض' : ($b['late'] ? '⚠️ متأخرة' : '✓ تعمل') }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="sub" style="margin-top:6px">إن كانت كلها متأخرة فسطر cron غير مفعّل على الخادم (انظر README)</div>
            {{-- طزاجةُ التاريخ المخبّأ وحده — الحالة (متأخرة/تعمل) حيّةٌ دائماً من نموذج الصحّة --}}
            @include('partials.cc.freshness', ['at' => $ccHistW['at'], 'ttl' => 60])
        @endif
    </div>
