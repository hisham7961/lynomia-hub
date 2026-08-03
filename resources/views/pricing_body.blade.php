{{-- الباقات والتسعير: بطاقاتٌ لا صفوف — كل خدمةٍ بوجهها وباقاتها وهامشها وموقعها من السوق --}}
@php $pr = \App\Support\Pricing::all((bool) request()->query('fresh')); @endphp

@if (count($pr['insights']))
    <div class="card" style="margin-bottom:12px">
        <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0">🎯 ما يستحق قراراً</h3>
            <a class="btn ghost xs" href="{{ route('m.index', 'plans') }}?fresh=1">🔄 أعد الحساب</a>
        </div>
        <div class="sub" style="margin:4px 0 10px">لا سرداً بل عملاً — الأحمر أولاً.</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px">
            @foreach (array_slice($pr['insights'], 0, 12) as $i)
                <div style="padding:10px 12px;border:1px solid var(--ln);border-radius:11px;
                            background:color-mix(in srgb, var(--{{ $i['tone'] }}) 9%, transparent)">
                    <div class="crow" style="gap:6px;align-items:baseline">
                        <span>{{ $i['icon'] }}</span><b style="font-size:13px">{{ $i['title'] }}</b>
                    </div>
                    <div style="font-size:12px;margin-top:3px"><b>{{ $i['what'] }}</b></div>
                    <div class="sub" style="font-size:12px;line-height:1.8;margin-top:4px">{{ $i['why'] }}</div>
                </div>
            @endforeach
        </div>
    </div>
@endif

