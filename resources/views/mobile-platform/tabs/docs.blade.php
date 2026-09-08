{{-- الوثائق (§43/§88): فهرسٌ حيٌّ لوثائقِ الجاهزية والمركز + مراجعُ حيّة، وعارضٌ آمنٌ
     لوثيقةٍ مُختارة (النصُّ يُطمَس بـBlade — لا حقنَ HTML، ولا اجتيازَ مسار). --}}
@php
    $base = fn (array $q = []) => route('mobileplatform.index', array_merge(['tab' => 'docs'], $q));
@endphp

@if ($docContent !== null)
    {{-- ═════ عارضُ وثيقةٍ آمن (نصٌّ خامٌّ مطموس) ═════ --}}
    <div class="toolbar"><a class="btn ghost xs" href="{{ $base() }}">→ عودة للفهرس</a></div>
    <div class="card kid wide">
        <h3>📄 <span class="mono">{{ $docName }}</span></h3>
        <pre class="mono" style="white-space:pre-wrap;overflow-x:auto;max-height:70vh;overflow-y:auto;line-height:1.7">{{ $docContent }}</pre>
    </div>
@elseif ($docSet !== '' && $docName !== '')
    <div class="toolbar"><a class="btn ghost xs" href="{{ $base() }}">→ عودة للفهرس</a></div>
    @include('partials.empty', ['text' => 'لا وثيقةَ بهذا الاسم (أو خارج القائمة المسموحة)', 'icon' => '📄'])
@else
    {{-- ═════ الفهرس ═════ --}}
    @include('partials.cc.kpis', ['items' => [
        ['label' => 'وثائقُ الجاهزية', 'value' => count($docs['readiness']), 'tone' => 'ok', 'sub' => 'docs/mobile-readiness'],
        ['label' => 'وثائقُ المركز', 'value' => count($docs['center']), 'tone' => 'ok', 'sub' => 'docs/mobile-platform-center'],
        ['label' => 'مواصفةُ OpenAPI', 'value' => 'حيّة', 'tone' => 'ok', 'sub' => 'مُولَّدة', 'url' => $docs['live']['openapi']],
        ['label' => 'مرجعُ المطوّر', 'value' => 'المسارات', 'sub' => 'تبويب الـAPI',
            'url' => route('mobileplatform.index', ['tab' => 'api']), 'hint' => 'مُستكشِفُ المسارات'],
    ]])

    <div class="card kid wide">
        <h3>📚 وثائقُ جاهزيّة الجوال</h3>
        <table class="mini">
            <tr><th>الوثيقة</th><th>الملف</th><th></th></tr>
            @forelse ($docs['readiness'] as $d)
                <tr>
                    <td>{{ $d['title'] }}</td>
                    <td class="mono sub">{{ $d['path'] }}</td>
                    <td class="acts"><a class="btn ghost xs" href="{{ $base(['doc_set' => 'readiness', 'doc' => $d['name']]) }}">عرض ←</a></td>
                </tr>
            @empty
                <tr><td colspan="3" class="sub" style="text-align:center;padding:10px">لا وثائق</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid wide">
        <h3>📗 وثائقُ مركز منصّة الجوال</h3>
        <table class="mini">
            <tr><th>الوثيقة</th><th>الملف</th><th></th></tr>
            @forelse ($docs['center'] as $d)
                <tr>
                    <td>{{ $d['title'] }}</td>
                    <td class="mono sub">{{ $d['path'] }}</td>
                    <td class="acts"><a class="btn ghost xs" href="{{ $base(['doc_set' => 'center', 'doc' => $d['name']]) }}">عرض ←</a></td>
                </tr>
            @empty
                <tr><td colspan="3" class="sub" style="text-align:center;padding:10px">لا وثائق</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid wide">
        <h3>🔗 مراجعُ حيّة</h3>
        <table class="mini">
            <tr><td>مواصفةُ OpenAPI (الجوال)</td><td class="acts"><a class="btn ghost xs" href="{{ $docs['live']['openapi'] }}" target="_blank" rel="noopener">فتح ↗</a></td></tr>
            <tr><td>Apple App Site Association</td><td class="acts"><a class="btn ghost xs" href="{{ $docs['live']['aasa'] }}" target="_blank" rel="noopener">فتح ↗</a></td></tr>
            <tr><td>Android assetlinks.json</td><td class="acts"><a class="btn ghost xs" href="{{ $docs['live']['assetlinks'] }}" target="_blank" rel="noopener">فتح ↗</a></td></tr>
            <tr><td>مرجعُ المطوّر — مُستكشِفُ المسارات وسجلُّ القدرات</td><td class="acts"><a class="btn ghost xs" href="{{ route('mobileplatform.index', ['tab' => 'api']) }}">تبويب الـAPI ←</a></td></tr>
        </table>
        <p class="sub" style="margin-top:8px">الوثائقُ تُعرَض نصّاً خاماً مطموساً (عرضٌ آمن — لا حقنَ HTML)؛ مصدرُها في المستودع تحت <span class="mono">docs/</span>.</p>
    </div>
@endif
