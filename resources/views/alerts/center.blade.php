@extends('layouts.app')
@section('title', 'مركز التنبيهات')
@section('content')
{{-- (WP-6.3 · §9.3) حالةُ التنبيه لا سيلُ إشعاراته: صفٌّ واحد لكل شرطٍ حيّ —
     يظهر ويتكرّر ويُقرّ ويتعافى، وذاكرتُه تنجو من تقليم الإشعارات --}}
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز التنبيهات</b></nav>
        <h2>🔔 مركز التنبيهات</h2>
        <div class="sub">قواعدُ النافذة («X خلال Y دقيقة») بحالتها: مُطلقٌ → مُقَرٌّ به → تعافى — الإقرارُ يُسكِت الجرسَ والعدّادُ يستمرّ حتى يزول الشرط. رادارُ «ينتهي قريباً» شاشةٌ أخرى (<a href="{{ route('alerts') }}">التنبيهات الزمنية</a>)</div>
    </div>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'مُطلقة الآن', 'value' => $kpi['triggered'], 'tone' => $kpi['triggered'] ? 'bad' : 'ok'],
    ['label' => 'حرجة غير متعافية', 'value' => $kpi['critical'], 'tone' => $kpi['critical'] ? 'bad' : 'ok'],
    ['label' => 'مُقَرٌّ بها', 'value' => $kpi['acknowledged'], 'tone' => $kpi['acknowledged'] ? 'wn' : 'ok'],
    ['label' => 'تعافت خلال ٧ أيام', 'value' => $kpi['resolved7'], 'tone' => 'ok'],
]])

@php $acStatuses = ['triggered' => 'مُطلق', 'acknowledged' => 'مُقَرٌّ به', 'resolved' => 'تعافى']; @endphp
<div class="card">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🧾 التنبيهات</h3>
        <form method="GET" class="crow" style="gap:6px;flex-wrap:wrap">
            <label class="vh" for="st">تصفية بالحالة</label>
            <select class="inp" id="st" name="st" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                @foreach ($acStatuses as $acK => $acL)
                    <option value="{{ $acK }}" @selected($st === $acK)>{{ $acL }}</option>
                @endforeach
            </select>
            <label class="vh" for="sev">تصفية بالشدّة</label>
            <select class="inp" id="sev" name="sev" onchange="this.form.submit()">
                <option value="">كل الشدّات</option>
                @foreach (\App\Support\Severity::LEVELS as $acLv)
                    <option value="{{ $acLv }}" @selected($sev === $acLv)>{{ \App\Support\Severity::LABELS[$acLv] }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="sub" style="margin:4px 0 10px">العدّادُ «×N» = كم مرّة رُصد الشرطُ منذ أول ظهورٍ في هذه النوبة؛ والتعافي آليٌّ حين يزول الشرطُ في التقييم التالي (كلَّ ٥ دقائق) — بقيدٍ لا بحذف.</div>

    <div class="tblwrap">
        <table class="tbl">
            <thead><tr>
                <th scope="col">الشدّة</th>
                <th scope="col">التنبيه</th>
                <th scope="col">الحالة</th>
                <th scope="col">آخر رصد</th>
                <th scope="col" class="acts"></th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $ai)
                <tr>
                    <td>
                        <span class="bdg {{ \App\Support\Severity::tone($ai->severity) }}">{{ \App\Support\Severity::label($ai->severity) }}</span>
                        @if ((int) $ai->count > 1)<span class="bdg g" title="مرات الرصد">×{{ (int) $ai->count }}</span>@endif
                    </td>
                    <td>
                        <b>{{ $ai->title }}</b>
                        <div class="sub">
                            @if ($ai->rule_id && isset($ruleNames[$ai->rule_id]))القاعدة: {{ $ruleNames[$ai->rule_id] }} · @endif
                            @if ($ai->subject)الموضوع: <bdi class="mono ltr">{{ $ai->subject }}</bdi> · @endif
                            منذ {{ \Illuminate\Support\Carbon::parse($ai->first_at)->diffForHumans() }}
                            @if ($ai->acknowledged_at) · أُقرّ {{ \Illuminate\Support\Carbon::parse($ai->acknowledged_at)->diffForHumans() }}@endif
                            @if ($ai->resolved_at) · تعافى {{ \Illuminate\Support\Carbon::parse($ai->resolved_at)->diffForHumans() }}@endif
                        </div>
                    </td>
                    <td><span class="bdg {{ ['triggered' => 'bad', 'acknowledged' => 'wn', 'resolved' => 'ok'][$ai->status] ?? 'g' }}">{{ $acStatuses[$ai->status] ?? $ai->status }}</span></td>
                    <td>{{ \Illuminate\Support\Carbon::parse($ai->last_at)->diffForHumans() }}</td>
                    <td class="acts">
                        @if (! empty($isOwner))
                            @if ($ai->status === 'triggered')
                                <form method="POST" action="{{ route('alerts.ack', $ai->id) }}" style="display:inline">@csrf
                                    <button class="btn ghost xs" type="submit" title="يُسكِت الجرسَ ويُبقي العدّاد">👁️ إقرار</button>
                                </form>
                            @endif
                            @if ($ai->incident_id)
                                <a class="btn ghost xs" href="{{ route('m.show', ['incidents', $ai->incident_id]) }}">🚨 الحادثة</a>
                            @elseif ($ai->status !== 'resolved')
                                <form method="POST" action="{{ route('alerts.incident', $ai->id) }}" style="display:inline">@csrf
                                    <button class="btn ghost xs" type="submit">🚨 افتح حادثة</button>
                                </form>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                @include('partials.empty', ['colspan' => 5, 'icon' => '🔕',
                         'text' => ($st !== '' || $sev !== '') ? 'لا تنبيهات بهذه التصفية' : 'لا تنبيهات نافذية بعد — تُكتب حين تُطلِق قاعدةٌ ذاتُ مصدرٍ مسمّى (تُنشأ من وحدة «قواعد التنبيه» بحقلَي المصدر والنافذة)، والتقييمُ كلَّ ٥ دقائق'])
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
