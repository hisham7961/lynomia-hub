{{-- المرفقات الشاملة — يتوقع: $aModule $aRecordId $attachments $aUsers (id=>name) --}}
@php
    // **رؤيةُ الوثيقة تتبع القاعدة**: الوثيقةُ الممنوعةُ صراحةً عن المستخدمِ لا تُعرَض
    // أصلاً — لا اسمَ، ولا مصغّرة، ولا عدّ. (المالكُ يتجاوز، فيرى الكلَّ.) تحميلٌ دفعيٌّ
    // للقواعدِ ثمّ ترشيح (لا N+1). فلا «رؤيةٌ غير مُفسَّرة» في القائمة نفسِها.
    $attachments = \App\Support\DocumentPolicy::filterListable(auth()->user(), $attachments);

    // التحكّمُ بوصولِ الوثيقةِ على مستوى المورد (Permissions 360 · وثائق · المستوى 5/6):
    // للمالكِ أو محرِّرِ الوحدة. تُحمَّل القواعدُ والأدوارُ دفعةً واحدةً (لا N+1).
    $aCanAcl = hub_is_owner() || hub_can(auth()->user(), $aModule, 'e');
    $aAcl = ($aCanAcl && \Illuminate\Support\Facades\Schema::hasTable('document_access_rules'))
        ? \Illuminate\Support\Facades\DB::table('document_access_rules')
            ->where('resource_type', 'attachment')
            ->whereIn('resource_id', $attachments->pluck('id'))
            ->get()->groupBy('resource_id')
        : collect();
    $aRoles = $aCanAcl ? \App\Models\Role::where('is_owner', false)->orderBy('name')->get(['id', 'name']) : collect();
    // الأشخاصُ الداخليّون (لا عملاء) — لسماحِ/منعِ أفرادٍ بأعيانهم
    $aPeople = $aCanAcl ? \App\Models\User::where('status', 'نشط')
        ->where(fn ($q) => $q->whereNull('account_type')->orWhere('account_type', '!=', 'client'))
        ->orderBy('name')->limit(500)->get(['id', 'name']) : collect();
    // خرائطُ الأسماءِ لعرضِ القواعدِ بالاسمِ لا بالمعرِّف
    $aRoleNames = $aRoles->pluck('name', 'id');
    $aPeopleNames = $aPeople->pluck('name', 'id');
