{{-- «استخرج من المحضر» — يتوقع $row (الاجتماع). أسطر القرارات والملاحظات بمربعات اختيار --}}
@php
    $mnDec = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $row->decs)), fn ($s) => $s !== ''));
    $mnNotes = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $row->notes)), fn ($s) => $s !== ''));
    $mnCanD = hub_can(auth()->user(), 'decisions', 'a');
    $mnCanT = hub_can(auth()->user(), 'tasks', 'a');
    $mnBorn = \App\Models\Decision::whereNull('deleted_at')->where('meeting_id', $row->id)->count();
@endphp
@if (($mnDec || $mnNotes) && hub_can(auth()->user(), 'meetings', 'e') && ($mnCanD || $mnCanT))
    <div class="card">
        <h3 class="cardtitle">📋 استخرج من المحضر
            @if ($mnBorn)<span class="bdg ok">{{ $mnBorn }} قرار مُستخرج</span>@endif
        </h3>
        <div class="sub" style="margin-bottom:8px">
            أشّر على ما يستحق متابعةً: القرارات تُنشأ مربوطةً بهذا الاجتماع، والمهام تُنسب لقرارها فيُقاس تنفيذه.
        </div>
        <form method="POST" action="{{ route('meetings.extract', $row->id) }}">
            @csrf
            @if ($mnDec && $mnCanD)
                <div style="margin-bottom:8px">
                    <b class="sub">⚖️ من «القرارات»:</b>
                    @foreach ($mnDec as $line)
                        <label class="chk" style="display:block;margin-top:3px">
                            <input type="checkbox" name="decisions[]" value="{{ $line }}"> {{ $line }}
                        </label>
                    @endforeach
                </div>
            @endif
            @if ($mnNotes && $mnCanT)
                <div style="margin-bottom:8px">
                    <b class="sub">✅ من «الملاحظات» كمهام:</b>
                    @foreach ($mnNotes as $line)
                        <label class="chk" style="display:block;margin-top:3px">
                            <input type="checkbox" name="tasks[]" value="{{ $line }}"> {{ $line }}
                        </label>
                    @endforeach
                </div>
            @endif
            <button class="btn">📋 أنشئ المحدد</button>
        </form>
    </div>
@endif
