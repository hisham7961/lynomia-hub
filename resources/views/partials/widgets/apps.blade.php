{{-- ودجة: تقدم التطبيقات --}}
@if ($data && $data->count())
<div class="card kid">
    <h3>📱 تقدم التطبيقات</h3>
    <table class="mini">
        @foreach ($data as $a)
            <tr>
                <td><a href="{{ route('apps.center', $a->id) }}">{{ \Illuminate\Support\Str::limit($a->name, 24) }}</a>
                    <div class="pbar sm"><span style="width:{{ (int) ($a->progress ?? 0) }}%"></span></div>
                    <div class="sub">{{ $a->ver ? 'v' . $a->ver . ' · ' : '' }}{{ $a->status }}</div></td>
                <td style="width:1%"><b>{{ $a->progress !== null ? $a->progress . '٪' : '—' }}</b></td>
            </tr>
        @endforeach
    </table>
</div>
@endif
