{{-- ودجة: مهام تقترب مواعيدها. $data = ['rows','dueCol','stCol','disp'] --}}
@php $rows = $data['rows'] ?? collect(); $dueCol = $data['dueCol'] ?? null; $stCol = $data['stCol'] ?? null; $disp = $data['disp'] ?? null; @endphp
@if ($rows->count() && $dueCol)
<div class="card kid">
    <h3>⏰ مهام تقترب مواعيدها</h3>
    <table class="mini">
        @foreach ($rows as $t)
            <tr>
                <td><a href="{{ route('m.show', ['tasks', $t->id]) }}">{{ \Illuminate\Support\Str::limit($t->{$disp}, 38) }}</a></td>
                <td class="mono acts">{{ substr($t->{$dueCol}, 0, 10) }}</td>
                @if ($stCol)<td class="acts"><span class="bdg {{ hub_tone($t->{$stCol}) }}">{{ $t->{$stCol} }}</span></td>@endif
            </tr>
        @endforeach
    </table>
</div>
@endif
