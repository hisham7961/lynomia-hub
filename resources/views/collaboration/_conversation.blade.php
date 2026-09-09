{{-- اللوحُ الأوسط لقناةٍ/مجموعة — نفسُ محرّكِ التعليقات عبر conversation_id (لا محرّكٌ ثانٍ).
     يتوقّع من الحاكم: $conv $role $canPost $canManage $isGroup $messages $members $users $record $sinceCursor --}}
@php
    use App\Models\Conversation;
    $roleLabels = ['owner' => 'مالك', 'moderator' => 'مشرف', 'member' => 'عضو', 'guest' => 'ضيف'];
    $clientAud = in_array($conv->audience, ['client', 'both'], true);
@endphp

<div class="card pad0">
    {{-- ترويسة: النوعُ والجمهورُ يُميَّزان بلا لبس (المرحلة ٧) --}}
    <div class="cx-hd">
        <div style="min-width:0;flex:1">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <b style="font-size:15px">{{ $isGroup ? '👥' : ($conv->project_id ? '📁' : '#') }} {{ $conv->title ?: ($isGroup ? 'مجموعة' : 'قناة') }}</b>
                @if ($isGroup)
                    <span class="bdg">🔒 مجموعةٌ خاصّة</span>
                @elseif ($conv->project_id && $clientAud)
                    <span class="bdg wn" title="يبلغها العميلُ عبر بوابته — لا تكتب هنا ما لا يراه">👥 غرفةُ عميل</span>
                @elseif ($conv->project_id)
                    <span class="bdg">🔒 غرفةٌ داخليّة</span>
                @elseif ($clientAud)
                    <span class="bdg wn">👥 يبلغها العميل</span>
                @else
                    <span class="bdg">🔒 قناةٌ داخليّة</span>
                @endif
            </div>
            <div class="sub" style="font-size:12px;margin-top:2px">{{ $members->count() }} عضواً · دورُك: {{ $roleLabels[$role] ?? $role }}</div>
        </div>
        {{-- نجمةُ المفضّلة الشخصيّة (§15) --}}
        @php $isFav = optional($members->firstWhere('user_id', auth()->id()))->isFavorite(); @endphp
        <form method="POST" action="{{ route('conversations.favorite', $conv->id) }}" class="inline">
            @csrf<button class="lnkbtn" type="submit" title="{{ $isFav ? 'إزالةٌ من المفضّلة' : 'إضافةٌ للمفضّلة' }}" style="font-size:16px">{{ $isFav ? '⭐' : '☆' }}</button>
        </form>
        <a class="btn ghost xs" href="{{ route('conversations.show', $conv->id) }}" title="الصفحةُ الكاملةُ وإدارةُ الأعضاء">⤢ كاملة</a>
    </div>

    {{-- تحذيرُ غرفةِ العميل — لا يُخطئ أحدٌ فيكتب سرّاً داخليّاً في غرفةٍ يبلغها العميل --}}
    @if ($clientAud && ! $isGroup)
        <div class="note wn" style="margin:10px 12px 0">👥 <b>هذه غرفةٌ يبلغها العميل عبر بوابته.</b> كلُّ ما يُكتب هنا يراه — لا تُدرِج تكلفةً أو ملاحظةً داخليّة.</div>
    @endif

    <div style="padding:12px 12px 0">
        @include('partials.comments', [
            'cModule'         => 'channel',
            'cRecordId'       => $conv->id,
            'cConversationId' => $conv->id,
            'cCanPost'        => $canPost,
            'cChannelMod'     => $canManage,
            'comments'        => $messages,
            'users'           => $users,
        ])
        {{-- مؤشّرُ الكتابةِ العابر — يُملأ حيّاً من النبضة --}}
        <div class="sub" id="conv-typing" hidden aria-live="polite" style="font-size:12px;padding:4px 2px;min-height:16px"></div>
    </div>
</div>

{{-- §39 الاستطلاعُ التدريجيّ + §typing الكتابة — نفسُ عقدِ الصفحةِ الكاملة --}}
<div hidden data-collab-poll data-kind="channel" data-target="cmt-list"
     data-poll-url="{{ route('conversations.since', $conv->id) }}" data-cursor="{{ $sinceCursor ?? '' }}"
     @if ($canPost)
        data-typing-url="{{ route('conversations.typing', $conv->id) }}" data-composer="#comments .cform textarea[name=body]"
        data-typing-box="conv-typing" data-csrf="{{ csrf_token() }}"
     @endif></div>
<script src="{{ asset('js/collab-poll.js') }}?v={{ config('hub.version') }}" defer></script>
