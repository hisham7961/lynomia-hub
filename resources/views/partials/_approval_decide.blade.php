{{-- أزرارُ الحسم — نسخةٌ واحدةٌ تستعملها العمليّةُ المحميّةُ وطلبُ العمل معاً --}}
<div class="crow" style="margin-top:12px">
    <form method="POST" action="{{ route('approvals.approve', $row->id) }}"
          data-confirm="{{ $confirm ?? 'اعتماد الطلب؟' }}">
        @csrf<button class="btn p sm" type="submit">{{ $verb ?? '✓ اعتماد' }}</button>
    </form>
    <form method="POST" action="{{ route('approvals.reject', $row->id) }}" style="display:flex;gap:6px">
        @csrf
        <input class="inp" name="note" placeholder="سبب الرفض (اختياري)" style="max-width:220px">
        <button class="btn ghost sm" type="submit">✕ رفض</button>
    </form>
</div>
