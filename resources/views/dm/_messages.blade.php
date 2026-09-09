{{-- خطُّ المحادثةِ المباشرة — يتوقّع $msgs · $other · اختيارياً $dmReactions.
     مشتركٌ بين الصندوق (dm/inbox) ومركزِ التواصلِ الموحّد (collaboration/center) — رسمٌ
     واحدٌ لا نسختان تتفارقان. صاحبُ الرسالةِ يمين والوارد يسار كالمعتاد. --}}
@php $me = auth()->id(); $lastDay = null; $lastFrom = null; @endphp
@forelse ($msgs as $m)
    @php
        $mine = $m->from_id === $me;
        $day = $m->created_at?->toDateString();
        $newDay = $day !== $lastDay;
        $grouped = ! $newDay && $lastFrom === $m->from_id;
        $lastDay = $day; $lastFrom = $m->from_id;
    @endphp
    @if ($newDay)
        <div class="sub" style="align-self:center;font-size:11px;padding:3px 10px;border-radius:99px;
                    background:var(--pss);margin:6px 0">
            {{ $m->created_at?->isToday() ? 'اليوم' : ($m->created_at?->isYesterday() ? 'أمس' : $m->created_at?->format('Y-m-d')) }}
        </div>
    @endif
    {{-- الوارد يمين والصادر يسار كما في تطبيقات المحادثة العربية · مرساةُ الرابط الدائم --}}
    <div class="msgw" id="dm-{{ $m->id }}" style="max-width:76%;{{ $mine ? 'align-self:flex-end' : 'align-self:flex-start' }};
                margin-top:{{ $grouped ? '1px' : '7px' }}">
        @if ($m->deleted_at)
            {{-- أثرٌ يقول ماذا جرى: الاختفاءُ بلا تفسيرٍ يجعل الطرف الآخر يظنّ أنه أخطأ القراءة --}}
            <div class="sub" style="padding:7px 12px;border:1px dashed var(--ln);border-radius:14px;font-size:12.5px">
                🚫 حُذفت رسالة{{ $mine ? '' : ' من ' . $other->name }}
            </div>
        @else
            <div style="padding:8px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.85;
                        {{ $mine ? 'background:var(--p);color:#fff' : 'background:var(--pss)' }}">{{ $m->body }}</div>
            @if ($m->att)
                <a class="sub" style="font-size:12px" href="{{ route('file.show', $m->att) }}" target="_blank" rel="noopener">📎 مرفق</a>
                <a class="sub" style="font-size:12px" href="{{ route('file.show', ['path' => $m->att, 'dl' => 1]) }}" title="تنزيل المرفق">⬇</a>
            @endif
            <div class="sub" style="font-size:10px;margin-top:1px;{{ $mine ? 'text-align:end' : '' }}">
                {{ $m->created_at?->format('H:i') }}
                @if ($mine){{ $m->read_at ? ' ✓✓' : ' ✓' }}@endif
                {{-- السحبُ لصاحب الرسالة وحده — لا يمحو أحدٌ كلام غيره --}}
                @if ($mine)
                    <form method="POST" action="{{ route('dm.destroy', $m->id) }}" class="msgdel"
                          data-confirm="سحبُ هذه الرسالة؟ يبقى مكانُها يقول إنها حُذفت.">
                        @csrf @method('DELETE')
                        <button class="lnkbtn" type="submit" aria-label="سحب الرسالة" title="سحب">🚫</button>
                    </form>
                @endif
            </div>
            {{-- §21 تفاعلاتُ الرسالة — على جدول reactions نفسِه، لطرفَي المحادثة --}}
            @php $mReacts = ($dmReactions ?? [])[$m->id] ?? []; @endphp
            <div class="crow" style="gap:4px;flex-wrap:wrap;margin-top:2px;{{ $mine ? 'justify-content:flex-end' : '' }}">
                @foreach ($mReacts as $emoji => $people)
                    <form method="POST" action="{{ route('dm.react', $m->id) }}" class="inline">
                        @csrf<input type="hidden" name="emoji" value="{{ $emoji }}">
                        <button class="lnkbtn" type="submit" style="border:1px solid var(--ln);border-radius:99px;padding:1px 7px;font-size:12px"
                                title="{{ collect($people)->pluck('name')->join('، ') }}">{{ $emoji }} {{ count($people) }}</button>
                    </form>
                @endforeach
                <details class="inline">
                    <summary class="lnkbtn" style="cursor:pointer;list-style:none;font-size:12px" title="تفاعل" aria-label="تفاعل">☺</summary>
                    <div class="crow" style="gap:2px;margin-top:2px">
                        @foreach (\App\Http\Controllers\Web\CommentController::REACTIONS as $e)
                            <form method="POST" action="{{ route('dm.react', $m->id) }}" class="inline">
                                @csrf<input type="hidden" name="emoji" value="{{ $e }}">
                                <button class="lnkbtn" type="submit" style="font-size:14px">{{ $e }}</button>
                            </form>
                        @endforeach
                    </div>
                </details>
            </div>
        @endif
    </div>
@empty
    <div class="empty"><span class="big">✉️</span>ابدأ المحادثة — رسالتك تصل فوراً مع إشعار</div>
@endforelse
