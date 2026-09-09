{{-- (Work OS · الطور J · WP-J.3 · §43 · C15) **جدولُ الوضعيّة الصادقة** — يتوقع:
     $posture (مصفوفة فحص ← قراءة كما خُزّنت من نبضة الوكيل الموقَّعة، أو null).

     قاعدةُ الصدق غيرُ القابلة للتفاوض: «فعّالة» لا تُعرَض إلا لقراءة 'active'
     خُزّنت حرفياً كما بلّغها النظام؛ وكلُّ ما سواها — قراءةٌ منعها النظام، قيمةٌ
     مجهولة، 'not-configured' — تُعرَض «غير مُهيّأ / تعذّرت القراءة». لا ادّعاء. --}}
@php
    $pChecks = [
        'defender'  => 'Defender (مضادّ الفيروسات)',
        'firewall'  => 'جدار الحماية',
        'bitlocker' => 'تشفير BitLocker',
        'filevault' => 'تشفير FileVault',
        'updates'   => 'تحديثات النظام',
        'wifi'      => 'شبكة Wi-Fi الشركة (اتصالُ الجهاز نفسِه)',
    ];
    $posture = is_array($posture ?? null) ? $posture : [];
@endphp
@if ($posture === [])
    <div class="sub">لا قراءةَ وضعيّةٍ بعد — تصل مع أول نبضةٍ موقَّعة من وكيل الجهاز.</div>
@else
    <table class="mini">
        <thead><tr><th>الفحص</th><th>الحالة كما بلّغها النظام</th></tr></thead>
        <tbody>
        @foreach ($posture as $pCheck => $pReading)
            {{-- §11 — العقدُ الموسَّع: active/inactive/permission-denied/unavailable/unsupported/not-configured.
                 مُنعُ القراءة (permission-denied) **ليس امتثالاً** — يُعرَض أحمرَ لا يُطمَس فعّالاً. --}}
            @php [$pLabel, $pTone] = \App\Support\PostureContract::label((string) $pReading); @endphp
            <tr>
                <td>{{ $pChecks[$pCheck] ?? $pCheck }}</td>
                <td><span class="bdg {{ $pTone }}">{{ $pLabel }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="sub" style="margin-top:6px">الامتثالُ لقراءة 'active' حرفيّةٍ وحدَها؛ ومُنعُ القراءة يُعرَض صراحةً — ليس امتثالاً ولا يُدّعى.</div>
@endif
