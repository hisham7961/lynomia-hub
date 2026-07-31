@extends('layouts.app')
@section('title', 'غرفة البيانات')
@section('content')
<div class="hero">
    <div>
        <h2>🔐 غرفة البيانات</h2>
        <div class="sub">شارك مستنداً حساساً برابط مؤقت — كلمة مرور، انتهاء، منع تنزيل بعلامة مائية، سجل مشاهدة، وإلغاء فوري</div>
    </div>
</div>

@if (session('newlink'))
    <div class="card" style="border-color:var(--ok)">
        <b>🔗 رابطك الجديد جاهز — انسخه الآن:</b>
        <div class="mono ltr" style="background:var(--pss);border-radius:10px;padding:10px;margin-top:8px;word-break:break-all;user-select:all">{{ session('newlink') }}</div>
    </div>
@endif

<div class="card">
    <h3>＋ مشاركة مستند جديد</h3>
    <form method="POST" action="{{ route('dataroom.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="fg">
            <div class="fld"><label>عنوان المستند <span class="req">*</span></label>
                <input class="inp" name="title" required maxlength="190" placeholder="مثال: القوائم المالية 2026 للمحاسب"></div>
            <div class="fld"><label>الملف <span class="req">*</span></label>
                <input class="inp" type="file" name="file" required>
                @error('file')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="fld"><label>كلمة مرور <span class="sub">(اختياري)</span></label>
                <input class="inp ltr" name="password" maxlength="100" autocomplete="new-password"></div>
            <div class="fld"><label>ينتهي بعد (أيام) <span class="sub">(اختياري)</span></label>
                <input class="inp ltr" type="number" name="days" min="1" max="365" placeholder="مثال: 7"></div>
            <div class="fld fw"><label style="display:flex;gap:8px;align-items:center;cursor:pointer">
                <input type="checkbox" name="no_download" value="1"> عرض فقط — منع التنزيل + علامة مائية بهوية المشاهد</label></div>
        </div>
        <div class="formfoot"><button class="btn p">إنشاء الرابط</button></div>
    </form>
</div>

<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>المستند</th><th>الحماية</th><th>المشاهدات</th><th>الحالة</th><th class="acts">إجراء</th></tr></thead>
        <tbody>
        @forelse ($links as $l)
            @php $dead = $l->isDead(); $lv = $lastViews[$l->id] ?? collect(); @endphp
            <tr style="{{ $dead ? 'opacity:.55' : '' }}">
                <td><b>{{ $l->title }}</b>
                    <div class="sub">أُنشئ {{ $l->created_at->diffForHumans() }}</div>
                    @unless ($dead)<div class="mono ltr sub" style="font-size:10.5px;word-break:break-all;user-select:all">{{ route('share.show', $l->token) }}</div>@endunless
                </td>
                <td class="sub">{{ $l->password_hash ? '🔑 كلمة مرور' : 'بلا كلمة مرور' }}
                    · {{ $l->expires_at ? 'ينتهي ' . $l->expires_at->format('m-d') : 'بلا انتهاء' }}
                    · {{ $l->no_download ? '👁 عرض فقط' : '⬇ تنزيل مسموح' }}</td>
                <td><b>{{ $l->views }}</b>
                    @if ($lv->count())<div class="sub" title="{{ $lv->take(5)->map(fn ($v) => $v->ip . ' · ' . \Illuminate\Support\Carbon::parse($v->created_at)->format('m-d H:i'))->implode("\n") }}">آخرها {{ \Illuminate\Support\Carbon::parse($lv->first()->created_at)->diffForHumans() }} من {{ $lv->first()->ip }}</div>@endif
                </td>
                <td>@if ($l->revoked)<span class="bdg bad">مُلغى</span>
                    @elseif ($l->expires_at && now()->gt($l->expires_at))<span class="bdg bad">منتهٍ</span>
                    @else<span class="bdg ok">نشط</span>@endif</td>
                <td class="acts">
                    @unless ($dead)
                        <form method="POST" action="{{ route('dataroom.revoke', $l->id) }}" data-confirm="إلغاء الرابط فوراً؟ لا يمكن التراجع.">
                            @csrf<button class="btn ghost xs" style="color:var(--bad)">إلغاء</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">لا روابط بعد — شارك أول مستند من الأعلى</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
