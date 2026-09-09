{{-- ═══════════ مشاريعُ الأصل (Project 360 · §20/§23/§67) ═══════════
     تخصيصاتُ هذا الأصلِ للمشاريع (تشغيليّةٌ لا عهدة): نشطٌ + تاريخ + تخصيصٌ/إنهاء.
     داخليٌّ حصراً (العميلُ لا يبلغ تفصيلَ الأصل). لغةٌ صريحة: «مخصّص للمشروع» لا «عهدة». --}}
@php
    use App\Support\AssetProjectService;
    $apSvc = new AssetProjectService();
    $apActive = $apSvc->activeForAsset((string) $row->id);
    $apHistory = $apSvc->historyForAsset((string) $row->id);
    $apU = auth()->user();
    $apCanAssign = ! hub_is_client($apU) && hub_can($apU, 'assets', 'e') && hub_can($apU, 'projects', 'v')
        && ! in_array(\App\Support\Custody::canonicalStatus($row->status), AssetProjectService::INELIGIBLE_STATUSES, true);

    $apCandidates = [];
    if ($apCanAssign) {
        $apActiveIds = $apActive->pluck('project_id')->map('strval')->all();
        $apRows = hub_scope(\App\Models\Project::query(), 'projects')
            ->when($row->company_id !== null, fn ($q) => $q->where(fn ($w) =>
                $w->where('company_id', $row->company_id)->orWhereNull('company_id')))
            ->whereNotIn('id', $apActiveIds ?: ['-'])
            ->orderByDesc('updated_at')->limit(200)->get(['id', 'name', 'status', 'client_id', 'audience']);
        foreach ($apRows as $pr) {
            $ext = ($pr->client_id !== null || $pr->audience === 'client');
            $apCandidates[(string) $pr->id] = ['name' => $pr->name,
                'sub' => trim(($pr->status ?: '') . ($ext ? ' · 👥 مشروع عميل' : ' · 🔒 داخليّ'))];
        }
    }
@endphp

<div class="card">
    <h3 class="cardtitle">📁 مشاريعُ الأصل
        <span class="bdg g">{{ $apActive->count() }} نشط</span>
    </h3>
    <div class="sub" style="margin-bottom:8px">تخصيصٌ تشغيليٌّ للمشاريع — مستقلٌّ عن الحائزِ والمحطّةِ والعهدة.</div>

    @if ($apActive->isNotEmpty())
        <div class="tblwrap"><table>
            <thead><tr><th>المشروع</th><th>الغرض</th><th>مُذ</th>@if ($apCanAssign)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($apActive as $ap)
                <tr>
                    <td><a href="{{ route('m.show', ['projects', $ap->project_id]) }}">{{ \Illuminate\Support\Str::limit($ap->project?->name ?? $ap->project_id, 40) }}</a></td>
                    <td class="sub">{{ $ap->purpose ?: '—' }}</td>
                    <td class="mono sub">{{ optional($ap->assigned_at)->format('Y-m-d') }}</td>
                    @if ($apCanAssign)
                        <td class="acts">
                            <form method="POST" action="{{ route('assetproject.end', $ap->id) }}" data-confirm="إنهاءُ تخصيصِ الأصلِ لهذا المشروع؟">
                                @csrf<button class="lnk sub" type="submit">✕ إنهاء</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @else
        @include('partials.empty', ['text' => 'هذا الأصلُ غيرُ مخصَّصٍ لأيِّ مشروع', 'icon' => '📁'])
    @endif

    @if ($apCanAssign && count($apCandidates))
        <details class="ap-assign" style="margin-top:10px">
            <summary class="btn sm">➕ تخصيصُ الأصلِ لمشروع</summary>
            <form method="POST" action="{{ route('assets.projects.assign', $row->id) }}" style="margin-top:10px">
                @csrf
                @include('partials.participant_picker', [
                    'candidates' => $apCandidates,
                    'field'      => 'projects[]',
                    'pickerId'   => 'asset-projects',
                    'label'      => 'اختر مشاريعَ لتخصيصِ الأصلِ لها',
                    'emptyHint'  => 'لا مشاريعَ متاحةً ضمنَ النطاق.',
                ])
                <label class="lbl" style="margin-top:8px">الغرض (اختياريّ)</label>
                <input class="inp" name="purpose" maxlength="120" placeholder="مثال: خادمُ مشروع · جهازُ اختبار">
                <button class="btn p" type="submit" style="margin-top:10px">تخصيصُ الأصل</button>
            </form>
        </details>
    @endif

    {{-- تاريخُ التخصيص (§67) — عمليٌّ لا عهدة: مُذ ومتى وانتهى --}}
    @php $apEnded = $apHistory->filter(fn ($h) => $h->ended_at !== null); @endphp
    @if ($apEnded->isNotEmpty())
        <details style="margin-top:10px">
            <summary class="sub" style="cursor:pointer">🗂️ تاريخُ التخصيصات <span class="bdg">{{ $apEnded->count() }}</span></summary>
            <div class="tblwrap" style="margin-top:6px"><table>
                <thead><tr><th>المشروع</th><th>مُذ</th><th>انتهى</th></tr></thead>
                <tbody>
                @foreach ($apEnded as $h)
                    <tr>
                        <td class="sub">{{ \Illuminate\Support\Str::limit($h->project?->name ?? $h->project_id, 36) }}</td>
                        <td class="mono sub">{{ optional($h->assigned_at)->format('Y-m-d') }}</td>
                        <td class="mono sub">{{ optional($h->ended_at)->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </details>
    @endif
</div>
<style>.ap-assign summary { cursor:pointer; list-style:none } .ap-assign summary::-webkit-details-marker { display:none }</style>
