{{-- بنود المسيّر وإجراءاته — يتوقع $row (المسيّر) --}}
@php
    $plLines = \App\Models\PayrollLine::where('run_id', $row->id)->orderBy('id')->get();
    // **منطَّقة**: كانت تقرأ أسماءَ الموظفين خاماً، فقارئٌ معزولٌ بشركةٍ يرى
    // أسماءَ موظفي شركةٍ أخرى في بنود المسيّر (v2.321)
    $plNames = hub_scope(\App\Models\Employee::query(), 'hr')
        ->whereIn('id', $plLines->pluck('emp_id'))->pluck('name', 'id');
    $plDraft = $row->status === 'مسودة' || blank($row->status);
    $plCanE = hub_can(auth()->user(), 'payroll', 'e');
    $plCur = $row->currency ?: setting('app.currency', 'د.ك');
@endphp
<div class="card">
    <h3 class="cardtitle">🧾 بنود المسيّر
        @unless ($plDraft)<span class="bdg {{ $row->status === 'مدفوع' ? 'ok' : 'wn' }}">{{ $row->status }} 🔏</span>@endunless
    </h3>
    @php $plEdit = $plDraft && $plCanE; @endphp
    @if ($plLines->isNotEmpty())
        {{-- في المسودة: خانات إضافي/خصم/سلف قابلة للإدخال — الصافي يُعاد حسابه عند الحفظ --}}
        @if ($plEdit)<form method="POST" action="{{ route('payroll.act', $row->id) }}">@csrf<input type="hidden" name="do" value="adjust">@endif
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الموظف</th><th>الأساسي</th><th>البدلات</th><th>إضافي</th><th>خصومات</th><th>سلف</th><th>الصافي</th></tr></thead>
            <tbody>
            @foreach ($plLines as $l)
                <tr>
                    <td>{{ $plNames[$l->emp_id] ?? '؟' }}</td>
                    <td class="mono">{{ number_format($l->base, 2) }}</td>
                    <td class="mono">{{ number_format($l->allow, 2) }}</td>
                    @if ($plEdit)
                        <td><input class="inp mono" type="number" step="any" min="0" style="width:90px"
                            name="ot[{{ $l->id }}]" value="{{ (float) $l->overtime ?: '' }}" placeholder="0"></td>
                        <td><input class="inp mono" type="number" step="any" min="0" style="width:90px"
                            name="de[{{ $l->id }}]" value="{{ (float) $l->deduct ?: '' }}" placeholder="0"></td>
                        <td><input class="inp mono" type="number" step="any" min="0" style="width:90px"
                            name="ad[{{ $l->id }}]" value="{{ (float) $l->advance ?: '' }}" placeholder="0"></td>
                    @else
                        <td class="mono">{{ $l->overtime ? number_format($l->overtime, 2) : '—' }}</td>
                        <td class="mono">{{ $l->deduct ? number_format($l->deduct, 2) : '—' }}</td>
                        <td class="mono">{{ $l->advance ? number_format($l->advance, 2) : '—' }}</td>
                    @endif
                    <td class="mono"><b>{{ number_format($l->net, 2) }}</b></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><th>الإجمالي ({{ $plLines->count() }} موظفاً)</th><th colspan="5"></th>
                <th class="mono">{{ number_format((float) $plLines->sum('net'), 2) }} {{ $plCur }}</th></tr></tfoot>
        </table></div>
        @if ($plEdit)<button class="btn ghost sm" style="margin-top:8px">💾 حفظ التعديلات وإعادة حساب الصافي</button></form>@endif
    @else
        <div class="sub" style="margin-bottom:8px">لا بنود بعد — «توليد المسيّر» يبني سطراً لكل موظفٍ نشط من راتبه وبدلاته.</div>
    @endif

    @if ($plCanE)
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            @if ($plDraft)
                <form method="POST" action="{{ route('payroll.act', $row->id) }}" class="inline">
                    @csrf<input type="hidden" name="do" value="generate">
                    <button class="btn">⚙️ {{ $plLines->isEmpty() ? 'توليد المسيّر' : 'إعادة التوليد' }}</button>
                </form>
                @if ($plLines->isNotEmpty())
                    <form method="POST" action="{{ route('payroll.act', $row->id) }}" class="inline">
                        @csrf<input type="hidden" name="do" value="approve">
                        <button class="btn ghost">✅ اعتماد</button>
                    </form>
                @endif
            @elseif ($row->status === 'معتمد')
                <form method="POST" action="{{ route('payroll.act', $row->id) }}" class="inline">
                    @csrf<input type="hidden" name="do" value="pay">
                    <button class="btn">💸 سُجّل الصرف</button>
                </form>
            @endif
        </div>
    @endif
</div>
