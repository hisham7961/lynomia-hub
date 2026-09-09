{{-- لوحُ السياق لقناةٍ/مجموعة — مشاركون · مثبّتات · ملفّات · سياقُ السجلّ.
     كلُّها من نفسِ الحاوية (لا استعلامٌ عبر حاويةٍ أخرى) — الصلاحيةُ حُسمت في الحارس. --}}
@php $roleLabels = ['owner' => 'مالك', 'moderator' => 'مشرف', 'member' => 'عضو', 'guest' => 'ضيف']; @endphp

@if (! empty($record))
    <div class="cx-ctxsec">
        <div class="cx-ctxh">🔗 سياقُ السجلّ</div>
        <a class="btn ghost sm" href="{{ $record['url'] }}" style="width:100%;text-align:start">
            {{ $record['label'] }} #{{ $record['id'] }} ⤢
        </a>
    </div>
@endif

<div class="cx-ctxsec">
    <div class="cx-ctxh">👥 المشاركون <span class="bdg g">{{ $members->count() }}</span></div>
    @foreach ($members as $m)
        <div style="display:flex;gap:8px;align-items:center;padding:5px 0">
            <span class="ava sm">{{ mb_substr($m->user?->name ?? '؟', 0, 1) }}</span>
            <b style="flex:1;min-width:0;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $m->user?->name ?? 'مستخدم محذوف' }}</b>
            <span class="bdg {{ $m->isOwner() ? 'ok' : '' }}" style="font-size:10px">{{ $roleLabels[$m->role] ?? $m->role }}</span>
        </div>
    @endforeach
    @if ($canManage)
        <a class="sub" style="font-size:12px;display:inline-block;margin-top:6px" href="{{ route('conversations.show', $conv->id) }}">⚙ إدارةُ الأعضاء</a>
    @endif
</div>

{{-- §15/§16 إدارةُ المجموعةِ اليوميّةُ داخلَ المركز: التنبيهات · إضافةُ أشخاصٍ (فرعٌ آمن) · المغادرة.
     لا مصطلحاتٍ تقنيّة (Fork/GroupDm) في نصّ المستخدم. القناةُ تُدار من صفحتها المعتادة. --}}
@if (($isGroup ?? false))
    @if (hub_has_col('conversation_members', 'notify_pref'))
        @php $myPref = optional($members->firstWhere('user_id', auth()->id()))->notify_pref ?? 'all'; @endphp
        <div class="cx-ctxsec">
            <div class="cx-ctxh">🔔 التنبيهات</div>
            <form method="POST" action="{{ route('conversations.notify', $conv->id) }}">
                @csrf
                <select class="inp" name="pref" onchange="this.form.submit()" aria-label="تفضيلُ تنبيهاتِ المجموعة">
                    <option value="all" @selected($myPref === 'all')>كلُّ الرسائل</option>
                    <option value="mentions" @selected($myPref === 'mentions')>الإشاراتُ إليّ فقط</option>
                    <option value="muted" @selected($myPref === 'muted')>كتمُ المجموعة</option>
                </select>
                <noscript><button class="btn ghost xs" style="margin-top:5px">حفظ</button></noscript>
            </form>
        </div>
    @endif

    <div class="cx-ctxsec">
        <div class="cx-ctxh">➕ إضافةُ أشخاص</div>
        @if (count($addCandidates ?? []))
            <details class="cx-addppl">
                <summary class="btn ghost sm" style="width:100%;text-align:start">إضافةُ أشخاصٍ للمجموعة…</summary>
                <form method="POST" action="{{ route('groups.fork', $conv->id) }}" style="margin-top:8px">
                    @csrf
                    <input type="hidden" name="origin" value="collab">
                    @include('partials.participant_picker', [
                        'candidates' => $addCandidates,
                        'pickerId'   => 'ctx-fork',
                        'label'      => 'من تضيف؟',
                        'emptyHint'  => 'لا زملاءَ آخرين لإضافتهم.',
                    ])
                    <div class="sub" style="font-size:11px;margin-top:5px;line-height:1.7">سيتم إنشاءُ مجموعةٍ جديدةٍ بالمشاركين المضافين، ولن تظهر لهم الرسائلُ السابقة.</div>
                    <button class="btn sm" type="submit" style="margin-top:8px">إنشاءُ مجموعةٍ موسَّعة</button>
                </form>
            </details>
        @else
            <div class="sub" style="font-size:12px">لا زملاءَ آخرين لإضافتهم.</div>
        @endif
    </div>

    <div class="cx-ctxsec">
        <form method="POST" action="{{ route('groups.leave', $conv->id) }}" data-confirm="مغادرةُ المجموعة؟">
            @csrf<button class="lnk sub" type="submit" style="font-size:12.5px">↩ مغادرةُ المجموعة</button>
        </form>
    </div>
@endif

<div class="cx-ctxsec">
    <div class="cx-ctxh">📌 المثبّتات <span class="bdg g">{{ $pins->count() }}</span></div>
    @forelse ($pins as $p)
        {{-- تصفّحُ المثبّتات: من ثبّت ومتى، والقفزُ إلى الرسالة (§28) --}}
        <a class="cx-pin" href="{{ route('conversations.show', $conv->id) }}#c-{{ $p->id }}"
           style="display:block;padding:6px 0;border-top:1px dashed var(--ln);color:inherit;text-decoration:none">
            <div class="sub" style="font-size:11px">{{ $p->user?->name ?? '؟' }} · {{ $p->pinned_at?->diffForHumans() ?? $p->created_at?->diffForHumans() }}</div>
            <div style="font-size:12.5px;line-height:1.7">{{ \Illuminate\Support\Str::limit($p->body, 90) }}</div>
        </a>
    @empty
        <div class="sub" style="font-size:12px">لا مثبّتاتٍ بعد.</div>
    @endforelse
</div>

<div class="cx-ctxsec">
    <div class="cx-ctxh">📎 الملفّات <span class="bdg g">{{ $files->count() }}</span></div>
    @forelse ($files as $f)
        <div style="display:flex;gap:6px;align-items:baseline;padding:5px 0;border-top:1px dashed var(--ln)">
            <a class="sub" style="flex:1;min-width:0;font-size:12.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
               href="{{ route('file.show', $f->att) }}" target="_blank" rel="noopener" title="{{ basename($f->att) }}">📎 {{ basename($f->att) }}</a>
            <span class="sub mono" style="font-size:10px;flex:none">{{ $f->user?->name ?? '؟' }}</span>
        </div>
    @empty
        <div class="sub" style="font-size:12px">لا ملفّاتٍ بعد.</div>
    @endforelse
</div>
