@extends('layouts.app')
@section('title', 'مركز التواصل')
@section('content')
@php
    use App\Models\Conversation;
    $roleLabels = ['owner' => 'مالك', 'moderator' => 'مشرف', 'member' => 'عضو', 'guest' => 'ضيف'];
    $presenceLabel = ['online' => 'متصل الآن', 'recent' => 'نشطٌ حديثاً', 'away' => 'بعيد', 'offline' => 'غير متصل'];
    $presenceDot = ['online' => 'var(--ok)', 'recent' => '#f0ad4e', 'away' => 'var(--ln)', 'offline' => 'transparent'];
    $selType = $selected['type'] ?? null;
    $selId   = $selected['id'] ?? null;
    $isActive = fn ($type, $id) => $selType === $type && (string) $selId === (string) $id;
@endphp

<div class="collab" style="display:grid;grid-template-columns:270px minmax(0,1fr) 300px;gap:14px;align-items:start">

    {{-- ═══════════ اللوحُ اليسار: السكّة (البدءُ في RTL) ═══════════ --}}
    <aside class="card pad0 cx-rail" aria-label="قوائمُ التواصل" style="position:sticky;top:12px;max-height:calc(100vh - 24px);overflow:auto">
        <div style="padding:12px 13px;border-bottom:1px solid var(--ln);display:flex;align-items:center;gap:8px">
            <h2 style="font-size:16px;margin:0;flex:1">💬 مركز التواصل</h2>
            @if (($rail['unreadTotal'] ?? 0) > 0)
                <span class="nbdg" title="غير مقروء">{{ $rail['unreadTotal'] }}</span>
            @endif
        </div>

        {{-- روابطُ فعلٍ سريعة — تصل الوجهاتِ القائمةَ لا تكرّرها --}}
        <nav class="cx-quick" aria-label="روابط سريعة" style="display:flex;flex-wrap:wrap;gap:5px;padding:9px 11px;border-bottom:1px solid var(--ln)">
            <a class="btn ghost xs" href="{{ route('conversations.directory') }}">➕ اكتشف قناة</a>
            <a class="btn ghost xs" href="{{ route('groups.index') }}">👥 مجموعة</a>
            <a class="btn ghost xs" href="{{ route('dm.inbox') }}">✉️ رسالة</a>
            <a class="btn ghost xs" href="{{ route('search.messages') }}">🔎 بحث</a>
            <a class="btn ghost xs" href="{{ route('saved.index') }}">🔖 المحفوظات</a>
        </nav>

        @php
            $section = function ($title, $icon, $items) use ($isActive, $presenceDot) {
                if (! count($items)) return;
                echo '<div class="cx-sec"><div class="cx-sech">' . $icon . ' ' . e($title) . '</div>';
                foreach ($items as $it) {
                    // الغرفةُ والقناةُ كلتاهما تُختاران بـ ?c= (النوعُ في التحديد channel)؛
                    // المجموعةُ group؛ المحادثةُ dm بمعرّفِ الطرف.
                    $active = $it['kind'] === 'dm'
                        ? $isActive('dm', $it['other_id'])
                        : $isActive($it['kind'] === 'group' ? 'group' : 'channel', $it['id'] ?? null);
                    echo '<a class="cx-row' . ($active ? ' on' : '') . '" href="' . e($it['url']) . '"' . ($active ? ' aria-current="page"' : '') . '>';
                    if ($it['kind'] === 'dm') {
                        $dot = $presenceDot[$it['presence']] ?? 'transparent';
                        echo '<span class="cx-ava">' . e(mb_substr($it['title'], 0, 1)) . '<span class="cx-dot" style="background:' . $dot . '"></span></span>';
                    } else {
                        $glyph = $it['kind'] === 'group' ? '👥' : ($it['kind'] === 'room' ? '📁' : '#');
                        echo '<span class="cx-gl">' . $glyph . '</span>';
                    }
                    echo '<span class="cx-tt">' . e($it['title']);
                    // شارةُ جمهورِ الغرفة — تمييزٌ لا يُخطَأ بين الداخليّ والعميل (المرحلة ٧)
                    if ($it['kind'] === 'room') {
                        $client = in_array($it['audience'] ?? '', ['client', 'both'], true);
                        echo '<span class="cx-aud ' . ($client ? 'cl' : 'in') . '">' . ($client ? '👥 عميل' : '🔒 داخليّة') . '</span>';
                    }
                    echo '</span>';
                    if (($it['unread'] ?? 0) > 0) echo '<span class="nbdg">' . (int) $it['unread'] . '</span>';
                    echo '</a>';
                }
                echo '</div>';
            };
            // «غير المقروء» مشتقٌّ — عرضٌ فقط (لا عزلٌ ثانٍ)
            $unreadItems = array_values(array_filter(
                array_merge($rail['channels'], $rail['rooms'], $rail['groups'], $rail['dms']),
                fn ($i) => ($i['unread'] ?? 0) > 0
            ));
        @endphp

        @php
            $section('المفضّلة', '⭐', $rail['favorites']);
            $section('غير المقروء', '🔵', $unreadItems);
            $section('القنوات', '#️⃣', $rail['channels']);
            $section('غرفُ المشاريع', '📁', $rail['rooms']);
            $section('المجموعات', '👥', $rail['groups']);
            $section('المحادثاتُ المباشرة', '✉️', $rail['dms']);
        @endphp

        @if (! count($rail['channels']) && ! count($rail['rooms']) && ! count($rail['groups']) && ! count($rail['dms']))
            <div class="empty" style="padding:24px 12px"><span class="big">💬</span>
                لا محادثاتٍ بعد — اكتشف قناةً أو ابدأ رسالة</div>
        @endif
    </aside>

    {{-- ═══════════ اللوحُ الأوسط: الخيطُ المختار ═══════════ --}}
    <main class="cx-center" aria-label="المحادثة" style="min-width:0">
        @if ($selType === null)
            @include('collaboration._empty')
        @elseif ($selType === 'dm')
            @include('collaboration._dm')
        @else
            @include('collaboration._conversation')
        @endif
    </main>

    {{-- ═══════════ اللوحُ اليمين: السياق (النهايةُ في RTL) ═══════════ --}}
    <aside class="card cx-ctx" aria-label="السياق" style="position:sticky;top:12px;max-height:calc(100vh - 24px);overflow:auto">
        @if ($selType === null)
            <div class="sub" style="padding:8px 2px">اختر محادثةً لعرضِ سياقها هنا — المشاركون والمثبّتاتُ والملفّات.</div>
        @elseif ($selType === 'dm')
            @include('collaboration._ctx_dm')
        @else
            @include('collaboration._ctx_conversation')
        @endif
    </aside>
