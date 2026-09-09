{{-- ═══════════ الانتباه والإشارات (§19) ═══════════
     الإشاراتُ إليك (@) والردودُ على تعليقاتك — **من محرّكِ الإشعاراتِ القائم**
     (HubNotification بنوعِ mention/reply) لا محرّكٍ ثانٍ. فتحُ العنصرِ يقرأه وينقلك لسياقه. --}}
@php $kindGlyph = ['mention' => '💬', 'reply' => '↩️']; $kindLabel = ['mention' => 'إشارة إليك', 'reply' => 'ردٌّ على تعليقك']; @endphp

<div class="card cx-att">
    <div class="cx-hd" style="border-bottom:1px solid var(--ln)">
        <h2 style="margin:0;font-size:17px;flex:1">🔔 الانتباه والإشارات</h2>
        <span class="bdg g" title="على هذه الصفحة">{{ $attention->total() }}</span>
    </div>

    @forelse ($attention as $n)
        <a class="cx-attrow {{ $n->read ? '' : 'unr' }}" href="{{ route('notifications.go', $n->id) }}">
            <span class="cx-attg" aria-hidden="true">{{ $kindGlyph[$n->kind] ?? '🔔' }}</span>
            <span class="cx-attbody">
                <span class="cx-atttop">
                    <span class="bdg">{{ $kindLabel[$n->kind] ?? $n->kind }}</span>
                    <span class="sub" style="font-size:11px">{{ $n->created_at?->diffForHumans() }}</span>
                    @unless ($n->read)<span class="cx-attdot" title="غير مقروء" aria-label="غير مقروء"></span>@endunless
                </span>
                <span class="cx-atttext">{{ \Illuminate\Support\Str::limit($n->text, 160) }}</span>
            </span>
            <span class="cx-attgo" aria-hidden="true">←</span>
        </a>
    @empty
        <div class="empty" style="padding:36px 16px;text-align:center">
            <span class="big" style="font-size:40px">🔕</span>
            <div style="margin-top:8px">لا إشاراتٍ ولا ردودٍ بعد.</div>
            <div class="sub" style="margin-top:4px">حين يذكرك زميلٌ (@) أو يردّ على تعليقك، يظهر هنا.</div>
        </div>
    @endforelse

    @if ($attention->hasPages())
        <div style="padding:12px 14px">{{ $attention->links('partials.pagination_simple') }}</div>
    @endif
</div>

<style>
.cx-att { padding:0 }
.cx-attrow { display:flex; align-items:flex-start; gap:11px; padding:11px 14px; border-top:1px solid var(--ln);
             color:inherit; text-decoration:none }
.cx-attrow:first-of-type { border-top:0 }
.cx-attrow:hover { background:var(--pss) }
.cx-attrow.unr { background:color-mix(in srgb, var(--p) 6%, transparent) }
.cx-attg { flex:none; font-size:18px; line-height:1.6 }
.cx-attbody { flex:1; min-width:0 }
.cx-atttop { display:flex; align-items:center; gap:7px; margin-bottom:3px }
.cx-atttext { display:block; font-size:13.5px; line-height:1.75 }
.cx-attdot { width:8px; height:8px; border-radius:50%; background:var(--p); display:inline-block }
.cx-attgo { flex:none; color:var(--sb); align-self:center }
</style>
