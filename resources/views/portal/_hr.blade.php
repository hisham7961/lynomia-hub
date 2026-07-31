{{-- محتوى ٣٦٠° مشترك بين «بوابتي» والملف الشامل — يتوقع: $emp $self $tasks $openTasks $leaves $attend $attMonth $log $assets $mustRead --}}
@php
    $days = fn ($d) => $d ? (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse(substr((string) $d, 0, 10))->startOfDay(), false) : null;
    $iq = $emp ? $days($emp->iqama_exp) : null;
    $pp = $emp ? $days($emp->pass_exp) : null;
@endphp

@if ($emp)
<div class="cards">
    <div class="stat"><span class="ico">🏖️</span><b>{{ $emp->leave_bal ?? '—' }}</b><span>رصيد الإجازات (يوم)</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ number_format($openTasks) }}</b><span>مهام مفتوحة</span></div>
    <div class="stat"><span class="ico">🕒</span><b>{{ $attMonth['days'] }} يوم / {{ number_format($attMonth['hours'], 1) }} س</b><span>حضور هذا الشهر</span></div>
    @if ($iq !== null)
        <div class="stat"><span class="ico">🪪</span><b class="{{ $iq < 30 ? 'txt-bad' : '' }}">{{ $iq < 0 ? 'منتهية!' : $iq . ' يوم' }}</b><span>انتهاء الإقامة</span></div>
    @endif
    @if ($pp !== null)
        <div class="stat"><span class="ico">🛂</span><b class="{{ $pp < 90 ? 'txt-bad' : '' }}">{{ $pp < 0 ? 'منتهٍ!' : $pp . ' يوم' }}</b><span>انتهاء الجواز</span></div>
    @endif
    @if ($self && $emp->salary)
        <div class="stat"><span class="ico">💵</span><b>{{ number_format((float) $emp->salary, 0) }}</b><span>الراتب الأساسي</span></div>
    @endif
</div>
@endif

{{-- ── الصندوق الموحد: ما ينتظر التصرف ── --}}
@if ($approvals->count() || $decisions->count() || $tickets->count() || $meetings->count())
<h3 style="margin:14px 0 10px">📥 {{ $self ? 'صندوقي الموحد — ينتظر تصرفي' : 'ينتظر تصرفه' }}</h3>
<div class="kids">
    @if ($approvals->count())
    <div class="card kid">
        <h3>🔏 موافقات معلقة <span class="bdg wn">{{ $approvals->count() }}</span></h3>
        <table class="mini">
            @foreach ($approvals as $a)
                <tr>
                    <td>@if (hub_can(auth()->user(), 'approvals', 'v'))<a href="{{ route('m.show', ['approvals', $a->id]) }}">{{ \Illuminate\Support\Str::limit($a->title, 32) }}</a>@else {{ \Illuminate\Support\Str::limit($a->title, 32) }} @endif
                        <div class="sub">{{ $a->type }}{{ $a->amount ? ' · ' . number_format((float) $a->amount, 0) . ' ' . $a->currency : '' }}{{ $a->due ? ' · قبل ' . substr($a->due, 0, 10) : '' }}</div></td>
                    <td class="acts">@if ($a->status)<span class="bdg {{ hub_tone($a->status) }}">{{ $a->status }}</span>@endif</td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif

    @if ($tickets->count())
    <div class="card kid">
        <h3>🎫 تذاكر مفتوحة <span class="bdg wn">{{ $tickets->count() }}</span></h3>
        <table class="mini">
            @foreach ($tickets as $t)
                <tr>
                    <td>@if (hub_can(auth()->user(), 'tickets', 'v'))<a href="{{ route('m.show', ['tickets', $t->id]) }}">{{ \Illuminate\Support\Str::limit($t->subject, 32) }}</a>@else {{ \Illuminate\Support\Str::limit($t->subject, 32) }} @endif
                        <div class="sub">{{ $t->customer }}{{ $t->priority ? ' · ' . $t->priority : '' }}</div></td>
                    <td class="acts">@if ($t->status)<span class="bdg {{ hub_tone($t->status) }}">{{ $t->status }}</span>@endif</td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif

    @if ($meetings->count())
    <div class="card kid">
        <h3>📅 اجتماعات قادمة</h3>
        <table class="mini">
            @foreach ($meetings as $mt)
                <tr>
                    <td>@if (hub_can(auth()->user(), 'meetings', 'v'))<a href="{{ route('m.show', ['meetings', $mt->id]) }}">{{ \Illuminate\Support\Str::limit($mt->title, 32) }}</a>@else {{ \Illuminate\Support\Str::limit($mt->title, 32) }} @endif
                        <div class="sub">{{ \Illuminate\Support\Carbon::parse($mt->dt)->translatedFormat('l j F · H:i') }}</div></td>
                    <td class="acts">@if ($mt->link)<a class="btn ghost xs" href="{{ hub_safe_url($mt->link) }}" target="_blank" rel="noopener">انضم</a>@endif</td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif

    @if ($decisions->count())
    <div class="card kid">
        <h3>🧭 قرارات أنفّذها <span class="bdg wn">{{ $decisions->count() }}</span></h3>
        <table class="mini">
            @foreach ($decisions as $d)
                <tr>
                    <td>@if (hub_can(auth()->user(), 'decisions', 'v'))<a href="{{ route('m.show', ['decisions', $d->id]) }}">{{ \Illuminate\Support\Str::limit($d->title, 32) }}</a>@else {{ \Illuminate\Support\Str::limit($d->title, 32) }} @endif
                        @if ($d->due)<div class="sub">قبل {{ substr($d->due, 0, 10) }}</div>@endif</td>
                    <td class="acts"><span class="bdg {{ hub_tone($d->status) }}">{{ $d->status }}</span></td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif
