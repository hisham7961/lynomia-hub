{{-- **معرض لقطات المتجر** — يتوقع $app و$shots (مرفقاتُ صور مرتَّبة).

     كانت اللقطاتُ حقلَ ملفٍ واحد يدهسه التالي، ثم صارت مرفقاتٍ في قائمةٍ نصّية:
     أسماءُ ملفاتٍ لا صور. وما يقرّر عليه المستخدمُ في المتجر صورةٌ لا اسم — فهذه
     منصّةُ عرضٍ كبيرة بإطار جهاز، وشريطُ مصغّرات يُنتقل به، وترتيبٌ يُحرَّك
     (الأولى هي أولُ ما يُرى في المتجر)، ورفعٌ متعددٌ في مكانه. --}}
@php
    $gCan = hub_can(auth()->user(), 'apps', 'e');
    $gN = $shots->count();
@endphp
<div class="card" id="shots">
    <h3>🖼️ لقطات المتجر
        <span class="bdg {{ $gN ? 'g' : 'bad' }}">{{ $gN }}</span>
        @if ($gN)
            <span class="sub msauto">الأولى هي واجهةُ صفحة المتجر — رتّبها بالأسهم</span>
        @endif
    </h3>

    @if ($gN)
        <div class="gal" data-gal>
            {{-- المنصّة: صورةٌ واحدةٌ كبيرة داخل إطار جهاز، وأسهمٌ للتنقّل --}}
            {{-- الاتجاهُ عربيّ: زرُّ «السابقة» يقع يميناً ويشير يميناً، و«التالية»
                 يساراً — والتقدّمُ في المعرض تقدّمٌ في اتجاه القراءة لا عكسه. --}}
            <div class="gstage">
                <button type="button" class="gnav" data-gprev aria-label="اللقطة السابقة">›</button>
                <div class="gframe">
                    @foreach ($shots as $i => $s)
                        <figure class="gslide {{ $i === 0 ? 'on' : '' }}" data-gslide="{{ $i }}">
                            <img src="{{ route('att.view', $s->id) }}" loading="{{ $i === 0 ? 'eager' : 'lazy' }}"
                                 alt="{{ $s->note ?: $s->original_name }}">
                        </figure>
                    @endforeach
                </div>
                <button type="button" class="gnav" data-gnext aria-label="اللقطة التالية">‹</button>
            </div>

            <div class="gbar">
                <span class="sub mono" data-gcount>1 / {{ $gN }}</span>
                <span class="spacer"></span>
                <span class="sub" data-gname>{{ \Illuminate\Support\Str::limit($shots[0]->note ?: $shots[0]->original_name, 46) }}</span>
            </div>

            {{-- شريطُ المصغّرات: نقرةٌ تنقل، وأزرارُ الترتيب والحذف تحت كلٍّ منها --}}
            <div class="gthumbs">
                @foreach ($shots as $i => $s)
                    <div class="gthumb {{ $i === 0 ? 'on' : '' }}" data-gthumb="{{ $i }}">
                        <button type="button" class="gpick" data-gpick="{{ $i }}"
                                aria-label="عرض اللقطة {{ $i + 1 }}">
                            <img src="{{ route('att.view', $s->id) }}" loading="lazy" alt="">
                            <span class="gnum">{{ $i + 1 }}</span>
                        </button>
                        <div class="gacts">
                            @if ($gCan)
                                <form method="POST" action="{{ route('att.move', $s->id) }}" class="inline">
                                    @csrf<input type="hidden" name="dir" value="up">
                                    <button class="btn ghost xs" title="تقديم" @disabled($loop->first)>⬆</button>
                                </form>
                                <form method="POST" action="{{ route('att.move', $s->id) }}" class="inline">
                                    @csrf<input type="hidden" name="dir" value="down">
                                    <button class="btn ghost xs" title="تأخير" @disabled($loop->last)>⬇</button>
                                </form>
                            @endif
                            <a class="btn ghost xs" href="{{ route('att.dl', $s->id) }}" title="تنزيل بالحجم الأصلي">⬇</a>
                            @if ($gCan)
                                <form method="POST" action="{{ route('att.destroy', $s->id) }}" class="inline"
                                      data-confirm="حذف اللقطة «{{ \Illuminate\Support\Str::limit($s->original_name, 30) }}»؟">
                                    @csrf @method('DELETE')
                                    <button class="btn ghost xs dn" title="حذف">✕</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="sub" style="padding:10px 0 14px">
            لا لقطات بعد — والمتاجرُ تشترط {{ $ready['shotsNeeded'] ?? \App\Support\AppStudio::SHOTS_APPLE }} على الأقل، وهي ما يقرّر عليه المستخدمُ
            التحميل قبل أن يقرأ سطراً. ارفعها كلَّها دفعةً واحدة من الحقل أدناه.
        </div>
    @endif

    @if ($gCan)
        <form method="POST" action="{{ route('att.store') }}" enctype="multipart/form-data" class="crow" style="margin-top:12px">
            @csrf
            <input type="hidden" name="module" value="apps">
            <input type="hidden" name="record_id" value="{{ $app->id }}">
            <input type="hidden" name="kind" value="{{ \App\Support\AppStudio::SHOT_KIND }}">
            <label class="vh" for="shot-files">اختر لقطات (عدة صور معاً)</label>
            {{-- أنواعٌ مسمّاةٌ لا `image/*`: القبولُ الصريح يُرشّح نافذةَ الاختيار
                 على ما تقبله المتاجر فعلاً، ولا يُغري برفع HEIC لا يُعرَض. --}}
            <input class="inp" id="shot-files" type="file" multiple required
                   name="files[]" accept="image/png,image/jpeg,image/webp">
            <button class="btn p sm" type="submit">＋ رفع اللقطات</button>
            <span class="sub">تُرفع بترتيب اختيارها وتُضاف في آخر المعرض — حتى
                {{ hub_bytes(hub_upload_cap()['appKb'] * 1024) }} للملف، بعدّاد تقدّم،
                والكبيرُ يُقطَّع تلقائياً ليمرّ من سقف الخادم.</span>
        </form>
        @error('files')<div class="err">{{ $message }}</div>@enderror
        @foreach ($errors->get('files.*') as $fmsgs)
            @foreach ($fmsgs as $fmsg)<div class="err">{{ $fmsg }}</div>@endforeach
        @endforeach
    @endif
