{{-- (WP-4.5) جدولُ صحّة الأسرار — يتوقع: $rows (مُرقَّماً، وفي كل صفٍّ usage/rotatedRef/stale).
     **لا قيمةَ ولا بصمة**: المتحكّمُ لا يقرأ secret_cipher من القاعدة أصلاً،
     والاستعمالُ من قيود «عرض حساس» في التدقيق (audits module/record_id). --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            @include('partials.cc.th', ['col' => 'title', 'label' => 'السرّ', 'default' => ''])
            @include('partials.cc.th', ['col' => 'type', 'label' => 'النوع', 'default' => ''])
            <th scope="col">أنشأه</th>
            <th scope="col">آخر تدوير</th>
            <th scope="col">العمر</th>
            <th scope="col">الكشف</th>
            <th scope="col">الخطر</th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $sec)
            <tr>
                <td><a href="{{ route('m.show', ['vault', $sec->id]) }}"><b>{{ \Illuminate\Support\Str::limit($sec->title, 40) }}</b></a>
                    @if ($sec->archived)<span class="bdg g">مؤرشف</span>@endif</td>
                <td class="sub">{{ $sec->type ?: '—' }}</td>
                <td class="sub">{{ $sec->uname ?? '—' }}</td>
                <td class="sub">
                    @if ($sec->rotated_at){{ \Illuminate\Support\Carbon::parse($sec->rotated_at)->diffForHumans() }}
                    @else<span title="أُنشئ قبل عمود الختم أو لم تتغيّر قيمتُه منذ الإنشاء">لم يُدوَّر قط</span>@endif
                </td>
                <td class="sub" title="من آخر تدويرٍ فعليّ — أو من الإنشاء لمن لم يُدوَّر">{{ \Illuminate\Support\Carbon::parse($sec->rotatedRef)->diffForHumans(null, true) }}</td>
                <td>{{ $sec->usage }} <span class="sub">كشفة</span></td>
                <td>
                    @if ($sec->stale)<span class="bdg wn" title="تجاوز عتبةَ التدوير — دوِّره في مصدره ثم حدِّثه في الخزنة">بائت</span>
                    @else<span class="bdg ok">سليم</span>@endif
                </td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 7, 'icon' => '🗝️',
                     'text' => 'لا أسرار في الخزنة بعد'])
        @endforelse
        </tbody>
    </table>
</div>