</div>

<style>
.cx-center { min-width:0 }
.cx-quick { display:flex; flex-wrap:wrap; gap:5px }
.cx-pin:hover { background:var(--pss) }
.cx-sec { padding:6px 0 8px }
.cx-sech { font-size:11px; color:var(--sb); padding:7px 13px 3px; letter-spacing:.02em }
.cx-row { display:flex; align-items:center; gap:9px; padding:7px 13px; color:inherit; text-decoration:none; border-inline-start:2px solid transparent }
.cx-row:hover { background:var(--pss) }
.cx-row.on { background:var(--pss); border-inline-start-color:var(--p); font-weight:600 }
.cx-gl { flex:none; width:20px; text-align:center; color:var(--sb) }
.cx-ava { position:relative; flex:none; width:24px; height:24px; border-radius:50%; background:var(--pss);
          display:inline-flex; align-items:center; justify-content:center; font-size:12px; font-weight:600 }
.cx-dot { position:absolute; inset-inline-end:-1px; bottom:-1px; width:9px; height:9px; border-radius:50%; border:2px solid var(--bg) }
.cx-tt { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:flex; align-items:center; gap:6px }
.cx-aud { font-size:10px; padding:0 5px; border-radius:99px; white-space:nowrap; flex:none }
.cx-aud.in { background:var(--pss); color:var(--sb) }
.cx-aud.cl { background:#fff3cd; color:#7a5b00; border:1px solid #f0d98a }
:root[data-theme="dark"] .cx-aud.cl, :root:not([data-theme="light"]) .cx-aud.cl { background:#3a2f00; color:#ffd873; border-color:#5a4a00 }
.cx-hd { display:flex; align-items:center; gap:10px; padding:11px 14px; border-bottom:1px solid var(--ln) }
.cx-ctxsec { border-top:1px solid var(--ln); padding:11px 2px }
.cx-ctxsec:first-child { border-top:0 }
.cx-ctxh { font-size:12px; color:var(--sb); margin-bottom:7px; display:flex; align-items:center; gap:6px }
@media (max-width:1100px) {
    .collab { grid-template-columns:1fr !important }
    .cx-ctx { display:none }               /* السياقُ يختفي على الضيّق — لا تمريرٌ أفقيّ */
    .cx-rail { position:static !important; max-height:none !important }
}
</style>
@endsection
