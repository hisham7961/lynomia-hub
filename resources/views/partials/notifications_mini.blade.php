@if ($items->isEmpty())
    <div class="palitem sub" style="justify-content:center;padding:16px">لا تنبيهات بعد 🎉</div>
@else
    @foreach ($items as $n)
        @php $url = ($n->module && $n->record_id && hub_mod($n->module)) ? route('m.show', [$n->module, $n->record_id]) : null; @endphp
        <a class="palitem" @if($url) href="{{ $url }}" @else href="javascript:void 0" @endif style="{{ $n->read ? 'opacity:.62' : '' }}">
            @unless ($n->read)<span style="width:7px;height:7px;border-radius:50%;background:var(--p);flex-shrink:0"></span>@endunless
            <span style="flex:1;font-size:12.5px;line-height:1.5">{{ $n->text }}<span class="sub" style="display:block;font-size:10.5px">{{ $n->created_at?->diffForHumans() }}</span></span>
        </a>
    @endforeach
    <button class="palitem sub" type="button" style="width:100%;border:0;background:none;cursor:pointer;border-top:1px dashed var(--ln);justify-content:center"
            hx-post="{{ route('notifications.readall') }}" hx-target="#bellbox" hx-swap="innerHTML">✓ تحديد الكل كمقروء</button>
@endif
<span id="bellbadge" hx-swap-oob="true">@php $nc = \App\Models\HubNotification::where('user_id', auth()->id())->where('read', false)->count(); @endphp@if($nc)<span class="nbdg">{{ $nc }}</span>@endif</span>
