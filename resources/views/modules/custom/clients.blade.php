{{-- **عميل ٣٦٠°**: صحتُه بأسبابها، وأرقامُ علاقته كلِّها (ارتباطات، مشاريع،
     عقود، تذاكر، فواتير، أصولٌ نديرها له)، ودخولُ مساحته بضغطة. --}}
@php
    $c360 = [
        'engagements' => \App\Models\Engagement::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereNotIn('status', ['منتهٍ', 'ملغى'])->count(),
        'projects' => \App\Models\Project::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereNotIn('status', ['مكتمل', 'ملغى'])->count(),
        'contracts' => \App\Models\Contract::whereNull('deleted_at')->where('client_id', $row->id)
            ->where('status', 'ساري')->count(),
        'tickets' => \App\Models\Ticket::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereNotIn('status', ['تم الحل', 'مغلقة'])->count(),
        'unpaid' => \App\Models\FinDocument::whereNull('deleted_at')->where('client_id', $row->id)
            ->whereIn('kind', config('hub.fin.income', []))
            ->whereNotIn('state', ['مدفوعة', 'ملغاة', 'مسودة'])->count(),
        'assets' => \App\Models\Asset::whereNull('deleted_at')->where('client_id', $row->id)->count(),
    ];
    $cHealth = \App\Support\Engagements::health($row);
@endphp
<div class="card">
    <h3 class="cardtitle">🧭 العميل ٣٦٠°
        <span class="bdg {{ $cHealth['tone'] === 'أخضر' ? 'ok' : ($cHealth['tone'] === 'أصفر' ? 'wn' : 'bad') }}"
              title="صحةُ العلاقة — مركّبةٌ من التذاكر والفواتير والمشاريع والتجديدات">
            الصحة: {{ $cHealth['tone'] }}</span>
        <form method="POST" action="{{ route('client.switch') }}" class="inline" style="margin-inline-start:auto">
            @csrf
            <input type="hidden" name="client" value="{{ $row->id }}">
            <button class="btn p xs" title="كل القوائم تتصفى على هذا العميل حتى تعود">🏢 دخول مساحة العمل</button>
        </form>
    </h3>
    @if ($cHealth['why'])
        <div class="crow" style="margin-bottom:8px">
            @foreach ($cHealth['why'] as $w)<span class="bdg wn">{{ $w }}</span>@endforeach
        </div>
    @endif
    <div class="cards">
        <div class="stat"><span class="ico">🤝</span><b>{{ $c360['engagements'] }}</b><span>ارتباطاً نشطاً</span></div>
        <div class="stat"><span class="ico">🗂️</span><b>{{ $c360['projects'] }}</b><span>مشروعاً جارياً</span></div>
        <div class="stat"><span class="ico">📜</span><b>{{ $c360['contracts'] }}</b><span>عقداً سارياً</span></div>
        <div class="stat"><span class="ico">🎫</span><b>{{ $c360['tickets'] }}</b><span>تذكرة مفتوحة</span></div>
        <div class="stat"><span class="ico">🧾</span><b>{{ $c360['unpaid'] }}</b><span>فاتورة غير مسددة</span></div>
        <div class="stat"><span class="ico">📦</span><b>{{ $c360['assets'] }}</b><span>أصلاً نديره له</span></div>
    </div>
    @if (hub_can(auth()->user(), 'engagements', 'a'))
        <a class="btn ghost sm" href="{{ route('m.create', 'engagements') }}">＋ ارتباط جديد لهذا العميل</a>
    @endif
    <span class="sub" style="margin-inline-start:8px">تفاصيلُ كل صنفٍ في «السجلات المرتبطة» أدناه — لا نسخةَ ثانية.</span>
</div>

{{-- **أعضاءُ مساحة العميل** (Work OS · الطور B · WP-B.3 · §13/§98): لوحةٌ داخليّةٌ
     لمديرِ الحساب — تُعدّد الأعضاءَ بأدوارهم وحالاتهم، وتدعو زميلاً للتفعيل، وتمنح
     دوراً، وتسحب وصولاً. الحرسُ في المتحكّم (`hub_can('clients','e')` + تصعيدٌ
     لمنح Owner/سحب الوصول)؛ هنا نُخفي أزرارَ الكتابة عمّن لا يملكها فحسب. --}}
@php
    $cmRoles = ['owner' => 'مالك (العميل)', 'lead' => 'قائد', 'technical' => 'تقنيّ', 'finance' => 'ماليّة', 'viewer' => 'مشاهد'];
    $cmStatus = ['active' => ['فعّال', 'ok'], 'invited' => ['مدعوّ', 'wn'], 'suspended' => ['معلّق', 'bad']];
    $cmCanManage = hub_can(auth()->user(), 'clients', 'e');
    // العضويّاتُ + حسابُ كلٍّ (لعرض حالة التفعيل الحقيقية) — ترتيبٌ حتميّ (status ثم id)
    $cmMembers = \App\Models\ClientMembership::where('client_id', $row->id)
        ->with('user:id,name,email,password_changed_at,account_type')
        ->orderBy('status')->orderBy('id')->get();
