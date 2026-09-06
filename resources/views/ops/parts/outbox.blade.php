    {{-- (WP-2.7 · §25) الصندوق الصادر بقارئٍ ملفوف: $outbox تصل null حين يسقط الجدول —
         فالقسم يقول «غير متاح» بلا أصفارٍ كاذبة، وحالةُ المكوّن الحرجة تبقى في ترويسة الصحّة. --}}
    <div class="card kid">
        <h3>📨 طوابير الرسائل الصادرة</h3>
        @if (is_null($outbox))
            <div class="sub">⛔ غير متاح — تعذّرت قراءة الصندوق الصادر (جدولٌ غائب أو قاعدةٌ متعثّرة)؛
                حكمُ المكوّن الحقيقي في ترويسة الصحّة أعلاه.</div>
        @else
            <div class="tblwrap"><table class="mini">
                @forelse ($outbox as $state => $c)
                    <tr><td>{{ ['queued' => 'بالانتظار', 'sending' => 'قيد الإرسال', 'sent' => 'أُرسلت', 'failed' => 'فاشلة'][$state] ?? $state }}</td>
                        <td class="acts"><span class="bdg {{ $state === 'failed' ? 'bad' : ($state === 'queued' ? 'wn' : 'ok') }}">{{ $c }}</span></td></tr>
                @empty
                    <tr><td class="sub" style="padding:10px">الصف فارغ</td></tr>
                @endforelse
            </table></div>

            {{-- (WP-2.6) أرقامُ التشغيل عبر OpsController::outboxOps — عدٌّ في القاعدة؛
                 (WP-2.7) مخبّأةٌ ٦٠ ثانية خلف hub_screen بختم جدول outbox — وإعادةُ
                 الإرسال تبصم الختمَ (hub_data_bump) فتُبطلها فوراً. راية ok تمنع
                 الأصفارَ الكاذبة فوق جدولٍ متعثّر. --}}
            @php
                $obW = hub_screen('ops.outbox', 60,
                    fn () => \App\Http\Controllers\Web\OpsController::outboxOps(), ['outbox'], true);
                $ob = $obW['data'];
            @endphp
            @if (empty($ob['ok']))
                <div class="sub" style="margin-top:6px">⛔ أرقامُ تشغيل الصادر غير متاحة الآن.</div>
            @else
            <div class="tblwrap"><table class="mini" id="ob-stats" style="margin-top:6px">
                <tr><td>أقدمُ منتظرة</td>
                    <td class="acts sub">{{ $ob['oldest'] ? \Illuminate\Support\Carbon::parse($ob['oldest'])->diffForHumans() : 'لا رسائل منتظرة' }}</td></tr>
                <tr><td>أحدثُ منتظرة</td>
                    <td class="acts sub">{{ $ob['newest'] ? \Illuminate\Support\Carbon::parse($ob['newest'])->diffForHumans() : '—' }}</td></tr>
                <tr><td>المُسلَّم (٢٤ ساعة)</td>
                    <td class="acts">{{ (int) $ob['delivered']['cur'] }}
                        <span class="sub">· {{ $ob['per_hour'] ?? '—' }}/ساعة{{ $ob['delivered']['pct'] !== null ? ' · ' . ($ob['delivered']['pct'] >= 0 ? '+' : '') . $ob['delivered']['pct'] . '٪ عن النافذة السابقة' : '' }}</span></td></tr>
                <tr><td>فاشلةٌ أُنشئت خلال ٢٤ ساعة</td>
                    <td class="acts">@if ($ob['failed_recent'])<span class="bdg bad">{{ $ob['failed_recent'] }}</span> <span class="sub">· {{ $ob['fail_per_hour'] }}/ساعة</span>@else 0 @endif</td></tr>
                @if ($ob['attempts_max'] !== null)
                    <tr><td>محاولاتُ الفاشلة</td>
                        <td class="acts sub">متوسط {{ $ob['attempts_avg'] ?? '—' }} · أقصى {{ $ob['attempts_max'] }}</td></tr>
                @endif
            </table></div>

            {{-- معاينةُ الفشل: النصُّ محجوبٌ لأنواع رموز التحقّق والوجهةُ مقنَّعة دائماً —
                 رمزُ OTP على شاشةٍ هو تجاوزُ قناة التحقّق نفسِها (WP-2.6) --}}
            @if ($ob['failed']->count())
                <div class="sub" style="margin-top:8px"><b>🚨 آخر الفاشلات</b> — أحدث {{ $ob['failed']->count() }}، الوجهةُ مقنَّعة والإعادةُ بتأكيد هوية:</div>
                <div class="tblwrap"><table class="mini">
                    @foreach ($ob['failed'] as $f)
                        <tr>
                            <td>{{ ['tg' => '✈️', 'mail' => '📧'][$f->channel] ?? $f->channel }}
                                <span class="sub">{{ $f->kind }} · إلى <bdi class="mono ltr">{{ \App\Support\Integrations::maskDestination($f->target) }}</bdi>
                                    · {{ $f->created_at?->diffForHumans() }}</span>
                                <div class="sub">{{ \App\Support\Integrations::outboxPreview($f->kind, $f->text) }}</div>
                                @if ($f->error)<div class="ferr">{{ $f->error }}</div>@endif</td>
                            <td class="acts">
                                <form method="POST" action="{{ route('ops.outbox.retry', $f->id) }}">@csrf
                                    <button class="btn ghost xs" title="تُعاد إلى الطابور ويُسلِّمها العامل نفسُه فوراً">♻️ إعادة</button></form>
                            </td>
                        </tr>
                    @endforeach
                </table></div>
            @endif
            @include('partials.cc.freshness', ['at' => $obW['at'], 'ttl' => 60])
            @endif

            <div class="sub" style="margin-top:6px">الفاشلة كلُّها تعاد بأمر <span class="mono ltr">php artisan hub:outbox --retry</span></div>
        @endif
    </div>