@forelse ($pr['catalog'] as $s)
    <div class="card" style="margin-bottom:14px">
        <div class="crow" style="gap:10px;align-items:center;flex-wrap:wrap">
            @if ($s['logo'])
                <img src="{{ route('file.show', $s['logo']) }}" alt=""
                     style="height:42px;width:42px;object-fit:cover;border-radius:11px;border:1px solid var(--ln)">
            @else
                <span style="height:42px;width:42px;display:grid;place-items:center;border-radius:11px;
                             border:1px solid var(--ln);font-size:18px">📦</span>
            @endif
            <div style="flex:1;min-width:180px">
                <h3 style="margin:0">{{ $s['service'] }}</h3>
                <div class="sub">
                    {{ $s['kind'] ?: 'خدمة' }}{{ $s['appName'] ? ' · ' . $s['appName'] : '' }}
                    @if ($s['opex'] !== null) · تشغيلٌ شهري {{ number_format($s['opex'], 0) }}@endif
                    · {{ count($s['plans']) }} باقات
                </div>
            </div>
            @if ($s['market']['n'])
                <div style="text-align:center">
                    <div class="sub" style="font-size:11px">نطاق السوق ({{ $s['market']['n'] }} منافسين)</div>
                    <span class="bdg">{{ number_format((float) $s['market']['min'], 0) }} — {{ number_format((float) $s['market']['max'], 0) }}</span>
                </div>
            @endif
        </div>

        {{-- بطاقات الباقات --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-top:12px">
            @foreach ($s['plans'] as $p)
                <div style="border:1px solid var(--ln);border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:6px;
                            {{ $p['belowCost'] ? 'border-color:var(--bad)' : ($p['free'] ? 'border-style:dashed' : '') }}">
                    <div class="crow" style="justify-content:space-between;align-items:baseline;gap:6px">
                        <b>{{ $p['name'] }}</b>
                        @if ($p['free'])<span class="bdg ok">مجانية</span>@endif
                        @if ($p['status'] && $p['status'] !== 'نشطة')<span class="bdg">{{ $p['status'] }}</span>@endif
                    </div>

                    <div style="font-size:26px;font-weight:800;line-height:1.2">
                        @if ($p['free']) 0
                        @elseif ($p['price'] === null) <span class="sub" style="font-size:15px">بلا سعر</span>
                        @else {{ number_format($p['price'], $p['price'] == (int) $p['price'] ? 0 : 2) }}@endif
                        <span class="sub" style="font-size:12px;font-weight:600">
                            {{ $p['currency'] ?: setting('app.currency', 'د.ك') }}{{ $p['cycle'] ? ' / ' . $p['cycle'] : '' }}
                        </span>
                    </div>

                    <div class="crow" style="gap:5px;flex-wrap:wrap">
                        @if ($p['belowCost'])
                            <span class="bdg bad" title="تكلفة الوحدة أعلى من السعر">🩸 تحت التكلفة</span>
                        @elseif ($p['margin'] !== null)
                            <span class="bdg {{ $p['margin'] >= 50 ? 'ok' : ($p['margin'] >= 20 ? '' : 'wn') }}">هامش {{ $p['margin'] }}٪</span>
                        @elseif (! $p['free'])
                            <span class="bdg wn" title="لا تكلفة وحدة مسجَّلة">بلا هامش معروف</span>
                        @endif

                        @if ($p['dir'] === 'up')<span class="bdg wn" title="السعر السابق {{ $p['prev'] }}">▲ رُفع</span>
                        @elseif ($p['dir'] === 'down')<span class="bdg" title="السعر السابق {{ $p['prev'] }}">▼ خُفض</span>@endif

                        @if ($p['staleDays'] !== null && $p['staleDays'] > 730)
                            <span class="bdg wn" title="آخر تغيير {{ substr((string) $p['changedAt'], 0, 10) }}">🕰️ ثابتٌ سنتين</span>
                        @endif
                        @if ($p['market'])<span class="bdg">{{ $p['market'] }}</span>@endif
                    </div>

                    @if (count($p['features']))
                        <ul class="sub" style="margin:4px 0 0;padding-inline-start:16px;line-height:1.9;font-size:12px">
                            @foreach (array_slice($p['features'], 0, 6) as $f)<li>{{ $f }}</li>@endforeach
                        </ul>
                    @endif
                    @if (count($p['limits']))
                        <div class="sub" style="font-size:12px;line-height:1.8">
                            <b>الحدود:</b> {{ implode(' · ', array_slice($p['limits'], 0, 4)) }}
                        </div>
                    @endif
                    @if ($p['addons'])<div class="sub" style="font-size:12px">➕ {{ \Illuminate\Support\Str::limit($p['addons'], 70) }}</div>@endif
                    @if ($p['discounts'])<div class="sub" style="font-size:12px">🏷️ {{ \Illuminate\Support\Str::limit($p['discounts'], 70) }}</div>@endif

                    <div style="margin-top:auto;padding-top:8px">
                        <a class="btn ghost xs" href="{{ route('m.show', ['plans', $p['id']]) }}">التفاصيل ←</a>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- المنافسون على الخدمة نفسها --}}
        @if (count($s['rivals']))
            <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--ln)">
                <b style="font-size:13px">⚔️ أين نقف — منافسون على الخدمة نفسها</b>
                <div class="tblwrap" style="margin-top:6px"><table class="tbl">
                    <thead><tr><th>المنافس</th><th>سعره</th><th>مجاني/تجربة</th><th>ميزتنا</th><th>ميزته</th></tr></thead>
                    <tbody>
                    @foreach ($s['rivals'] as $c)
                        <tr>
                            <td><a href="{{ route('m.show', ['competitors', $c['id']]) }}"><b>{{ $c['name'] }}</b></a>
                                @if ($c['pos'])<div class="sub">{{ \Illuminate\Support\Str::limit($c['pos'], 40) }}</div>@endif</td>
                            <td class="mono">
                                @if ($c['from'] === null && $c['to'] === null)<span class="sub">—</span>
                                @else{{ number_format((float) ($c['from'] ?? $c['to']), 0) }}@if ($c['to'] && $c['from'] && $c['to'] != $c['from']) — {{ number_format((float) $c['to'], 0) }}@endif
                                <div class="sub">{{ $c['currency'] ?: '' }} {{ $c['billing'] ?: '' }}</div>@endif
                            </td>
                            <td>
                                @if ($c['free'])<span class="bdg wn">باقة مجانية</span>@endif
                                @if ($c['trial'])<span class="bdg">تجربة {{ $c['trial'] }} يوماً</span>@endif
                                @if (! $c['free'] && ! $c['trial'])<span class="sub">—</span>@endif
                            </td>
                            <td class="sub">{{ \Illuminate\Support\Str::limit((string) $c['ourEdge'], 60) ?: '—' }}</td>
                            <td class="sub">{{ \Illuminate\Support\Str::limit((string) $c['theirEdge'], 60) ?: '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        @endif
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">💳</span>
        لا باقات بعد — أضِف باقةً واربطها بخدمةٍ ليظهر الكتالوج بهامشه ومقارنته.</div></div>
@endforelse

<div class="card">
    <div class="sub" style="line-height:2">
        <b>الهامش</b> = (السعر − تكلفة الوحدة) ÷ السعر — من حقل «التكلفة التقديرية» في الباقة نفسها،
        و<b>نطاق السوق</b> من أسعار المنافسين المرتبطين بالخدمة ذاتها.
        و<b>شعار المنتج</b> يُؤخذ من تطبيق مشروع الخدمة.<br>
        املأ «تكلفة الوحدة» و«السعر السابق» و«تاريخ تغيير السعر» و«السوق المستهدف»، واربط منافسيك بالخدمة —
        فكل حقلٍ منها يفتح قراراً في اللوحة أعلاه.
    </div>
</div>
