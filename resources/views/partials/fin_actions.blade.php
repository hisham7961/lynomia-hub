{{-- «سجّل دفعة» على المستند المالي — يتوقع $row. تظهر لغير المسدَّد ولمن يملك fin.e --}}
@php
    $finRemain = max(0, (float) ($row->total ?? 0) - (float) ($row->paid ?? 0));
    $finDead = in_array((string) $row->state, config('hub.fin.dead'), true);
    $finBanks = hub_can(auth()->user(), 'banks', 'v')
        ? hub_scope(\App\Models\BankAccount::query()->whereNull('deleted_at'), 'banks')->orderBy('name')->limit(50)->pluck('name', 'id')
        : collect();
@endphp
@if (! $finDead && $finRemain > 0 && hub_can(auth()->user(), 'fin', 'e'))
    <div class="card">
        <h3 class="cardtitle">💰 سجّل دفعة</h3>
        <div class="sub" style="margin-bottom:8px">
            المتبقي <b class="mono">{{ number_format($finRemain, 2) }} {{ $row->currency ?: setting('app.currency', 'د.ك') }}</b>
            من إجمالي <span class="mono">{{ number_format((float) ($row->total ?? 0), 2) }}</span>
            — الدفعة تنقل الحالة آلياً وتحرّك رصيد الحساب المختار.
        </div>
        <form method="POST" action="{{ route('fin.act', $row->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
            @csrf
            <input type="hidden" name="do" value="pay">
            <label style="display:block">
                <span class="sub">المبلغ</span>
                <input class="inp mono" type="number" step="any" min="0.001" max="{{ $finRemain }}"
                       name="amount" value="{{ $finRemain }}" required style="width:140px">
            </label>
            @if ($finBanks->isNotEmpty())
                <label style="display:block">
                    <span class="sub">الحساب البنكي / الصندوق</span>
                    <select class="inp" name="bankId" style="min-width:170px">
                        <option value="">— بلا حساب —</option>
                        @foreach ($finBanks as $bid => $bname)
                            <option value="{{ $bid }}" @selected($row->bank_id === $bid)>{{ $bname }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <button class="btn">💰 سجّل الدفعة</button>
        </form>
    </div>
@endif
