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
        <div class="crow" style="margin-top:12px">
            <form method="POST" action="{{ route('approvals.approve', $row->id) }}"
                  data-confirm="اعتماد العملية وتنفيذها الآن؟">
                @csrf<button class="btn p sm" type="submit">✓ اعتماد وتنفيذ</button>
            </form>
            <form method="POST" action="{{ route('approvals.reject', $row->id) }}" style="display:flex;gap:6px">
                @csrf
                <input class="inp" name="note" placeholder="سبب الرفض (اختياري)" style="max-width:220px">
                <button class="btn ghost sm" type="submit">✕ رفض</button>
            </form>
        </div>
    @endif
</div>
@endif
