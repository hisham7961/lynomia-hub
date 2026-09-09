{{-- ═══════════ أصولُ المشروع (Project 360 · §19/§22/§23/§63/§67) ═══════════
     الأصولُ **المخصَّصةُ** للمشروع (تخصيصٌ تشغيليّ لا عهدة): نشطٌ + تاريخ + تخصيصٌ/إنهاء.
     داخليٌّ حصراً (يُدرَج داخل @unless($isCli)) — لا يرى العميلُ أصولاً ولا عدَّها (§12/§70).
     لغةٌ صريحة: «مخصّص للمشروع» لا «عهدة»/«حائز» (§23). --}}
@php
    use App\Support\AssetProjectService;
    $paSvc = new AssetProjectService();
    $paActive = $paSvc->activeForProject((string) $row->id);
    $paU = auth()->user();
    $paCanAssign = ! hub_is_client($paU) && hub_can($paU, 'assets', 'e') && hub_can($paU, 'projects', 'v');

    // مرشّحو التخصيص — أصولٌ ضمنَ النطاق، بشركةٍ متوافقة، غيرُ مخصَّصةٍ نشطاً لهذا المشروع،
    // وليست في حالةٍ نهائيّة. استعلامٌ واحدٌ محدود (§43 — لا N+1).
    $paCandidates = [];
    if ($paCanAssign) {
        $paAssignedIds = $paActive->pluck('asset_id')->map('strval')->all();
        $paRows = hub_scope(\App\Models\Asset::query(), 'assets')
            ->when($row->company_id !== null, fn ($q) => $q->where(fn ($w) =>
                $w->where('company_id', $row->company_id)->orWhereNull('company_id')))
            ->whereNotIn('id', $paAssignedIds ?: ['-'])
            ->whereNotIn('status', AssetProjectService::INELIGIBLE_STATUSES)
            ->orderBy('name')->limit(200)->get(['id', 'name', 'code', 'type', 'status', 'holder_id']);
        $paHolderNames = $paRows->pluck('holder_id')->filter()->unique()->isNotEmpty()
            ? hub_ref_labels('users', $paRows->pluck('holder_id')->filter()->unique()->values()->all()) : [];
        foreach ($paRows as $pc) {
            $sub = trim(($pc->code ? $pc->code . ' · ' : '') . ($pc->type ?: '')
                . ($pc->holder_id && isset($paHolderNames[$pc->holder_id]) ? ' · بيد ' . $paHolderNames[$pc->holder_id] : ''));
            $paCandidates[(string) $pc->id] = ['name' => $pc->name ?: $pc->code, 'sub' => $sub];
        }
    }
@endphp

<div class="card">
    <h3 class="cardtitle">🖥️ أصولُ المشروع
        <span class="bdg g">{{ $paActive->count() }} مخصّص</span>
    </h3>
    <div class="sub" style="margin-bottom:8px">تخصيصٌ تشغيليٌّ للمشروع — **ليس عهدةً**: قد يبقى الأصلُ بيدِ موظّفٍ أو في محطّةٍ بينما يخدم هذا المشروع.</div>

    @if ($paActive->isNotEmpty())
        <div class="tblwrap"><table>
            <thead><tr><th>الكود</th><th>الأصل</th><th>النوع</th><th>الحالة</th><th>الغرض</th><th>مُذ</th>@if ($paCanAssign)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($paActive as $pa)
                <tr>
                    <td class="mono ltr sub">{{ $pa->asset?->code }}</td>
                    <td><a href="{{ route('m.show', ['assets', $pa->asset_id]) }}">{{ \Illuminate\Support\Str::limit($pa->asset?->name ?? $pa->asset_id, 40) }}</a></td>
                    <td class="sub">{{ $pa->asset?->type ?: '—' }}</td>
                    <td><span class="bdg">{{ $pa->asset?->status ?: '—' }}</span></td>
                    <td class="sub">{{ $pa->purpose ?: '—' }}</td>
                    <td class="mono sub">{{ optional($pa->assigned_at)->format('Y-m-d') }}</td>
                    @if ($paCanAssign)
                        <td class="acts">
                            <form method="POST" action="{{ route('assetproject.end', $pa->id) }}" data-confirm="إنهاءُ تخصيصِ هذا الأصلِ للمشروع؟ (لا يمسّ العهدةَ ولا المحطّة)">
                                @csrf<button class="lnk sub" type="submit" title="إنهاءُ التخصيص">✕ إنهاء</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @else
        @include('partials.empty', ['text' => 'لا توجد أصولٌ مخصّصةٌ لهذا المشروع', 'icon' => '🖥️'])
    @endif

    @if ($paCanAssign)
        <details class="pa-assign" style="margin-top:10px">
            <summary class="btn sm">➕ تخصيصُ أصلٍ للمشروع</summary>
            <form method="POST" action="{{ route('projects.assets.assign', $row->id) }}" style="margin-top:10px">
                @csrf
                @include('partials.participant_picker', [
                    'candidates' => $paCandidates,
                    'field'      => 'assets[]',
                    'pickerId'   => 'proj-assets',
                    'label'      => 'اختر أصولاً لتخصيصها (تشغيليّاً — لا عهدة)',
                    'emptyHint'  => 'لا أصولَ متاحةً للتخصيصِ ضمنَ نطاقِ المشروع.',
                ])
                <label class="lbl" style="margin-top:8px">الغرض (اختياريّ)</label>
                <input class="inp" name="purpose" maxlength="120" placeholder="مثال: خادمُ مشروع · جهازُ اختبار · وحدةُ عرض">
                <button class="btn p" type="submit" style="margin-top:10px">تخصيصُ الأصول</button>
            </form>
        </details>
    @endif
</div>
<style>.pa-assign summary { cursor:pointer; list-style:none } .pa-assign summary::-webkit-details-marker { display:none }</style>
