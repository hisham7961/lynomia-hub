{{-- (WP-4.3) صلاحياتٌ تغيّرت مؤخّراً — قيودُ التدقيق على roles/users في ٣٠ يوماً.
     يتوقع: $rows (مُرقَّماً من صفوف audits)، $emailMode. النصوصُ لغير المالك تمرّ
     بالطمس (critic #9) — فقيدُ تدقيقٍ قد يحمل بريداً أو عنواناً في اسمه. --}}
<div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th scope="col">الفعل</th>
            <th scope="col">الوحدة</th>
            <th scope="col">السجل</th>
            <th scope="col">مَن</th>
            <th scope="col">متى</th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $pc)
            @php $pcMask = fn ($s) => $emailMode === '' ? (string) $s : \App\Support\SecurityFindings::maskPII((string) $s); @endphp
            <tr>
                <td><b>{{ $pcMask($pc->action) }}</b></td>
                <td>{{ $pc->module === 'roles' ? 'الأدوار' : 'المستخدمون' }}</td>
                <td>{{ $pcMask($pc->name) ?: '—' }}</td>
                <td class="sub">{{ $pcMask($pc->actor) ?: 'النظام' }}</td>
                <td class="sub">{{ \Illuminate\Support\Carbon::parse($pc->created_at)->diffForHumans() }}</td>
            </tr>
        @empty
            @include('partials.empty', ['colspan' => 5, 'icon' => '🔁',
                     'text' => 'لا تغييرَ على الأدوار أو المستخدمين في آخر ٣٠ يوماً'])
        @endforelse
        </tbody>
    </table>
</div>
