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
    ];
    $pStates = ['active' => ['فعّالة', 'g'], 'inactive' => ['معطَّلة', 'bad']];
    $posture = is_array($posture ?? null) ? $posture : [];
@endphp
@if ($posture === [])
    <div class="sub">لا قراءةَ وضعيّةٍ بعد — تصل مع أول نبضةٍ موقَّعة من وكيل الجهاز.</div>
@else
    <table class="mini">
        <thead><tr><th>الفحص</th><th>الحالة كما بلّغها النظام</th></tr></thead>
        <tbody>
        @foreach ($posture as $pCheck => $pReading)
            @php [$pLabel, $pTone] = $pStates[$pReading] ?? ['غير مُهيّأ / تعذّرت القراءة', 'wn']; @endphp
            <tr>
                <td>{{ $pChecks[$pCheck] ?? $pCheck }}</td>
                <td><span class="bdg {{ $pTone }}">{{ $pLabel }}</span></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="sub" style="margin-top:6px">قراءةٌ منعها نظامُ التشغيل تُخزَّن وتُعرَض «غير مُهيّأ» — لا تُدّعى أبداً.</div>
@endif
