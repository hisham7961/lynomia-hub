{{--
    بطاقة «السجلات المرتبطة» — الشكل الموحَّد لمخرجات hub_related().

    كانت مُعاد بناؤها في تسعة قوالب بجداول متقاربة لا متطابقة، فكان تغيير الشكل
    يتطلب تسع تعديلات ويُنسى بعضها. المتغيرات:
      $children  مخرجات hub_related()      (مطلوب)
      $ownerId   معرّف السجل الأب — لرابط «عرض الكل» المفلتر (مطلوب)
      $heading   عنوان القسم، أو null لإخفائه
--}}
@php $heading = $heading ?? '🔗 السجلات المرتبطة'; @endphp

@if (count($children))
    @if ($heading)<h3 style="margin:4px 0 10px">{{ $heading }}</h3>@endif
    <div class="kids">
        @foreach ($children as $ch)
            <div class="card kid">
                <h3>{{ $ch['label'] }} <span class="bdg">{{ number_format($ch['count']) }}</span></h3>
                <table class="mini">
                    @foreach ($ch['rows'] as $cr)
                        <tr>
                            <td><a href="{{ route('m.show', [$ch['module'], $cr->id]) }}">{{ \Illuminate\Support\Str::limit($cr->{$ch['display']} ?? $cr->id, 44) }}</a></td>
                            <td class="mono sub" style="width:1%;white-space:nowrap">{{ optional($cr->created_at)->format('m-d') }}</td>
                        </tr>
                    @endforeach
                </table>
                <div style="margin-top:8px">
                    <a class="btn ghost xs" href="{{ route('m.index', [$ch['module'], 'f' => [$ch['field']['key'] => $ownerId]]) }}">عرض الكل ←</a>
                </div>
            </div>
        @endforeach
    </div>
@endif
