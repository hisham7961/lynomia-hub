@extends('layouts.app')
@section('title', $conv->title ?: 'قناة')
@section('content')
@php
    use App\Models\Conversation;
    $roleLabels = ['owner' => 'مالك', 'moderator' => 'مشرف', 'member' => 'عضو', 'guest' => 'ضيف'];
    // من أستطيع تعديلَ دورِه: المالكُ الجميعَ، والمشرفُ من دونه رتبةً فقط (كالخادم)
    $myRank = Conversation::roleRank($role);
@endphp

<div class="hero">
    <div>
        @if (($isGroup ?? false))
            @php $gnames = $members->where('user_id', '!=', auth()->id())->map(fn ($m) => $m->user?->name)->filter()->take(4)->implode('، '); @endphp
            <h2>👥 {{ $conv->title ?: ($gnames ?: 'مجموعة') }}</h2>
        @else
            <h2># {{ $conv->title ?: 'قناة' }}</h2>
        @endif
        <div class="sub">
            @if (($isGroup ?? false))
                <span class="bdg">🔒 مجموعةٌ خاصّة</span>
            @elseif (in_array($conv->audience, ['client', 'both'], true))
                <span class="bdg wn">👥 يبلغها العميل عبر بوابته</span>
            @else
                <span class="bdg">🔒 قناةٌ داخليّة</span>
            @endif
            · {{ $members->count() }} عضواً · دورُك: {{ $roleLabels[$role] ?? $role }}
        </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
        {{-- §16 تفضيلُ إشعارِ القناةِ لعضوها — كلُّ عضوٍ يضبط تفضيلَه وحده --}}
        @php $myPref = optional($members->firstWhere('user_id', auth()->id()))->effectiveNotifyPref() ?? 'all'; @endphp
        <form method="POST" action="{{ route('conversations.notify', $conv->id) }}" class="inline">
            @csrf
            <label class="vh" for="conv-notify">إشعارات القناة</label>
            <select class="inp sm" id="conv-notify" name="pref" onchange="this.form.submit()" title="إشعارات القناة">
                <option value="all" @selected($myPref === 'all')>🔔 كل الإشعارات</option>
                <option value="mentions" @selected($myPref === 'mentions')>@ الإشارات فقط</option>
                <option value="muted" @selected($myPref === 'muted')>🔕 مكتومة</option>
            </select>
            <noscript><button class="btn sm" type="submit">حفظ</button></noscript>
        </form>
        {{-- §15 نجمةُ المفضّلة الشخصيّة --}}
        @php $isFav = optional($members->firstWhere('user_id', auth()->id()))->isFavorite(); @endphp
        <form method="POST" action="{{ route('conversations.favorite', $conv->id) }}" class="inline">
            @csrf<button class="lnkbtn" type="submit" title="{{ $isFav ? 'إزالةٌ من المفضّلة' : 'إضافةٌ للمفضّلة' }}" style="font-size:16px">{{ $isFav ? '⭐' : '☆' }}</button>
        </form>
        {{-- §14 أرشفةُ القناةِ لمالكها --}}
        @if ($role === 'owner')
            <form method="POST" action="{{ route('conversations.archive', $conv->id) }}" class="inline" data-confirm="أرشفةُ القناة؟ تختفي من القوائم النشطة ويبقى تاريخُها.">
                @csrf<button class="btn ghost sm" type="submit" title="أرشفة">🗄️</button>
            </form>
        @endif
        <a class="btn ghost sm" href="{{ route('conversations.index') }}">← كل القنوات</a>
    </div>
</div>

@if (session('ok'))<div class="note ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="note wn">{{ session('err') }}</div>@endif
@if ($errors->any())<div class="note wn">{{ $errors->first() }}</div>@endif

