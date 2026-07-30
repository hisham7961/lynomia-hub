{{-- بطاقة التواقيع على صفحة العقد — طلبات هذا العقد وحالتها --}}
@php $sreqs = \App\Models\SignRequest::where('contract_id', $row->id)->orderByDesc('created_at')->get(); @endphp
<div class="card">
    <h3>✍️ التوقيع الإلكتروني
        <span class="spacer"></span>
        <a class="btn ghost xs" href="{{ route('esign.index') }}">＋ طلب توقيع</a></h3>
    @forelse ($sreqs as $q)
        <div style="display:flex;gap:9px;align-items:center;padding:6px 0;border-bottom:1px solid color-mix(in srgb,var(--ln) 45%,transparent)">
            <span class="bdg {{ $q->status === 'وُقّع' ? 'ok' : 'wn' }}">{{ $q->status }}</span>
            <b style="flex:1">{{ $q->title }}</b>
            @if ($q->signed_at)<span class="sub mono">{{ $q->signer_name }} · {{ $q->signed_at->format('m-d H:i') }} · {{ $q->signed_ip }}</span>@endif
            <a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}">📄</a>
        </div>
    @empty
        <div class="sub">لا طلبات توقيع لهذا العقد بعد</div>
    @endforelse
</div>
