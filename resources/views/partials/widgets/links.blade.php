{{-- ودجة: روابط سريعة — بلاطات ملونة لأكثر الوجهات استعمالاً --}}
<div class="card kid">
    <h3>⚡ روابط سريعة</h3>
    <div class="qlinks">
        @foreach ($data ?? [] as $l)
            <a class="ql" style="--ql:{{ $l['c'] }}" href="{{ route($l['r']) }}">
                <span class="qlico">{{ $l['i'] }}</span>
                <span>{{ $l['t'] }}</span>
            </a>
        @endforeach
    </div>
</div>
