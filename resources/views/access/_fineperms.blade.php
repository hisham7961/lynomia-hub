{{-- ═══════════ الصلاحيّاتُ الدقيقةُ والراياتُ الفعّالة (Permissions 360 · م3) ═══════════
     قراءةٌ من PermissionInspector::finePerms/flags — «ماذا يفتح هذا المفتاحُ ولمن مُنح؟»
     دون قراءةِ الكود. القرارُ من hub_can/hub_flag أنفسِهما (المالكُ يتجاوز)، ورقابةُ
     الاتصالاتِ بحكمِها الحقيقيّ (ليست موروثةً للمالك آليّاً). --}}
<div class="card">
    <h3 style="margin:0 0 8px">🧩 الصلاحيّاتُ الدقيقة — ماذا تفتح ولمن مُنحت</h3>
    <div class="sub" style="margin-bottom:8px">مفاتيحُ الكتالوجِ (config/hub_permissions) على وحداتِها: ✅ ممنوحةٌ فعّالاً، — غيرُ ممنوحة. تُحفَظ وتُمنَح من محرّرِ الأدوار.</div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الصلاحيّة</th><th>الوحدة</th><th>ممنوحة</th><th>ماذا تفتح بالضبط</th></tr></thead>
        <tbody>
        @foreach ($fine as $f)
            <tr>
                <td>{{ $f['label'] }}
                    @if ($f['risky'])<span class="bdg wn">حسّاسة</span>@endif
                    <span class="sub mono ltr" style="font-size:10px">{{ $f['key'] }}</span></td>
                <td>{{ $f['module_label'] }}</td>
                <td>{{ $f['granted'] ? '✅' : '—' }}</td>
                <td class="sub">{{ $f['hint'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

<div class="card">
    <h3 style="margin:0 0 8px">🚩 الراياتُ الفعّالة (ومجموعاتُ المراقبةِ الثلاث)</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الراية</th><th>ممنوحة</th></tr></thead>
        <tbody>
        @foreach ($flags as $fl)
            <tr>
                <td>{{ $fl['label'] }}
                    @if ($fl['risky'])<span class="bdg wn">حسّاسة</span>@endif
                    <span class="sub mono ltr" style="font-size:10px">{{ $fl['key'] }}</span></td>
                <td>{{ $fl['granted'] ? '✅' : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