@endphp
<div class="card" id="attachments">
    <h3>📎 المرفقات <span class="bdg g">{{ $attachments->count() }}</span>
        {{-- حزمةٌ واحدةٌ بدل اثنتي عشرة ضغطة — بالأسماء الأصلية كما رُفعت --}}
        @if ($attachments->count() > 1 && class_exists(\ZipArchive::class))
            <a class="btn ghost xs" style="margin-inline-start:auto"
               href="{{ route('att.zip', [$aModule, $aRecordId]) }}"
               title="تنزيل كل مرفقات هذا السجل في ملف مضغوط">⬇ تحميل الكل (ZIP)</a>
        @endif
    </h3>

    @forelse ($attachments as $a)
        @php
            $isImg = in_array($a->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'], true);
            $isPdf = $a->mime === 'application/pdf';
            $ico = $isImg ? '🖼️' : ($isPdf ? '📕'
                : (str_contains((string) $a->mime, 'sheet') || str_contains((string) $a->mime, 'csv') ? '📊' : '📄'));
        @endphp
        <div class="arow" id="att-{{ $a->id }}" style="padding:7px 0;border-bottom:1px solid var(--brd)">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                {{-- الصور والشهادات واللوجوهات تُرى قبل أي ضغطة: مصغّرة حية لا أيقونة صمّاء --}}
                @if ($isImg)
                    <img src="{{ route('att.view', $a->id) }}" alt="" loading="lazy"
                         style="width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid var(--ln);background:#fff">
                @else
                    <span aria-hidden="true" style="font-size:22px">{{ $ico }}</span>
                @endif
                <div style="min-width:0;flex:1">
                    <a href="{{ route('att.dl', $a->id) }}"><b>{{ \Illuminate\Support\Str::limit($a->original_name, 60) }}</b></a>
                    <div class="sub">
                        {{ hub_bytes($a->size) }} · {{ $aUsers[$a->uploaded_by] ?? '—' }}
                        · {{ optional($a->created_at)->format('Y-m-d H:i') }}
                        @if ($a->downloads) · ⬇ {{ $a->downloads }}@endif
                        @php $aNote = $a->note ?: $a->field; @endphp
                        @if ($a->kind) · <span class="bdg g">{{ hub_doc_label($aModule, $a->kind) }}</span>@endif
                        @if ($a->kind && hub_doc_sensitive($aModule, $a->kind)) <span class="bdg bad" title="نوعٌ حسّاس: بياناتٌ شخصيّة/ماليّة — يُنصَح بضبطِ وصولٍ صريح">🔴 حسّاس</span>@endif
                        @if ($a->doc_no) · رقم {{ $a->doc_no }}@endif
                        @if ($a->expires_at) · ينتهي {{ $a->expires_at->toDateString() }}@endif
                        @if ($aNote) · <span title="ملاحظة">{{ \Illuminate\Support\Str::limit($aNote, 60) }}</span>@endif
                    </div>
                </div>
                @if ($isImg || $isPdf)
                    <a class="btn ghost xs" href="{{ route('att.view', $a->id) }}" target="_blank" rel="noopener" title="فتح المعاينة في تبويب">↗</a>
                @endif
                {{-- زرُّ تنزيلٍ صريح: الاسمُ كان رابطَ التنزيل الوحيد، ومن رآه
                     ظنّه فتحاً للمعاينة فلم يجد باباً لأخذ نسخته. --}}
                <a class="btn ghost xs" href="{{ route('att.dl', $a->id) }}"
                   title="تنزيل «{{ \Illuminate\Support\Str::limit($a->original_name, 40) }}» بصيغته الأصلية">⬇ تحميل</a>
                @if ($a->uploaded_by === auth()->id() || hub_is_owner() || hub_can(auth()->user(), $aModule, 'e'))
                    <form method="POST" action="{{ route('att.destroy', $a->id) }}" class="inline"
                          {{-- الاسم في سمة HTML مُهرَّبة — لا سياق JS فلا صنف الحقن القديم أصلاً --}}
                          data-confirm="حذف المرفق «{{ \Illuminate\Support\Str::limit($a->original_name, 40) }}»؟">
                        @csrf @method('DELETE')
                        <button class="btn ghost xs" aria-label="حذف المرفق {{ $a->original_name }}">حذف</button>
                    </form>
                @endif
            </div>
            {{-- التحكّمُ بالوصول (المستوى 5/6): سماحٌ/منعٌ لهذه الوثيقةِ بعينها لدورٍ — للمالك/المحرِّر --}}
            @if ($aCanAcl)
                @php $aRows = $aAcl[$a->id] ?? collect(); @endphp
                <details style="margin-top:4px">
                    <summary class="sub pointer">🔒 التحكّم بالوصول @if ($aRows->count()) <span class="bdg wn">{{ $aRows->count() }} قاعدة</span>@endif</summary>
                    <div style="padding:6px 0">
                        @foreach ($aRows as $ar)
                            @php $arName = $ar->principal_type === 'role'
                                ? ($aRoleNames[$ar->principal_id] ?? 'دورٌ محذوف')
                                : ($aPeopleNames[$ar->principal_id] ?? 'مستخدمٌ محذوف'); @endphp
                            <div class="sub" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                <span class="bdg {{ $ar->effect === 'deny' ? 'bad' : 'ok' }}">{{ $ar->effect === 'deny' ? 'منع' : 'سماح' }}</span>
                                <span>{{ $ar->principal_type === 'role' ? '👥 دور' : '👤 شخص' }}: <b>{{ $arName }}</b></span>
                                <form method="POST" action="{{ route('att.access.clear', [$a->id, $ar->id]) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <button class="btn ghost xs" aria-label="إزالة قاعدة الوصول">إزالة</button>
                                </form>
                            </div>
                        @endforeach
                        {{-- ضبطةٌ واحدة: عدّةُ أدوارٍ و/أو عدّةُ أشخاص (اختر عدّةً بـCtrl/⌘ أو السحب) --}}
                        <form method="POST" action="{{ route('att.access', $a->id) }}" style="margin-top:6px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                            @csrf
                            <div>
                                <label class="sub" for="acl-roles-{{ $a->id }}">👥 الأدوار</label>
                                <select class="inp" id="acl-roles-{{ $a->id }}" name="roles[]" multiple size="4" style="min-width:180px">
                                    @foreach ($aRoles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="sub" for="acl-users-{{ $a->id }}">👤 الأشخاص</label>
                                <select class="inp" id="acl-users-{{ $a->id }}" name="users[]" multiple size="4" style="min-width:180px">
                                    @foreach ($aPeople as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="sub" for="acl-effect-{{ $a->id }}">القرار</label>
                                <select class="inp" id="acl-effect-{{ $a->id }}" name="effect" style="max-width:120px">
                                    <option value="allow">سماح</option>
                                    <option value="deny">منع</option>
                                </select>
                            </div>
                            <button class="btn ghost sm" type="submit">حفظ للمحدَّدين</button>
                        </form>
                        <div class="sub" style="margin-top:3px">اختر أكثر من دورٍ أو شخصٍ معاً (Ctrl/⌘ أو السحب) ثمّ «حفظ للمحدَّدين».</div>
                    </div>
                </details>
            @endif
            {{-- معاينة كاملة داخل الصفحة نفسها — بلا منبثقات: الصورة تتمدد وPDF بعارضه --}}
            @if ($isImg || $isPdf)
                <details style="margin-top:4px">
                    <summary class="sub pointer">👁 معاينة كاملة داخل الصفحة</summary>
                    @if ($isImg)
                        <img src="{{ route('att.view', $a->id) }}" alt="{{ $a->original_name }}" loading="lazy"
                             style="max-width:100%;max-height:70vh;border-radius:12px;border:1px solid var(--ln);margin-top:6px;background:#fff">
                    @else
                        <iframe src="{{ route('att.view', $a->id) }}" title="{{ $a->original_name }}" loading="lazy"
                                style="width:100%;height:60vh;border:1px solid var(--ln);border-radius:12px;margin-top:6px;background:#fff"></iframe>
                    @endif
                </details>
            @endif
        </div>
    @empty
        <div class="sub" style="padding:8px 0 14px">لا مرفقات — أرفق عقداً، إيصالاً، تصميماً، أو أي ملف يخص هذا السجل</div>
    @endforelse

    <form method="POST" action="{{ route('att.store') }}" enctype="multipart/form-data" class="crow" style="margin-top:10px">
        @csrf
        <input type="hidden" name="module" value="{{ $aModule }}">
        <input type="hidden" name="record_id" value="{{ $aRecordId }}">
        {{-- **رفعٌ متعدد**: لقطاتُ المتجر تُرفع ثمانياً وعشراً، وكانت دورةُ الرفع
             كلُّها تُكرَّر لكل صورة — فمن ملّ في السادسة ترك النصف. --}}
        <label class="vh" for="att-file">اختر ملفاً أو أكثر</label>
        <input class="inp" id="att-file" type="file" name="files[]" multiple required
               title="يمكن اختيار عدة ملفات معاً (حتى {{ \App\Http\Controllers\Web\AttachmentController::BATCH_MAX }})">
        <label class="vh" for="att-note">ملاحظة عن الملف</label>
        <input class="inp" id="att-note" type="text" name="note" maxlength="300" placeholder="ملاحظة (اختياري)">
        @php $aSpec = hub_doc_spec($aModule); @endphp
        @if ($aSpec)
            <label class="vh" for="att-kind">نوع الوثيقة</label>
            <select class="inp" id="att-kind" name="kind">
                <option value="">— نوع الوثيقة (اختياري) —</option>
                @foreach ($aSpec as $d)
                    <option value="{{ $d['key'] }}" @selected(request()->query('kind') === $d['key'])>{{ $d['label'] }}</option>
                @endforeach
            </select>
            <label class="vh" for="att-docno">رقم الوثيقة</label>
            <input class="inp" id="att-docno" type="text" name="doc_no" maxlength="80" placeholder="رقمها (اختياري)" style="max-width:140px">
            <label class="vh" for="att-exp">تاريخ الانتهاء</label>
            <input class="inp" id="att-exp" type="date" name="expires_at" title="تاريخ الانتهاء — يدخل رادار «ينتهي قريباً»" style="max-width:160px">
        @endif
        <button class="btn p sm" type="submit">إرفاق</button>
    </form>
    @php $upCap = hub_upload_cap(); @endphp
    <div class="sub" style="margin-top:6px">
        الحدّ الأقصى للملف: <b>{{ hub_bytes($upCap['appKb'] * 1024) }}</b>
        @if ($upCap['byPhp'])
            {{-- الملفُّ الكبير لا يُرسَل دفعةً واحدة بل قطعاً تمرّ من سقف الخادم،
                 فالسقفُ المعلن هو حدُّ النظام لا حدُّ الطلب الواحد --}}
            — الأكبر من {{ hub_bytes($upCap['chunkAt'] * 1024) }} يُرفع
            <b>مقطَّعاً تلقائياً</b> ليتجاوز سقفَ الخادم ({{ hub_bytes($upCap['phpKb'] * 1024) }})
        @endif
        · يظهر عدّادُ التقدّم أثناء الرفع.
    </div>
    @error('kind')<div class="err">{{ $message }}</div>@enderror
    @error('file')<div class="err">{{ $message }}</div>@enderror
    @error('files')<div class="err">{{ $message }}</div>@enderror
    {{-- خطأُ ملفٍ بعينه في الدفعة يُسمّى: «الملف الثالث أكبر من الحد» لا رسالةٌ عامة --}}
    @foreach ($errors->get('files.*') as $fkey => $fmsgs)
        @foreach ($fmsgs as $fmsg)<div class="err">{{ $fmsg }}</div>@endforeach
    @endforeach
</div>
