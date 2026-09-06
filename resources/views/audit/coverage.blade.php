@extends('layouts.app')
@section('title', 'محلّل تغطية التدقيق')
@section('content')
@include('partials.pagehead', ['icon' => '🧭', 'title' => 'محلّل تغطية التدقيق', 'crumb' => 'النظام',
    'sub' => 'هل ثمّة عملياتٌ مهمّة بلا تدقيق؟ — سجلُّ الوحدات × سمة Auditable × الكتالوج الأمنيّ × مواضعُ الكتابة الفعلية'])

{{-- (WP-5.5 · §1.7) القاعدةُ الصارمة: لا يقينَ مزيَّفاً — المسحُ النصّيّ يُثبت
     وجودَ موضعِ كتابةٍ حرفيّ ولا يُثبت تنفيذَه، فما لا يُثبَت يُقال «يحتاج مراجعة» --}}
<div class="cards" style="margin-bottom:12px">
    <div class="kpi">
        <div class="lbl">📦 وحداتٌ مغطّاة CRUD</div>
        <div class="val">{{ $report['modules']['audited'] }}/{{ $report['modules']['total'] }}</div>
        <div class="sub">سمةُ Auditable تكتب الإضافة والتعديل والحذف آلياً</div>
    </div>
    <div class="kpi">
        <div class="lbl">✅ صيغٌ مثبَتة حرفياً</div>
        <div class="val">{{ $report['counts']['proven'] }}</div>
        <div class="sub">موضعُ كتابةٍ حرفيّ وُجد في app/</div>
    </div>
    <div class="kpi">
        <div class="lbl">🧬 صيغٌ مشتقّة بنيوياً</div>
        <div class="val">{{ $report['counts']['derived'] }}</div>
        <div class="sub">سمةُ وحدةٍ أو كاتبٌ آخر — إثباتُ بنيةٍ لا صيغة</div>
    </div>
    <div class="kpi {{ $report['counts']['review'] > 0 ? 'wn' : '' }}">
        <div class="lbl">🔍 يحتاج مراجعة</div>
        <div class="val">{{ $report['counts']['review'] }}</div>
        <div class="sub">لا إثباتَ نصّياً — تُراجَع بعينٍ بشرية لا تُدّعى</div>
    </div>
</div>

@if (count($report['modules']['missing']))
    <div class="card" style="border-color:var(--bad);margin-bottom:12px">
        <b>⚠️ وحداتٌ بلا سمة Auditable — CRUD فيها بلا أثر:</b>
        <div class="sub" style="margin-top:4px">{{ implode('، ', $report['modules']['missing']) }}</div>
    </div>
@endif

{{-- الثغراتُ المعلومة — حالُها يُفحص من المصدر لا يُفترض: إغلاقُها لاحقاً يقلبها هنا بلا تحرير --}}
<div class="card" style="margin-bottom:12px">
    <h3>🕳️ الثغراتُ المعروفة — بصدقٍ لا تجميل</h3>
    <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px">
        @foreach ($report['gaps'] as $g)
            <div>
                <span class="bdg {{ ['closed' => 'ok', 'partial' => 'wn', 'open' => 'bad'][$g['state']] ?? '' }}">{{ ['closed' => 'مغلقة', 'partial' => 'جزئية', 'open' => 'مفتوحة'][$g['state']] ?? $g['state'] }}</span>
                <b>{{ $g['title'] }}</b>
                <div class="sub">{{ $g['note'] }}</div>
            </div>
        @endforeach
    </div>
</div>

{{-- خريطةُ الكتالوج الأمنيّ: كلُّ كودٍ بصيغه ودرجةِ إثبات كلٍّ منها --}}
<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th scope="col">الكود</th><th scope="col">الحدث</th><th scope="col">الشدّة</th>
            <th scope="col">الصيغ ودليلُ تغطيتها</th>
        </tr></thead>
        <tbody>
        @forelse ($report['events'] as $code => $e)
            <tr>
                <td class="mono ltr" style="font-size:11px"><bdi class="mono ltr">{{ $code }}</bdi>
                    <div style="margin-top:2px"><span class="bdg {{ ['proven' => 'ok', 'derived' => '', 'review' => 'wn'][$e['status']] }}">{{ ['proven' => 'مغطّى', 'derived' => 'مشتق', 'review' => 'يحتاج مراجعة'][$e['status']] }}</span></div>
                </td>
                <td>{{ $e['label'] }}</td>
                <td><span class="bdg {{ \App\Support\SecurityEvents::SEVERITY_TONE[$e['severity']] ?? '' }}">{{ $e['severity'] }}</span></td>
                <td>
                    <div style="display:flex;flex-direction:column;gap:3px">
                        @foreach ($e['actions'] as $a)
                            <div class="sub" style="font-size:12px">
                                <span class="bdg {{ ['proven' => 'ok', 'derived' => '', 'review' => 'wn'][$a['status']] }}" style="font-size:10px">{{ ['proven' => 'مغطّى', 'derived' => 'مشتق', 'review' => 'يحتاج مراجعة'][$a['status']] }}</span>
                                <b>{{ $a['action'] }}</b> — {{ $a['why'] }}
                            </div>
                        @endforeach
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="empty"><span class="big">🧭</span>لا كتالوجَ أمنياً — SecurityEvents::CODES فارغ</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="card" style="margin-top:12px">
    <h3>كيف يُقرأ هذا التحليل؟</h3>
    <div class="sub" style="line-height:2">
        <b>مغطّى</b>: صيغةُ الفعل لها موضعُ كتابةٍ <b>حرفيّ</b> في الشيفرة — إثباتُ وجودٍ لا إثباتُ تنفيذ.
        <b>مشتق</b>: التغطية بنيوية (سمةُ Auditable تكتب CRUD الوحدة، أو رادارُ المنع يكتب جدولَه) لا صيغةً حرفية.
        <b>يحتاج مراجعة</b>: المسحُ النصّيّ لا يُثبتها — قد يُبنى الفعلُ من متغيّرٍ وقد لا يُكتب أصلاً؛ تُراجَع بعينٍ بشرية.<br>
        فئاتُ النطاق (حذف/تصدير/مالية/…) تُشتقّ لكل قيدٍ كتابةً وقراءةً (WP-5.2) فلا تُعدّ ثغرات.
        والاحتفاظ: <b>{{ $retention['days'] > 0 ? $retention['days'] . ' يوماً (وصفاً معلَناً)' : 'للأبد' }}</b>
        — {{ $retention['policy'] !== '' ? $retention['policy'] : 'لا كودَ تقليمٍ لسجل التدقيق: المفتاح وصفُ سياسةٍ لا مقصّ' }}.
    </div>
</div>
@endsection