</div>
@endif

<h3 style="margin:14px 0 10px">🗂️ {{ $self ? 'ملفي وعملي' : 'ملفه وعمله' }}</h3>
<div class="kids">
    <div class="card kid">
        <h3>✅ {{ $self ? 'مهامي' : 'مهامه' }} المفتوحة
            @if (hub_can(auth()->user(), 'tasks', 'v'))<a class="btn ghost xs msauto" href="{{ route('m.index', 'tasks') }}">الكل ←</a>@endif
        </h3>
        <table class="mini">
            @forelse ($tasks as $t)
                <tr>
                    <td>
                        @if (hub_can(auth()->user(), 'tasks', 'v'))<a href="{{ route('m.show', ['tasks', $t->id]) }}">{{ \Illuminate\Support\Str::limit($t->title, 34) }}</a>
                        @else {{ \Illuminate\Support\Str::limit($t->title, 34) }} @endif
                        <div class="sub">{{ $t->due ? 'الاستحقاق: ' . substr($t->due, 0, 10) : 'بلا موعد' }}{{ $t->priority ? ' · ' . $t->priority : '' }}</div>
                    </td>
                    <td class="acts">
                        @if ($t->status)<span class="bdg {{ hub_tone($t->status) }}">{{ $t->status }}</span>@endif
                        @if ($t->progress !== null)<div class="sub" style="text-align:center">{{ (int) $t->progress }}٪</div>@endif
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:14px;text-align:center">لا مهام مفتوحة 🎉</td></tr>
            @endforelse
        </table>
    </div>

    @if ($emp)
    <div class="card kid">
        <h3>🏖️ الإجازات والطلبات</h3>
        <table class="mini">
            @forelse ($leaves as $l)
                <tr>
                    <td>{{ $l->type }}<div class="sub">{{ substr($l->date_from, 0, 10) }} ← {{ substr((string) $l->date_to, 0, 10) }} ({{ $l->days }} يوم)</div></td>
                    <td class="acts"><span class="bdg {{ hub_tone($l->status) }}">{{ $l->status }}</span></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:14px;text-align:center">لا طلبات بعد</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>🕒 آخر الحضور</h3>
        <table class="mini">
            @forelse ($attend as $a)
                <tr>
                    <td>{{ substr($a->date, 0, 10) }}<div class="sub">{{ $a->time_in ?? '—' }} ← {{ $a->time_out ?? '—' }}</div></td>
                    <td class="acts">{{ $a->hours !== null ? number_format((float) $a->hours, 1) . ' س' : '' }}
                        @if ($a->status)<span class="bdg {{ hub_tone($a->status) }}">{{ $a->status }}</span>@endif</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:14px;text-align:center">لا تسجيلات حضور</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>📋 السجل الوظيفي <span class="sub">(تدريب · شهادات · ملاحظات)</span></h3>
        <table class="mini">
            @forelse ($log as $r)
                <tr>
                    <td>{{ \Illuminate\Support\Str::limit($r->title, 32) }}<div class="sub">{{ $r->kind }}{{ $r->date ? ' · ' . substr($r->date, 0, 10) : '' }}{{ $r->expiry ? ' · ينتهي ' . substr($r->expiry, 0, 10) : '' }}</div></td>
                    <td class="acts">@if ($r->status)<span class="bdg {{ hub_tone($r->status) }}">{{ $r->status }}</span>@endif</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:14px;text-align:center">السجل فارغ</td></tr>
            @endforelse
        </table>
    </div>
    @endif

    <div class="card kid">
        <h3>💻 العهدة والأجهزة</h3>
        <table class="mini">
            @forelse ($assets as $a)
                <tr>
                    <td>{{ $a->name }}<div class="sub">{{ $a->type }}{{ $a->tag ? ' · ' . $a->tag : '' }}</div></td>
                    <td class="acts">@if ($a->status)<span class="bdg {{ hub_tone($a->status) }}">{{ $a->status }}</span>@endif</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:14px;text-align:center">لا عهدة مسجلة</td></tr>
            @endforelse
        </table>
    </div>

    @if ($self && $mustRead->count())
    <div class="card kid">
        <h3>📕 سياسات يجب قراءتها</h3>
        <table class="mini">
            @foreach ($mustRead as $k)
                <tr><td>
                    @if (hub_can(auth()->user(), 'kb', 'v'))<a href="{{ route('m.show', ['kb', $k->id]) }}">{{ $k->title }}</a>@else {{ $k->title }} @endif
                    @if ($k->cat)<div class="sub">{{ $k->cat }}</div>@endif
                </td></tr>
            @endforeach
        </table>
    </div>
    @endif
</div>
