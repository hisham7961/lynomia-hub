@extends('layouts.app')
@section('title', 'مركز الإشعارات')
@section('content')
<div class="toolbar">
    <a class="btn {{ $unread ? 'ghost' : '' }} sm" href="{{ route('notifications.index') }}">الكل</a>
    <a class="btn {{ $unread ? '' : 'ghost' }} sm" href="{{ route('notifications.index', ['unread' => 1]) }}">غير المقروء</a>
    <div class="spacer"></div>
    <form method="POST" action="{{ route('notifications.readall') }}"
          hx-post="{{ route('notifications.readall') }}" hx-target="#bellbox" hx-swap="innerHTML"
          hx-on::after-request="location.reload()">
        @csrf<button class="btn ghost sm" type="submit">✓ تحديد الكل كمقروء</button>
    </form>
</div>

<div class="card pad0">
    @forelse ($items as $n)
        @php $url = ($n->module && $n->record_id && hub_mod($n->module)) ? route('m.show', [$n->module, $n->record_id]) : null; @endphp
        <a class="gitem" @if($url) href="{{ hub_safe_url($url) }}" @else href="javascript:void 0" @endif
           style="padding:12px 16px;{{ $n->read ? 'opacity:.62' : '' }}">
            @unless ($n->read)<span style="width:8px;height:8px;border-radius:50%;background:var(--p);flex-shrink:0"></span>@endunless
            <span style="flex:1;line-height:1.6">{{ $n->text }}
                <span class="sub" style="display:block;font-size:11px">
                    {{ $n->created_at?->diffForHumans() }}
                    @if ($n->module && hub_mod($n->module)) · {{ hub_mod($n->module)['label'] }} @endif
                </span>
            </span>
        </a>
    @empty
        <div class="sub" style="padding:28px;text-align:center">{{ $unread ? 'لا إشعارات غير مقروءة 🎉' : 'لا إشعارات بعد' }}</div>
    @endforelse
</div>

@include('partials.pagination', ['paginator' => $items])
@endsection
