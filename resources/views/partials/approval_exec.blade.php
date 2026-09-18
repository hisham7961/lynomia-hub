{{-- بطاقة تنفيذ العملية المحمية على صفحة الموافقة — يتوقع: $row (Approval) --}}
@php
    $tdef   = $row->mod ? hub_mod($row->mod) : null;
    $target = null; $diff = [];
    if ($tdef && $row->record_id) {
        $tc = '\\App\\Models\\' . $tdef['model'];
        $target = class_exists($tc) ? $tc::withTrashed()->find($row->record_id) : null;
        if ($target && $row->op === 'e') {
            foreach ($tdef['fields'] as $f) {
                if (! array_key_exists($f['key'], (array) $row->payload)) continue;
                $new = $row->payload[$f['key']];
                $cur = $target->{$f['col']} ?? null;
                if (is_array($cur)) $cur = implode('، ', $cur);
                if (is_array($new)) $new = implode('، ', $new);
                if (($f['type'] ?? '') === 'sec') { $cur = $cur ? '••••' : '—'; $new = '••••'; }
                if ((string) $cur === (string) $new) continue;
                $diff[] = ['label' => $f['label'], 'cur' => $cur, 'new' => $new];
            }
        }
    }
    $pending  = in_array($row->status, [null, '', 'معلّق'], true);
    $canJudge = hub_approver();
    $reqName  = $row->requested_by ? (\App\Models\User::find($row->requested_by)?->name ?? '—') : null;
@endphp
@if ($row->mod)
<div class="card">
    <h3>⚡ عملية محمية بانتظار الحسم</h3>
    <div class="crow" style="margin-bottom:10px">
        <span class="chip">{{ $row->op === 'd' ? '🗑 حذف' : '✏️ تعديل' }} في {{ $tdef['label'] ?? $row->mod }}</span>
        @if ($target)<a class="chip" href="{{ route('m.show', [$row->mod, $row->record_id]) }}">فتح السجل الهدف ←</a>@endif
        @if ($reqName)<span class="chip">طلبها: {{ $reqName }}</span>@endif
        @if ($row->decided_at)<span class="chip">حُسمت {{ $row->decided_at->diffForHumans() }}</span>@endif
    </div>

    @if ($row->op === 'e' && $diff)
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الحقل</th><th>القيمة الحالية</th><th>القيمة المطلوبة</th></tr></thead>
            <tbody>
                @foreach ($diff as $d)
                    <tr><td><b>{{ $d['label'] }}</b></td>
                        <td class="sub">{{ \Illuminate\Support\Str::limit((string) ($d['cur'] ?? '—'), 60) ?: '—' }}</td>
                        <td>{{ \Illuminate\Support\Str::limit((string) $d['new'], 60) }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
    @elseif ($row->op === 'e')
        <div class="sub">لا فروقات عن القيم الحالية</div>
    @elseif ($row->op === 'd' && $target)
        <div class="sub">سيُنقل السجل «{{ \Illuminate\Support\Str::limit((string) ($target->{hub_display_col($row->mod)} ?? $row->record_id), 50) }}» إلى السلة عند الاعتماد</div>
    @elseif (! $target)
        <div class="sub txt-bad">السجل الهدف لم يعد موجوداً</div>
    @endif

    @if ($pending && $canJudge && $target)
        @include('partials._approval_decide', ['row' => $row, 'verb' => '✓ اعتماد وتنفيذ',
                                               'confirm' => 'اعتماد العملية وتنفيذها الآن؟'])
    @endif
</div>
@else
{{--
    **طلبُ العمل** (شراءٌ · مصروفٌ · إجازة) — لا `mod` له ولا سجلَّ خلفه.

    كانت البطاقةُ كلُّها ملفوفةً بـ`@if ($row->mod)`، فبُنيت للعمليّةِ المحميّةِ
    وحدَها. والنتيجةُ أنّ مديراً يقرأ في صباحه «عمليات موقوفة لن تُنفَّذ قبل
    اعتمادك» يفتح البندَ فلا يجد زرّاً — والنماذجُ الوحيدةُ في الصفحة: خروجٌ
    وتعليقٌ وإرفاق. (المراجعةُ الشاملة · الطبقة ٢ · L2-01)

    والقرارُ هنا **قرارٌ لا تنفيذ**: لا سجلَّ يُعدَّل، وإنّما يُختَم القرارُ
    ويَبلغ صاحبَه فيرتفع الحجزُ عن العمل.
--}}
<div class="card">
    <h3>✋ طلبُ عملٍ بانتظار الحسم</h3>
    <div class="crow" style="margin-bottom:10px">
        @if ($row->type)<span class="chip">{{ $row->type }}</span>@endif
        @if ($row->amount !== null)
            <span class="chip">{{ number_format((float) $row->amount, 2) }}
                {{ $row->currency ?: setting('app.currency', 'د.ك') }}</span>
        @endif
        @if ($reqName)<span class="chip">طلبها: {{ $reqName }}</span>@endif
        @if ($row->due)<span class="chip">الاستحقاق: {{ $row->due->format('Y-m-d') }}</span>@endif
        @if ($row->decided_at)<span class="chip">حُسمت {{ $row->decided_at->diffForHumans() }}</span>@endif
    </div>
    @if ($row->reason)<div class="sub" style="margin-bottom:10px">{{ $row->reason }}</div>@endif

    @if ($pending && $canJudge)
        @include('partials._approval_decide', ['row' => $row, 'verb' => '✓ اعتماد',
                                               'confirm' => 'اعتماد هذا الطلب؟'])
    @elseif ($pending)
        <div class="sub">ينتظر قرارَ معتمِدٍ — ولستَ منهم.</div>
    @endif
</div>
@endif
