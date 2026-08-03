{{-- سطور القيد المزدوج — يتوقع $row (القيد). الإضافة والحذف على المسودة فقط --}}
@php
    $jlLines = \App\Models\JournalLine::where('entry_id', $row->id)->orderBy('created_at')->get();
    $jlAccounts = \App\Models\LedgerAccount::whereNull('deleted_at')->orderBy('code')->get(['id', 'code', 'name']);
    $jlNames = $jlAccounts->keyBy('id');
    $jlDebit = (float) $jlLines->sum('debit');
    $jlCredit = (float) $jlLines->sum('credit');
    $jlBalanced = $jlDebit > 0 && round($jlDebit, 3) === round($jlCredit, 3);
    $jlPosted = $row->state === 'مرحّل';
    $jlCanE = hub_can(auth()->user(), 'entries', 'e');
@endphp
<div class="card">
    <h3 class="cardtitle">📓 سطور القيد
        @if ($jlPosted)<span class="bdg ok">مُرحَّل ومقفل 🔏</span>@endif
    </h3>
    @if ($jlLines->isNotEmpty())
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الحساب</th><th>مدين</th><th>دائن</th><th>البيان</th>@if(!$jlPosted && $jlCanE)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($jlLines as $l)
                <tr>
                    <td>{{ $jlNames[$l->acc_id]->code ?? '' }} — {{ $jlNames[$l->acc_id]->name ?? '؟' }}</td>
                    <td class="mono">{{ $l->debit > 0 ? number_format($l->debit, 3) : '—' }}</td>
                    <td class="mono">{{ $l->credit > 0 ? number_format($l->credit, 3) : '—' }}</td>
                    <td class="sub">{{ $l->memo ?: '—' }}</td>
                    @if (! $jlPosted && $jlCanE)
                        <td><form method="POST" action="{{ route('entries.line.drop', [$row->id, $l->id]) }}" class="inline">
                            @csrf @method('DELETE')<button class="btn ghost xs">🗑</button></form></td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr>
                <th>الإجمالي</th>
                <th class="mono">{{ number_format($jlDebit, 3) }}</th>
                <th class="mono">{{ number_format($jlCredit, 3) }}</th>
                <th colspan="2"><span class="bdg {{ $jlBalanced ? 'ok' : 'bad' }}">{{ $jlBalanced ? 'موزون ✓' : 'غير موزون' }}</span></th>
            </tr></tfoot>
        </table></div>
    @else
        <div class="sub">لا سطور بعد — القيد رأسٌ بلا جسم حتى تضيف مدينه ودائنه.</div>
    @endif

    @if (! $jlPosted && $jlCanE)
        <form method="POST" action="{{ route('entries.line', $row->id) }}"
              style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:10px">
            @csrf
            <label><span class="sub">الحساب</span>
                <select class="inp" name="accId" required style="min-width:190px">
                    <option value="">— اختر —</option>
                    @foreach ($jlAccounts as $a)<option value="{{ $a->id }}">{{ $a->code }} — {{ $a->name }}</option>@endforeach
                </select></label>
            <label><span class="sub">مدين</span><input class="inp mono" type="number" step="any" min="0" name="debit" style="width:110px"></label>
            <label><span class="sub">دائن</span><input class="inp mono" type="number" step="any" min="0" name="credit" style="width:110px"></label>
            <label><span class="sub">البيان</span><input class="inp" name="memo" style="width:180px"></label>
            <button class="btn ghost sm">＋ سطر</button>
        </form>
        <form method="POST" action="{{ route('entries.post', $row->id) }}" style="margin-top:10px">
            @csrf
            <button class="btn" @disabled(! $jlBalanced)>🔏 رحّل القيد</button>
            @unless ($jlBalanced)<span class="sub">الترحيل يتطلب توازن المدين والدائن فوق الصفر.</span>@endunless
        </form>
    @endif
</div>