<div class="grid" style="grid-template-columns:2fr 1fr;gap:16px;align-items:start">
    <div>
        {{-- §7 تحذيرُ الجمهور — غرفةٌ/قناةٌ يبلغها العميل: لا يُخطئ أحدٌ فيكتب سرّاً داخليّاً.
             العزلُ فيزيائيٌّ صلبٌ (§9 صفّان لا علامةُ رسالة)؛ التحذيرُ تمييزٌ عرضيٌّ فوقه. --}}
        @if (! ($isGroup ?? false) && in_array($conv->audience, ['client', 'both'], true))
            <div class="note wn" style="margin-bottom:10px">👥 <b>هذه المحادثةُ يبلغها العميل عبر بوابته.</b>
                كلُّ ما يُكتب هنا يراه — لا تُدرِج تكلفةً أو ملاحظةً داخليّة.</div>
        @endif
        {{-- قائمةُ الرسائل: نفسُ محرّك التعليقات (comments) عبر conversation_id --}}
        @include('partials.comments', [
            'cModule'         => 'channel',
            'cRecordId'       => $conv->id,
            'cConversationId' => $conv->id,
            'cCanPost'        => $canPost,
            'cChannelMod'     => $canManage,
            'comments'        => $messages,
            'users'           => $users,
        ])
        {{-- مؤشّرُ الكتابةِ العابر — يُملأ حيّاً من النبضة، ويختفي عند غيابِ الإشارة --}}
        <div class="sub" id="conv-typing" hidden aria-live="polite"
             style="font-size:12px;padding:4px 2px;min-height:16px"></div>
    </div>

    <div class="card">
        <h3>👥 الأعضاء <span class="bdg g">{{ $members->count() }}</span></h3>
        @foreach ($members as $m)
            @php $canEditThis = $canManage && ($role === 'owner' || Conversation::roleRank($m->role) < $myRank); @endphp
            <div class="row" style="display:flex;gap:8px;align-items:center;padding:8px 4px;border-top:1px solid var(--brd)">
                <span class="ava sm">{{ mb_substr($m->user?->name ?? '؟', 0, 1) }}</span>
                <b style="flex:1;min-width:0">{{ $m->user?->name ?? 'مستخدم محذوف' }}</b>
                @if ($canEditThis)
                    {{-- تعديلُ الدور: بابُ conversations.member.role — الخادمُ يفرض الحدود --}}
                    <form method="POST" action="{{ route('conversations.member.role', $conv->id) }}" class="inline">
                        @csrf<input type="hidden" name="user_id" value="{{ $m->user_id }}">
                        <select class="inp sm" name="role" onchange="this.form.submit()" title="تعديلُ الدور">
                            <option value="member" @selected($m->role === 'member')>عضو</option>
                            <option value="guest" @selected($m->role === 'guest')>ضيف</option>
                            @if ($role === 'owner')
                                <option value="moderator" @selected($m->role === 'moderator')>مشرف</option>
                                <option value="owner" @selected($m->role === 'owner')>مالك</option>
                            @endif
                        </select>
                    </form>
                @else
                    <span class="bdg {{ $m->isOwner() ? 'ok' : '' }}">{{ $roleLabels[$m->role] ?? $m->role }}</span>
                @endif
                @if ($canManage)
                    <form method="POST" action="{{ route('conversations.member.remove', $conv->id) }}"
                          data-confirm="إزالةُ العضو؟" class="inline">
                        @csrf<input type="hidden" name="user_id" value="{{ $m->user_id }}">
                        <button class="lnk sub" type="submit" title="إزالة">✕</button>
                    </form>
                @endif
            </div>
        @endforeach

        @if (($isGroup ?? false))
            {{-- §35 مجموعة: الإضافةُ تُنشئ مجموعةً جديدة (حفظُ الجمهور التاريخيّ) --}}
            <form method="POST" action="{{ route('groups.fork', $conv->id) }}" style="margin-top:10px">
                @csrf
                <label class="lbl">إضافةُ مشاركين</label>
                <select class="inp" name="participants[]" multiple size="4" required>
                    @foreach ($users as $uid => $name)
                        @unless ($members->contains('user_id', $uid))
                            <option value="{{ $uid }}">{{ $name }}</option>
                        @endunless
                    @endforeach
                </select>
                <div class="sub" style="margin-top:4px">تُنشئ الإضافةُ مجموعةً جديدة — لا يرى المُضافون ما مضى.</div>
                <button class="btn sm" type="submit" style="margin-top:6px">مجموعةٌ جديدةٌ بالمُضافين</button>
            </form>
            <form method="POST" action="{{ route('groups.leave', $conv->id) }}" style="margin-top:10px" data-confirm="مغادرةُ المجموعة؟">
                @csrf<button class="lnk sub" type="submit">↩ مغادرةُ المجموعة</button>
            </form>
        @elseif ($canManage)
            <form method="POST" action="{{ route('conversations.member.add', $conv->id) }}" style="margin-top:10px">
                @csrf
                <label class="lbl">إضافةُ عضو</label>
                <select class="inp" name="user_id" required>
                    <option value="">— اختر مستخدماً —</option>
                    @foreach ($users as $uid => $name)
                        @unless ($members->contains('user_id', $uid))
                            <option value="{{ $uid }}">{{ $name }}</option>
                        @endunless
                    @endforeach
                </select>
                <select class="inp" name="role" style="margin-top:6px">
                    <option value="member">عضو (يقرأ ويكتب)</option>
                    <option value="guest">ضيف (يقرأ فقط)</option>
                    @if ($role === 'owner')
                        <option value="moderator">مشرف (يدير الأعضاء)</option>
                        <option value="owner">مالك</option>
                    @endif
                </select>
                <button class="btn sm" type="submit" style="margin-top:6px">إضافة</button>
            </form>
        @endif
    </div>
</div>

{{-- §39 الاستطلاعُ التدريجيّ — رسائلُ القناةِ الجديدةُ منذ مؤشّرٍ بلا إعادةِ جلبِ الخيط.
     ومؤشّرُ الكتابةِ العابر (Typing): يُلمِّح عند إدخالِ حقلِ الناشر ويُعرَض في النبضة. --}}
<div hidden data-collab-poll data-kind="channel" data-target="cmt-list"
     data-poll-url="{{ route('conversations.since', $conv->id) }}" data-cursor="{{ $sinceCursor ?? '' }}"
     @if ($canPost)
        data-typing-url="{{ route('conversations.typing', $conv->id) }}" data-composer="#comments .cform textarea[name=body]"
        data-typing-box="conv-typing" data-csrf="{{ csrf_token() }}"
     @endif>
<script src="{{ asset('js/collab-poll.js') }}?v={{ config('hub.version') }}" defer></script>

@endsection
