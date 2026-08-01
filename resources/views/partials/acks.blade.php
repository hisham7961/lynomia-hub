{{-- الإقرار الموثَّق على السجل — يتوقع: $module $row. لا يظهر لوحدةٍ غير مسجَّلة --}}
@php $ack = \App\Support\Acks::state($module, $row); @endphp
@if ($ack && ($ack['total'] || $ack['extra']))
    @php
        $me = collect($ack['people'])->firstWhere('id', (string) auth()->id());
        $late = collect($ack['people'])->where('acked', false);
    @endphp
    <div class="card">
        <h3 class="cardtitle">🖊️ {{ $ack['def']['label'] }}
            <span class="bdg {{ $ack['pct'] === 100 ? 'ok' : ($ack['done'] ? 'wn' : 'bad') }}">
                {{ $ack['done'] }}/{{ $ack['total'] }}
            </span>
        </h3>
        <div class="sub" style="margin-bottom:8px">{{ $ack['def']['why'] }}</div>

        {{-- الزرّ لمن طُلب منه هو وحده — والإقرار عن الغير ممنوع في الخادم أيضاً --}}
        @if ($me && ! $me['acked'])
            <form method="POST" action="{{ route('acks.store', [$module, $row->id]) }}"
                  class="card" style="background:var(--pss);padding:10px 12px;margin-bottom:8px">
                @csrf
                <label for="ack-note"><b>{{ $ack['def']['verb'] }}</b></label>
                @if ($me['stale'])
                    <div class="sub" style="margin:4px 0">
                        ⚠️ أقررتَ بالنسخة {{ $me['ver'] }} وقد صار السجل بالنسخة {{ $ack['ver'] }} —
                        النصّ تغيّر بعد إقرارك فيلزم إقرارٌ جديد.
                    </div>
                @endif
                <div class="crow" style="margin-top:6px">
                    <input class="inp" id="ack-note" name="note" maxlength="1000"
                           placeholder="تحفّظ أو ملاحظة (اختياري) — تُحفظ مع إقرارك">
                    <button class="btn p sm" type="submit">🖊️ أُقرّ</button>
                </div>
            </form>
        @elseif ($me)
            <div class="sub" style="margin-bottom:8px">
                ✅ أقررتَ {{ $me['at'] ? \Illuminate\Support\Carbon::parse($me['at'])->diffForHumans() : '' }}
                @if ($me['note']) — تحفّظك: «{{ $me['note'] }}» @endif
            </div>
        @endif

        <table class="mini">
            @foreach ($ack['people'] as $p)
                <tr>
                    <td>{{ $p['name'] }}
                        @if ($p['acked'])
                            {{-- الدليل: من أقرّ ومتى ومن أي جهاز — كشهادة التوقيع --}}
                            <div class="sub" style="font-size:11px">
                                {{ \Illuminate\Support\Carbon::parse($p['at'])->format('Y-m-d H:i') }}
                                @if ($p['ip']) · {{ $p['ip'] }} @endif
                                @if ($p['note']) · «{{ \Illuminate\Support\Str::limit($p['note'], 40) }}» @endif
                            </div>
                        @endif
                    </td>
                    <td class="acts">
                        @if ($p['acked'])<span class="bdg ok">✅ أقرّ</span>
                        @elseif ($p['stale'])<span class="bdg wn" title="أقرّ بنسخةٍ سابقة">🔁 إقرار قديم</span>
                        @else<span class="bdg">⏳ لم يُقرّ</span>@endif
                    </td>
                </tr>
            @endforeach
        </table>

        @if ($late->count() && hub_can(auth()->user(), $module, 'e'))
            <form method="POST" action="{{ route('acks.remind', [$module, $row->id]) }}" style="margin-top:8px">
                @csrf<button class="btn ghost sm">🔔 ذكّر من لم يُقرّ ({{ $late->count() }})</button>
            </form>
        @endif
        @if ($ack['extra'])
            <div class="sub" style="margin-top:6px;font-size:12px">
                📎 {{ $ack['extra'] }} إقرار محفوظ لأشخاصٍ لم يعودوا في القائمة — الأثر لا يُمحى بتغيير القائمة.
            </div>
        @endif
    </div>
@endif
