{{-- (WP-9.4 · spec §7.11 · §7.12 · §35) **نقلُ الإعدادات بين التنصيبات.**

     تصديرٌ لا يحمل سرّاً ولا صفَّ حالة، واستيرادٌ **بخطوتين**: قراءةٌ وفرقٌ ووسمُ
     خطرٍ أولاً — بلا كتابةِ حرف — ثم تأكيدٌ يطبّق عبر الكاتب الواحد. وهذا غيرُ
     استعادةِ النسخة الكاملة (‏`php artisan hub:import`) ولا يُخلط بها. --}}
@php
    $frozen = (bool) (collect($runtimeFlags ?? [])->firstWhere('key', 'security.freeze_exports')['on'] ?? false);
    $plan = $importPlan ?? null;
@endphp

<div class="card" style="max-width:860px;margin-top:12px">
    <h3>📦 نقلُ الإعدادات بين التنصيبات</h3>

    {{-- ═ التصدير ═ --}}
    <div style="margin-top:8px">
        <b>التصدير</b>
        <p class="sub" style="line-height:1.9">
            ملفُّ JSON لما ضُبط فعلاً في هذا النظام (المفاتيحُ التي لها صفٌّ في القاعدة)،
            مختوماً بالنسخة وتاريخ التصدير. <b>لا يخرج فيه سرٌّ قطّ</b> — ولا قيمةٌ
            مشفَّرة: مفتاحٌ مشفَّرٌ بمفتاح تطبيقٍ آخر لا يُفَكّ هناك أصلاً، فيُذكر اسمُه
            لتضبطه من شاشته بعد النقل. ولا صفَّ حالةٍ تشغيلية (نبضاتُ المجدولات، وآخرُ
            نجاحِ التكاملات، والوضعُ التجريبي): نقلُها يكذب على مراقبة التنصيب الجديد
            من أول دقيقة.
        </p>
        @if ($frozen)
            <div class="sub" style="padding:8px 10px;border-radius:9px;border:1px solid var(--ln);
                        background:color-mix(in srgb, var(--wn) 10%, transparent)">
                ⚠️ <b>تجميدُ التصدير مرفوعٌ الآن</b> — كلُّ تصديرٍ مصدودٌ برمز ٤٢٣ حتى للمالك.
                يُنزَّل من <a href="{{ route('security.index') }}">مركز الأمان</a>.
            </div>
        @else
            <a class="btn" href="{{ route('settings.export') }}">⬇️ صدّر الإعدادات (JSON)</a>
        @endif
    </div>

    <hr style="border:none;border-top:1px solid var(--ln);margin:14px 0">

    {{-- ═ الاستيراد — الخطوة الأولى ═ --}}
    <div>
        <b>الاستيراد</b>
        <p class="sub" style="line-height:1.9">
            ارفع ملفَّ تصديرٍ من نظامٍ آخر. الرفعُ <b>يقرأ ويقارن ولا يكتب</b>: تُعرَض
            القيمةُ الحالية والقادمة لكل مفتاح، ويُوسَم عالي الخطورة، ويُذكر سببُ رفضِ
            كلِّ مفتاحٍ لا يُقبل. ثم تُطبَّق بزرِّ تأكيدٍ مستقلّ.
        </p>
        <form method="POST" action="{{ route('settings.import') }}" enctype="multipart/form-data" class="crow" style="gap:8px;flex-wrap:wrap">
            @csrf
            <input class="inp" type="file" name="file" accept=".json,application/json" style="flex:1;min-width:220px">
            <button class="btn">📤 اقرأ الملف واعرض الفرق</button>
        </form>
        @error('file')<div class="ferr" style="margin-top:6px">{{ $message }}</div>@enderror
        <div class="sub" style="margin-top:8px">
            واستعادةُ <b>نسخةٍ احتياطية كاملة</b> شيءٌ آخر تماماً (جداولُ المنشأة كلُّها):
            <span class="mono ltr">php artisan hub:import</span> — لا تُخلط بهذه.
        </div>
    </div>

    {{-- ═ الفرقُ والتأكيد — الخطوات ٣ إلى ٦ ═ --}}
    @if ($plan)
        <hr style="border:none;border-top:1px solid var(--ln);margin:14px 0">
        <div>
            <b>ملفٌّ مقروءٌ ينتظر تأكيدَك</b>
            <div class="crow" style="gap:6px;flex-wrap:wrap;margin:6px 0">
                <span class="bdg ok">{{ count($plan['ok']) }} سيتغيّر</span>
                <span class="bdg g">{{ count($plan['same']) }} بلا تغيير</span>
                <span class="bdg {{ count($plan['bad']) ? 'wn' : 'g' }}">{{ count($plan['bad']) }} مرفوض</span>
                @if (! empty($plan['risky']))<span class="bdg bad">فيه مفتاحٌ عالي الخطورة — يُطلب تأكيدُ هويتك</span>@endif
                @if (! empty($plan['from']['version']))
                    <span class="bdg g">من نسخة <bdi class="mono ltr">{{ $plan['from']['version'] }}</bdi></span>
                @endif
            </div>

            @if ($plan['ok'])
                <div class="tblwrap"><table class="tbl">
                    {{-- ترتيبُ الصفوف بالمفتاح من `Settings::importPlan` — لا فرزَ في المتحكّم فلا رأسَ قابلاً للفرز --}}
                    <thead><tr>
                        <th scope="col">المفتاح</th>
                        <th scope="col">الحالي</th>
                        <th scope="col">القادم</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($plan['ok'] as $row)
                        <tr>
                            <td>
                                {{ $row['label'] }}
                                @if ($row['risky'])<span class="bdg wn" title="عالي الخطورة — يُطلب تأكيدُ الهوية قبل التطبيق">⚠️</span>@endif
                                <div><bdi class="mono ltr sub" style="font-size:11px">{{ $row['key'] }}</bdi></div>
                            </td>
                            <td><bdi class="mono ltr">{{ $row['before'] === '' ? '— (على افتراضيّه)' : \Illuminate\Support\Str::limit($row['before'], 60) }}</bdi></td>
                            <td><bdi class="mono ltr">{{ \Illuminate\Support\Str::limit($row['after'], 60) }}</bdi></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>

                <form method="POST" action="{{ route('settings.import.apply') }}" style="margin-top:10px">
                    @csrf
                    <button class="btn p" data-confirm="تطبيقُ {{ count($plan['ok']) }} مفتاحاً على هذا النظام؟ تسري فوراً، ويُسجَّل كلُّ مفتاحٍ في تاريخ الإعدادات وسجل التدقيق.">
                        ✅ أكّد التطبيق
                    </button>
                </form>
            @else
                @include('partials.empty', ['icon' => '📄', 'text' => 'لا مفتاحَ في هذا الملف يغيّر شيئاً على هذا النظام'])
            @endif

            @if ($plan['bad'])
                <div style="margin-top:12px">
                    <b class="sub">ما رُفض ولماذا</b>
                    <div class="tblwrap"><table class="tbl">
                        <thead><tr><th scope="col">المفتاح</th><th scope="col">السبب</th></tr></thead>
                        <tbody>
                        @foreach ($plan['bad'] as $row)
                            <tr>
                                <td><bdi class="mono ltr">{{ $row['key'] }}</bdi></td>
                                <td class="sub">{{ $row['why'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </div>
            @endif
        </div>
    @endif
</div>
