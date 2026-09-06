{{-- (WP-4.5) جدولُ مركز رموز API — يتوقع: $rows (مُرقَّماً ومُصنَّفاً بحقلَي status/full).
     **لا قيمةَ رمزٍ ولا بصمتَه هنا أبداً** — المتحكّمُ لا يقرأ token_hash أصلاً.
     الحالةُ من التصنيف الواحد ApiTokens::STATUSES، والأفعالُ إبطالٌ فقط (ق٧). --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            @include('partials.cc.th', ['col' => 'name', 'label' => 'المفتاح', 'default' => 'created'])
            <th scope="col">المالك</th>
            <th scope="col">النطاقات</th>
            @include('partials.cc.th', ['col' => 'created', 'label' => 'سُكّ', 'default' => 'created'])
            @include('partials.cc.th', ['col' => 'expires', 'label' => 'ينتهي', 'default' => 'created'])
            @include('partials.cc.th', ['col' => 'used', 'label' => 'آخر استعمال', 'default' => 'created'])
            <th scope="col">آخر عنوان</th>
            <th scope="col">الامتياز</th>
            <th scope="col">الحالة</th>
            <th scope="col" class="acts"></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $tok)
            @php [$tokLabel, $tokTone] = \App\Support\ApiTokens::STATUSES[$tok->status] ?? [$tok->status, 'g']; @endphp
            <tr>
                <td><b>{{ $tok->name }}</b>
                    @if ($tok->allowed_ips)<div class="sub" title="محصورٌ بعناوين شبكةٍ محددة">🌐 مقيَّد العناوين</div>@endif</td>
                <td>{{ $tok->uname ?? 'مستخدم محذوف' }}</td>
                <td class="sub" style="max-width:220px">
                    @if ($tok->full)<span class="bdg wn" title="null أو * = كلُّ صلاحيات صاحبه">كامل الصلاحيات</span>
                    @else<bdi class="mono ltr">{{ \Illuminate\Support\Str::limit($tok->scopes, 60) }}</bdi>@endif
                </td>
                <td class="sub" title="{{ $tok->created_at }}">{{ \Illuminate\Support\Carbon::parse($tok->created_at)->diffForHumans() }}</td>
                <td class="sub">{{ $tok->expires_at ? \Illuminate\Support\Carbon::parse($tok->expires_at)->diffForHumans() : 'لا ينتهي' }}</td>
                <td class="sub">{{ $tok->last_used_at ? \Illuminate\Support\Carbon::parse($tok->last_used_at)->diffForHumans() : 'لم يُستعمل قط' }}</td>
                <td>@if ($tok->last_ip)<bdi class="mono ltr">{{ $tok->last_ip }}</bdi>@else<span class="sub">—</span>@endif</td>
                <td>@if ($tok->privileged)<span class="bdg {{ $tok->full ? 'bad' : 'wn' }}" title="صاحبُه مالكٌ أو حاملُ رايةٍ خطرة">مميَّز</span>
                    @else<span class="sub">عاديّ</span>@endif</td>
                <td><span class="bdg {{ $tokTone }}">{{ $tokLabel }}</span>
                    @if ($tok->revoked_at)<div class="sub">{{ \Illuminate\Support\Carbon::parse($tok->revoked_at)->diffForHumans() }}</div>@endif</td>
                <td class="acts">
                    @unless ($tok->revoked_at)
                        <form method="POST" action="{{ route('security.token.revoke', $tok->id) }}" style="display:inline"
                              data-confirm="إبطال «{{ $tok->name }}»؟ يُرفض فوراً على سطح API ولا رجعة — صاحبُه يسكّ بديلاً من ملفه.">
                            @csrf<button class="btn ghost xs" style="color:var(--bad);border-color:var(--bad)">أبطِل</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 10, 'icon' => '🔑',
                     'text' => 'لا مفاتيح API بعد — تُسكّ من الملف الشخصي لكل مستخدم'])
        @endforelse
        </tbody>
    </table>
</div>
