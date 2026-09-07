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
        <h2># {{ $conv->title ?: 'قناة' }}</h2>
        <div class="sub">
            @if (in_array($conv->audience, ['client', 'both'], true))
                <span class="bdg wn">👥 يبلغها العميل عبر بوابته</span>
            @else
                <span class="bdg">🔒 قناةٌ داخليّة</span>
            @endif
            · {{ $members->count() }} عضواً · دورُك: {{ $roleLabels[$role] ?? $role }}
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('conversations.index') }}">← كل القنوات</a>
</div>

@if (session('ok'))<div class="note ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="note wn">{{ session('err') }}</div>@endif
@if ($errors->any())<div class="note wn">{{ $errors->first() }}</div>@endif

<div class="grid" style="grid-template-columns:2fr 1fr;gap:16px;align-items:start">
    <div>
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

        @if ($canManage)
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

@endsection
