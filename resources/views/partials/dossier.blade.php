{{-- ملف الكيان: ما يجب أن يكون موجوداً، وما ينقص، وما على وشك الانتهاء.
     يتوقع: $module $row (السجل). يُعرض فقط لوحدةٍ لها قائمة وثائق معلنة. --}}
@php
    $doc = hub_dossier($module, $row->id);
    $docTitle = ['companies' => 'ملف الشركة', 'projects' => 'ملف المشروع', 'brands' => 'ملف العلامة',
                 'services' => 'ملف الخدمة', 'competitors' => 'ملف المنافس', 'clients' => 'ملف العميل',
                 'suppliers' => 'ملف المورّد', 'hr' => 'ملف الموظف', 'apps' => 'ملف التطبيق',
                 'contracts' => 'ملف العقد', 'assets' => 'ملف الأصل', 'websites' => 'ملف الموقع'][$module] ?? 'ملف السجل';
@endphp
@if ($doc['rows'])
<div class="card" id="dossier">
    <h3>🗂️ {{ $docTitle }}
        <span class="bdg {{ $doc['tone'] }}">{{ $doc['pct'] }}٪ مكتمل</span>
        @if ($doc['requiredMissing'])<span class="bdg bad">{{ $doc['requiredMissing'] }} إلزامية ناقصة</span>@endif
        @if ($doc['expired'])<span class="bdg bad">{{ $doc['expired'] }} منتهية</span>@endif
        @if ($doc['expiring'])<span class="bdg wn">{{ $doc['expiring'] }} تنتهي قريباً</span>@endif
    </h3>

    <div class="pbar sm" style="margin-bottom:10px"><span style="width:{{ $doc['pct'] }}%"></span></div>
    <div class="sub" style="margin-bottom:10px">
        الاكتمال يُحسب على الوثائق <b>الإلزامية</b> وحدها ({{ $doc['need'] }} وثيقة) — والباقي مفيدٌ لا مطلوب.
        كل وثيقةٍ لها تاريخ انتهاء تصل رادار <a href="{{ route('alerts') }}">«ينتهي قريباً»</a> تلقائياً.
    </div>

    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الوثيقة</th><th>الحالة</th><th>النسخ</th><th>ينتهي</th><th class="acts">إجراء</th></tr></thead>
        <tbody>
        @foreach ($doc['rows'] as $d)
            <tr>
                <td>
                    <b>{{ $d['label'] }}</b>
                    @if ($d['req'])<span class="bdg bad" title="إلزامية">إلزامية</span>@endif
                    @if ($d['multi'])<span class="bdg g" title="تُقبل نسخ متعددة">متعددة</span>@endif
                    @if ($d['hint'])<div class="sub">{{ $d['hint'] }}</div>@endif
                </td>
                <td><span class="bdg {{ $d['tone'] }}">{{ $d['state'] }}</span></td>
                <td class="mono">{{ $d['n'] ?: '—' }}</td>
                <td class="sub">
                    @if ($d['expires'])
                        {{ $d['expires']->toDateString() }}
                        <div class="{{ $d['days'] < 0 ? 'txt-bad' : '' }}">
                            {{ $d['days'] < 0 ? 'انتهت منذ ' . abs($d['days']) . ' يوماً' : 'بعد ' . $d['days'] . ' يوماً' }}
                        </div>
                    @elseif ($d['expiry'])
                        <span title="هذه الوثيقة لها مدّة — سجّل تاريخ انتهائها">بلا تاريخ ⚠️</span>
                    @else
                        —
                    @endif
                </td>
                <td class="acts">
                    @if ($d['n'])
                        @foreach (array_slice($d['files'], 0, 3) as $f)
                            <a class="btn ghost xs" href="{{ route('att.dl', $f->id) }}"
                               title="{{ $f->original_name }}">⬇ {{ $f->doc_no ?: \Illuminate\Support\Str::limit($f->original_name, 16) }}</a>
                        @endforeach
                    @endif
                    <a class="btn ghost xs" href="#attachments" onclick="document.getElementById('att-kind')&&(document.getElementById('att-kind').value='{{ $d['key'] }}')">
                        {{ $d['n'] ? '＋ نسخة' : '＋ ارفع' }}</a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @if ($doc['extra'])
        <div class="sub" style="margin-top:8px">
            و{{ $doc['extra'] }} ملفاً مرفوعاً بلا نوعٍ معلن — تراه في <a href="#attachments">المرفقات</a> أدناه.
        </div>
    @endif
</div>
@endif
