{{-- محرّرُ إعدادات الجوال لقسمٍ واحد (خطّةُ التطبيق · 2.1) — يتوقّع: $section (release|deeplinks|push)
     و$settings (MobileSettings::values — السرُّ حضورٌ لا قيمة). نموذجٌ عاديّ بلا معالِجٍ مضمَّن (CSP). --}}
@php
    use App\Support\Mobile\MobileSettings as MS;
    $bag = $errors->getBag('mobileSettings');
    $val = fn (string $f) => (string) old($f, $settings[$f] ?? '');
    $titles = [
        'release'   => ['🏷️ ضبطُ الإصدارات والمتجر والدعم', 'semver X.Y.Z، والأدنى لا يتجاوز الأحدث؛ روابطُ المتجر https حصراً، والدعم https أو mailto. الفارغُ يعيد الافتراض (لا حجب/NOT_CONFIGURED).'],
        'deeplinks' => ['🔗 ضبطُ الروابط العميقة', 'Team ID عشرةُ أحرف، Bundle/Package بصيغة com.example.app، وبصماتُ SHA-256 بصيغة AA:BB:… (32 زوجاً) — واحدةٌ في كلِّ سطرٍ أو مفصولةٌ بفاصلة.'],
        'push'      => ['🔐 ضبطُ مزوّد الدفع (FCM)', 'حسابُ الخدمة (المفضَّل) ورمزُ الوصول (احتياطٌ ينتهي خلال ساعة) سرّان للكتابة فقط: يُخزَّنان مشفَّرَين ولا يُعرَضان أبداً — اتركهما فارغَين لإبقاء المخزَّن. معرّفُ المشروع يُؤخذ من حساب الخدمة إن تُرك فارغاً.'],
    ];
    $text = [
        'release' => ['min_version_ios' => '1.0.0', 'latest_version_ios' => '1.4.0', 'min_version_android' => '1.0.0',
            'latest_version_android' => '1.4.0', 'store_url_ios' => 'https://apps.apple.com/app/…',
            'store_url_android' => 'https://play.google.com/store/apps/details?id=…', 'support_url' => 'https://… أو mailto:…'],
        'deeplinks' => ['dl_apple_team_id' => 'ABCDE12345', 'dl_apple_bundle_id' => 'com.example.app', 'dl_android_package' => 'com.example.app'],
        'push' => ['push_fcm_project_id' => 'my-firebase-project'],
    ][$section];
@endphp
<div class="card kid wide" id="mps-{{ $section }}">
    <h3>{{ $titles[$section][0] }}</h3>
    <p class="sub">{{ $titles[$section][1] }} كلُّ تغييرٍ يُدقَّق (قيدٌ لكلِّ مفتاح) ويصل التطبيقَ في app-config التالي.</p>
    @if ($bag->any())
        <div class="ferr" style="margin:6px 0">
            @foreach ($bag->all() as $m)<div>{{ $m }}</div>@endforeach
        </div>
    @endif
    <form method="POST" action="{{ route('mobileplatform.settings.save', $section) }}" class="toolbar"
          style="gap:10px;flex-wrap:wrap;align-items:flex-end">@csrf
        @foreach ($text as $f => $ph)
            <label class="sub" id="mps-{{ $f }}" style="min-width:min(260px,100%)">{{ MS::LABELS['mobile.' . $f] }}
                <input class="inp ltr" type="text" name="{{ $f }}" value="{{ $val($f) }}" placeholder="{{ $ph }}"
                       dir="ltr" autocomplete="off" maxlength="500">
                @if ($bag->has($f))<span class="ferr">{{ $bag->first($f) }}</span>@endif
            </label>
        @endforeach

        @if ($section === 'release')
            <label class="sub" id="mps-force_update"><input type="checkbox" name="force_update" value="1"
                @checked(old('force_update', $settings['force_update'] ?? '0') === '1')> {{ MS::LABELS['mobile.force_update'] }}
                <span class="sub">(دون الأدنى ⇒ شاشةُ تحديثٍ حاجبة)</span></label>
        @elseif ($section === 'deeplinks')
            <label class="sub" id="mps-dl_android_fingerprints" style="min-width:min(520px,100%)">{{ MS::LABELS['mobile.dl_android_fingerprints'] }}
                <textarea class="inp ltr mono" name="dl_android_fingerprints" rows="3" dir="ltr"
                          placeholder="AA:BB:CC:…:FF">{{ str_replace(',', "\n", $val('dl_android_fingerprints')) }}</textarea>
                @if ($bag->has('dl_android_fingerprints'))<span class="ferr">{{ $bag->first('dl_android_fingerprints') }}</span>@endif
            </label>
        @else
            <label class="sub" id="mps-push_driver">{{ MS::LABELS['mobile.push_driver'] }}
                <select class="inp" name="push_driver">
                    <option value="" @selected($val('push_driver') === '')>— غير مُهيّأ (NullPushProvider)</option>
                    <option value="fcm" @selected($val('push_driver') === 'fcm')>fcm — Firebase Cloud Messaging</option>
                </select>
                @if ($bag->has('push_driver'))<span class="ferr">{{ $bag->first('push_driver') }}</span>@endif
            </label>
            <label class="sub" id="mps-push_fcm_service_account" style="min-width:min(520px,100%)">{{ MS::LABELS[MS::SA_KEY] }}
                <span class="bdg {{ ! empty($settings['sa_set']) ? 'ok' : 'g' }}">{{ ! empty($settings['sa_set']) ? '🔐 مضبوط' : 'غير مضبوط' }}</span>
                {{-- كتابةٌ فقط: لا يُعاد محتواه أبداً — منه يُسكّ رمزُ OAuth خادميّاً ويُجدَّد قبل انتهائه --}}
                <textarea class="inp ltr mono" name="push_fcm_service_account" rows="3" dir="ltr" autocomplete="off"
                          placeholder="الصق ملفّ حساب الخدمة JSON — اتركه فارغاً لإبقاء المخزَّن"></textarea>
                @if ($bag->has('push_fcm_service_account'))<span class="ferr">{{ $bag->first('push_fcm_service_account') }}</span>@endif
            </label>
            @if (! empty($settings['sa_set']))
                <label class="sub"><input type="checkbox" name="clear_fcm_service_account" value="1"> امسح حسابَ الخدمة المخزَّن</label>
            @endif
            <label class="sub" id="mps-push_fcm_access_token" style="min-width:min(320px,100%)">{{ MS::LABELS[MS::TOKEN_KEY] }}
                <span class="bdg {{ ! empty($settings['token_set']) ? 'ok' : 'g' }}">{{ ! empty($settings['token_set']) ? '🔐 مضبوط' : 'غير مضبوط' }}</span>
                {{-- كتابةٌ فقط: لا value أبداً، ولا يُعاد عند خطأ التحقّق --}}
                <input class="inp ltr" type="password" name="push_fcm_access_token" value="" dir="ltr"
                       autocomplete="new-password" placeholder="اتركه فارغاً لإبقاء المخزَّن">
                @if ($bag->has('push_fcm_access_token'))<span class="ferr">{{ $bag->first('push_fcm_access_token') }}</span>@endif
            </label>
            @if (! empty($settings['token_set']))
                <label class="sub"><input type="checkbox" name="clear_fcm_access_token" value="1"> امسح الرمزَ المخزَّن</label>
            @endif
        @endif

        <button type="submit" class="btn p sm">💾 احفظ</button>
    </form>
</div>