</div>

<style>
.gal{--gh:420px}
.gstage{display:flex;align-items:center;gap:10px}
.gframe{flex:1;min-width:0;height:var(--gh);border-radius:18px;border:1px solid var(--ln);
    background:linear-gradient(180deg,var(--bg2),var(--cd));display:flex;align-items:center;
    justify-content:center;overflow:hidden;position:relative}
.gslide{display:none;width:100%;height:100%;align-items:center;justify-content:center;margin:0;padding:12px}
.gslide.on{display:flex}
.gslide img{max-width:100%;max-height:100%;object-fit:contain;border-radius:10px;
    box-shadow:0 6px 26px rgba(0,0,0,.16);background:#fff}
.gnav{flex:none;width:38px;height:38px;border-radius:50%;border:1px solid var(--ln);background:var(--cd);
    color:var(--tx);font-size:22px;line-height:1;cursor:pointer;transition:background .15s,transform .15s}
.gnav:hover{background:var(--pss);color:var(--pd);transform:scale(1.06)}
.gbar{display:flex;align-items:center;gap:10px;margin-top:8px}
.gthumbs{display:flex;gap:10px;overflow-x:auto;padding:10px 2px 2px;scroll-snap-type:x proximity}
.gthumb{flex:none;width:112px;scroll-snap-align:start}
.gpick{display:block;width:112px;height:150px;padding:0;border-radius:12px;overflow:hidden;cursor:pointer;
    border:2px solid var(--ln);background:var(--bg2);position:relative}
.gthumb.on .gpick{border-color:var(--p);box-shadow:0 0 0 3px color-mix(in srgb,var(--p) 18%,transparent)}
.gpick img{width:100%;height:100%;object-fit:cover;display:block}
.gnum{position:absolute;inset-inline-start:4px;top:4px;background:rgba(0,0,0,.6);color:#fff;
    border-radius:6px;font-size:11px;padding:1px 6px;font-weight:700}
/* أزرارُ اللقطة صفٌّ واحدٌ تحتها: تقديمٌ وتأخيرٌ وتنزيلٌ وحذف — والالتفافُ
   إلى سطرين كان يجعل الشريطَ يقفز بارتفاعه كلّما تغيّر عددُ اللقطات */
.gacts{display:flex;gap:2px;justify-content:center;margin-top:4px;flex-wrap:nowrap}
.gacts .btn.xs{padding:3px 6px;font-size:11px}
@media (max-width:700px){.gal{--gh:300px}}
</style>

<script>
/* سلايدر المعرض: منصّةٌ واحدةٌ تتبدّل — بلا مكتبة ولا اعتمادٍ على شبكة.
   السهمان يدوران (الأخيرة ← الأولى)، والمصغّرات تنقل مباشرةً، والأسهمُ في
   لوحة المفاتيح تعمل حين يكون المعرض في التركيز. */
(function () {
    var g = document.querySelector('[data-gal]');
    if (!g) return;
    var slides = g.querySelectorAll('[data-gslide]');
    var thumbs = g.querySelectorAll('[data-gthumb]');
    var count = g.querySelector('[data-gcount]');
    var name = g.querySelector('[data-gname]');
    var names = [@foreach ($shots as $s)@json(\Illuminate\Support\Str::limit($s->note ?: $s->original_name, 46)),@endforeach];
    var i = 0;
    function go(n) {
        if (!slides.length) return;
        i = (n + slides.length) % slides.length;
        slides.forEach(function (s, k) { s.classList.toggle('on', k === i); });
        thumbs.forEach(function (t, k) { t.classList.toggle('on', k === i); });
        if (count) count.textContent = (i + 1) + ' / ' + slides.length;
        if (name && names[i]) name.textContent = names[i];
        var on = thumbs[i]; if (on && on.scrollIntoView) on.scrollIntoView({block: 'nearest', inline: 'nearest'});
    }
    var prev = g.querySelector('[data-gprev]'), next = g.querySelector('[data-gnext]');
    if (prev) prev.addEventListener('click', function () { go(i - 1); });
    if (next) next.addEventListener('click', function () { go(i + 1); });
    g.querySelectorAll('[data-gpick]').forEach(function (b) {
        b.addEventListener('click', function () { go(parseInt(b.dataset.gpick, 10) || 0); });
    });
    /* في واجهةٍ عربية: اليسارُ يتقدّم واليمينُ يرجع — عكسُ الإنجليزية تماماً */
    document.addEventListener('keydown', function (e) {
        if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) return;
        if (e.key === 'ArrowLeft') go(i + 1);
        else if (e.key === 'ArrowRight') go(i - 1);
    });
})();
</script>
