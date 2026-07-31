{{-- تواقيع مرتبطة بهذه الجهة (عميل/موظف/شركة…) — تظهر على صفحة الكيان نفسه --}}
@php
    $lreqs = \App\Models\SignRequest::where('link_module', $module)->where('link_id', $row->id)
        ->orderByDesc('created_at')->get();
@endphp
@if ($lreqs->isNotEmpty() || hub_can(auth()->user(), 'contracts', 'a'))
<div class="card">
    <h3>✍️ التواقيع المرتبطة
        <span class="spacer"></span>
        @if (hub_can(auth()->user(), 'contracts', 'a'))<a class="btn ghost xs" href="{{ route('esign.index', ['link' => $module . ':' . $row->id, 'title' => hub_mod($module)['label'] . ' — ' . ($row->{hub_display_col($module)} ?? '')]) }}">＋ طلب توقيع</a>@endif</h3>
    @forelse ($lreqs as $q)
        <div style="display:flex;gap:9px;align-items:center;padding:6px 0;border-bottom:1px solid color-mix(in srgb,var(--ln) 45%,transparent)">
            <span class="bdg {{ $q->status === 'وُقّع' ? 'ok' : 'wn' }}">{{ $q->status }}</span>
            <b style="flex:1">{{ $q->title }}</b>
            @if ($q->signed_at)<span class="sub mono">{{ $q->signer_name }} · {{ $q->signed_at->format('m-d H:i') }}</span>@endif
            <a class="btn ghost xs" href="{{ route('esign.doc', $q->id) }}">📄</a>
        </div>
    @empty
        <div class="sub">لا تواقيع مرتبطة بعد</div>
    @endforelse
</div>
@endif