@endphp
<div class="card">
    <h3 class="cardtitle">👥 أعضاءُ مساحة العميل
        <span class="bdg" title="من يبلغ بوابةَ هذا العميل وبأيّ دور">{{ $cmMembers->count() }} عضواً</span>
    </h3>
    <span class="sub">مَن يبلغ مساحةَ العميل وبأيّ دور — الدعوةُ تُنشئ حسابَ عميلٍ ويضع هو كلمتَه بنفسه (لا كلمةَ سرٍّ تُرسَل).</span>

    @if ($cmMembers->isEmpty())
        <div class="empty" style="margin-top:10px">لا أعضاءَ بعد — ادعُ أوّلَ زميلٍ لمساحة هذا العميل.</div>
    @else
        <div style="overflow-x:auto;margin-top:10px">
            <table class="tbl">
                <thead><tr><th>العضو</th><th>الدور</th><th>الحالة</th><th>الحساب</th>@if ($cmCanManage)<th>إجراءات</th>@endif</tr></thead>
                <tbody>
                @foreach ($cmMembers as $mb)
                    @php [$stLabel, $stTone] = $cmStatus[$mb->status] ?? [$mb->status, 'wn']; @endphp
                    <tr>
                        <td>
                            <b>{{ $mb->user?->name ?? '—' }}</b>
                            <div class="sub mono">{{ $mb->user?->email }}</div>
                        </td>
                        <td>
                            @if ($cmCanManage)
                                <form method="POST" action="{{ route('clients.members.role', [$row->id, $mb->id]) }}" class="inline">
                                    @csrf
                                    <select name="role" class="inp xs" onchange="this.form.submit()" aria-label="دورُ العضو">
                                        @foreach ($cmRoles as $rk => $rl)
                                            <option value="{{ $rk }}" @selected($mb->role === $rk)>{{ $rl }}</option>
                                        @endforeach
                                    </select>
                                    <noscript><button class="btn xs">حفظ</button></noscript>
                                </form>
                            @else
                                <span class="bdg">{{ $cmRoles[$mb->role] ?? $mb->role }}</span>
                            @endif
                        </td>
                        <td><span class="bdg {{ $stTone }}">{{ $stLabel }}</span></td>
                        <td>
                            @if ($mb->user && $mb->user->password_changed_at === null)
                                <span class="bdg wn" title="أُرسلت دعوةُ تفعيلٍ ولم يضع كلمتَه بعد">لم يُفعّل بعد</span>
                            @else
                                <span class="bdg ok">مُفعَّل</span>
                            @endif
                        </td>
                        @if ($cmCanManage)
                            <td>
                                @if ($mb->status !== 'suspended')
                                    <form method="POST" action="{{ route('clients.members.revoke', [$row->id, $mb->id]) }}" class="inline"
                                          onsubmit="return confirm('سحبُ وصولِ هذا العضو؟ يسقط عن مساحة العميل فوراً.')">
                                        @csrf
                                        <button class="btn bad xs" title="يتطلّب تأكيدَ الهوية">سحبُ الوصول</button>
                                    </form>
                                @else
                                    <span class="sub">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($cmCanManage)
        <form method="POST" action="{{ route('clients.members.invite', $row->id) }}" class="crow" style="margin-top:12px;gap:8px;flex-wrap:wrap;align-items:end">
            @csrf
            <label class="fld"><span class="sub">بريدُ الزميل</span>
                <input type="email" name="email" required class="inp" placeholder="name@company.com"></label>
            <label class="fld"><span class="sub">الاسم (اختياري)</span>
                <input type="text" name="name" maxlength="120" class="inp" placeholder="اسمُ الزميل"></label>
            <label class="fld"><span class="sub">الدور</span>
                <select name="role" class="inp">
                    @foreach ($cmRoles as $rk => $rl)<option value="{{ $rk }}" @selected($rk === 'viewer')>{{ $rl }}</option>@endforeach
                </select></label>
            <button class="btn p">＋ دعوةُ زميل</button>
        </form>
        <span class="sub">منحُ «مالك (العميل)» أو سحبُ الوصول يتطلّب تأكيدَ الهوية.</span>
    @endif
</div>

{{-- تشريح الخسارة على صفحة العميل الخاسر — يتوقع $row --}}
@if ((string) $row->stage === 'خسارة')
    @php
        $lsComp = $row->competitor_id
            ? \App\Models\Competitor::whereNull('deleted_at')->find($row->competitor_id) : null;
    @endphp
    <div class="card">
        <h3 class="cardtitle">📉 تشريح الخسارة</h3>
        <div style="display:flex;gap:18px;flex-wrap:wrap">
            <div><div class="sub">السبب</div>
                @if ($row->lost_reason)<span class="bdg bad">{{ $row->lost_reason }}</span>
                @else<span class="sub">غير مسجَّل — سجّله ليُحتسب في تقرير «لماذا نخسر»</span>@endif
            </div>
            @if ($lsComp)
                <div><div class="sub">خسرناه لصالح</div>
                    <a href="{{ route('m.show', ['competitors', $lsComp->id]) }}">{{ $lsComp->name }}</a>
                    @if ($lsComp->threat)<span class="bdg {{ hub_tone($lsComp->threat) }}">{{ $lsComp->threat }}</span>@endif
                </div>
            @endif
            @if ($row->value)
                <div><div class="sub">القيمة الضائعة</div>
                    <b class="mono">{{ number_format((float) $row->value, 2) }} {{ setting('app.currency', 'د.ك') }}</b></div>
            @endif
        </div>
    </div>
@endif
