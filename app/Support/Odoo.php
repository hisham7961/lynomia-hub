<?php

namespace App\Support;

use App\Models\OdooConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * عميل أودو للعرض فقط — JSON-RPC بلا أي اعتماديات.
 *
 * **اتصالاتٌ متعددة**: الاتصالُ الافتراضي من الإعدادات
 * (odoo.url · odoo.db · odoo.user · odoo.key — تُقرأ هنا وحدها، يحرسها
 * اختبارُ مصدر)، وخوادمُ إضافية من جدول `odoo_connections` — فبعضُ
 * المشاريع لها أودو خاص. `Odoo::for($id)` يعطيك نسخةً لاتصالٍ بعينه،
 * و`Odoo::forRow($row)` يقرأ اختيارَ السجل من `meta['odoo']['conn']`.
 *
 * **اتصالٌ محذوفٌ أو معطّل لا يسقط صامتاً إلى خادمٍ آخر** — يعود نسخةً
 * «ميتة» خطؤها ظاهرٌ في كل شاشة: أرقامُ شركةٍ أخرى أخطرُ من لا أرقام.
 *
 * كل الاستدعاءات قراءة (search_read / search_count / read_group) —
 * لا كتابة محاسبية إطلاقاً، ويفرضها حارسُ مصدرٍ لا وعدُ توثيق.
 * ومفاتيحُ الكاش تحمل معرّفَ الاتصال: لا تسرّبَ أرقامٍ بين خادمين.
 */
class Odoo
{
    protected function __construct(
        protected string $connId,
        protected string $connName,
        protected string $url,
        protected string $db,
        protected string $user,
        protected string $key,
        protected ?string $err = null,
    ) {}

    /**
     * قاطعٌ داخل النسخة الواحدة: أول فشل اتصالٍ (خادمٌ لا يستجيب) يوسم الاتصال
     * «ساقطاً» فتُرفض بقيةُ النداءات فوراً بلا شبكة. البطاقة تُنشئ نسخةً واحدة ثم
     * تدور على القنوات عليها — فخادمٌ ميتٌ يحجب العاملَ مرةً واحدة لا مرةً لكل قناة.
     */
    protected ?string $down = null;

    /* ───────────── المصانع ───────────── */

    /** نسخة لاتصال: null أو 'default' = الافتراضي من الإعدادات، وإلا معرّف صف */
    public static function for(OdooConnection|string|null $conn = null): self
    {
        if ($conn instanceof OdooConnection) {
            return self::fromRow($conn);
        }
        if ($conn === null || $conn === '' || $conn === 'default') {
            return new self('default', 'الافتراضي',
                (string) setting('odoo.url'), (string) setting('odoo.db'),
                (string) setting('odoo.user'), (string) setting('odoo.key'));
        }

        $row = OdooConnection::find($conn);
        if (! $row) {
            return new self((string) $conn, 'اتصال محذوف', '', '', '', '',
                'اتصال أودو المحدد لهذا السجل محذوف — اختر اتصالاً آخر من شاشة التخصيص');
        }
        if (! $row->active) {
            return new self($row->id, (string) $row->name, '', '', '', '',
                'اتصال أودو «' . $row->name . '» معطّل — فعّله من مركز التكاملات أو اختر غيره');
        }

        return self::fromRow($row);
    }

    protected static function fromRow(OdooConnection $row): self
    {
        return new self($row->id, (string) $row->name, (string) $row->url,
            (string) $row->db, (string) $row->username, (string) $row->key_cipher);
    }

    /** نسخة لسجلٍّ بحسب اختياره المخزَّن في meta['odoo']['conn'] */
    public static function forRow($row): self
    {
        $meta = (array) ($row->meta ?? []);

        return self::for($meta['odoo']['conn'] ?? null);
    }

    /** قائمة الاتصالات للاختيار: الافتراضي + الصفوف النشطة */
    public static function connections(): array
    {
        $out = [[
            'id' => 'default', 'name' => 'الافتراضي — من الإعدادات',
            'ready' => self::for(null)->ready(),
        ]];
        foreach (OdooConnection::where('active', true)->orderBy('name')->orderBy('id')->get() as $c) {
            $out[] = ['id' => $c->id, 'name' => (string) $c->name, 'ready' => true];
        }

        return $out;
    }

    /* ───────────── هوية النسخة ───────────── */

    public function id(): string { return $this->connId; }

    public function label(): string { return $this->connName; }

    /** سببُ موت النسخة إن ماتت — يُعرض في الشاشات بدل الصمت */
    public function error(): ?string { return $this->err; }

    /** للعرض الإداري: الرابط والقاعدة والمستخدم — فلا تقرأ الشاشاتُ الإعداداتِ رأساً */
    public function host(): string { return $this->url; }

    public function database(): string { return $this->db; }

    public function account(): string { return $this->user; }

    /** جاهزة للنداء؟ الحقول الأربعة مكتملة ولا خطأ */
    public function ready(): bool
    {
        return $this->err === null
            && $this->url !== '' && $this->db !== '' && $this->user !== '' && $this->key !== '';
    }

    /**
     * مفتاح كاش يحمل معرّفَ الاتصال **وبصمةَ اعتماده**: تعديلُ بيانات اتصالٍ
     * (توجيهُه لخادمٍ آخر أو تدويرُ مفتاحه) يُدوّر كلَّ مفاتيحه تلقائياً —
     * كان نسفُ `uid` وحده يترك opts/stats/chan تخدم بياناتِ الخادم القديم
     * حتى عشر دقائق. عامٌّ لأن الاختبارات تُثبت العزل به.
     */
    public function cacheKey(string $suffix): string
    {
        $fp = substr(sha1($this->url . '|' . $this->db . '|' . $this->user . '|' . $this->key), 0, 8);

        return 'odoo:' . $this->connId . ':' . $fp . ':' . $suffix;
    }

    /* ───────────── النداء ───────────── */

    /** نداء JSON-RPC خام */
    protected function rpc(string $service, string $method, array $args)
    {
        if (! $this->ready()) {
            throw new \RuntimeException($this->err ?? 'بيانات الاتصال بأودو ناقصة');
        }
        // القاطع مفتوح: سقط الاتصال في نداءٍ سابقٍ لهذه النسخة — لا نلمس الشبكة ثانيةً
        if ($this->down !== null) {
            throw new \RuntimeException($this->down);
        }

        $url = rtrim($this->url, '/') . '/jsonrpc';

        // بوابة SSRF عند النداء نفسه لا وقت الحفظ وحده: رابطٌ عامٌّ يُبدّله DNS
        // متقلّبٌ إلى داخليٍّ (169.254.169.254/127.0.0.1) وقت النداء، أو يردّ 302
        // نحو الداخل فيُتّبع. نُعيد الفحص، ونثبّت العنوان (CURLOPT_RESOLVE) على ما
        // أجازه الحارس، ولا نتّبع إعادة التوجيه — كنمط مسبار Uptime.
        $gate = hub_outbound_ok($url);
        if (! $gate['ok']) {
            $this->down = 'رابط أودو مرفوض: ' . $gate['why'];
            throw new \RuntimeException($this->down);
        }
        try {
            // connectTimeout منفصلٌ قصير: خادمٌ لا يقبل الاتصال (جدارٌ ناريّ يُسقط
            // الحزم) يفشل بعد ٣ث لا ينتظر المهلة الكلية ١٢ث حاجزاً العامل.
            $resp = Http::connectTimeout(3)->timeout(12)
                ->withOptions(['allow_redirects' => false, 'curl' => hub_resolve_pin($url, $gate['ip'])])
                ->post($url, [
                    'jsonrpc' => '2.0', 'method' => 'call', 'id' => rand(1, 99999),
                    'params' => ['service' => $service, 'method' => $method, 'args' => $args],
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // فشل اتصالٍ (لا استجابة/مهلة): افتح القاطع فلا تُحجب بقيةُ القنوات
            $this->down = 'تعذّر الوصول لخادم أودو — لا استجابة، تحقق من الرابط والشبكة';
            throw new \RuntimeException($this->down, 0, $e);
        }

        if (! $resp->successful()) {
            throw new \RuntimeException('تعذر الوصول لخادم أودو (' . $resp->status() . ') — تحقق من الرابط');
        }
        $j = $resp->json();
        if (isset($j['error'])) {
            throw new \RuntimeException('أودو رفض الطلب: ' . ($j['error']['data']['message'] ?? $j['error']['message'] ?? 'خطأ غير معروف'));
        }

        return $j['result'] ?? null;
    }

    /** هوية المستخدم — مخبأة ١٠ دقائق لكل اتصال */
    public function login(): int
    {
        return Cache::remember($this->cacheKey('uid'), 600, function () {
            $uid = $this->rpc('common', 'authenticate', [$this->db, $this->user, $this->key, []]);
            if (! $uid) throw new \RuntimeException('فشل الدخول لأودو — تحقق من اسم القاعدة والمستخدم ومفتاح الـ API');

            return (int) $uid;
        });
    }

    /** إصدار الخادم — لاختبار الاتصال */
    public function serverVersion(): string
    {
        $v = $this->rpc('common', 'version', []);

        // ردٌّ **خارجيّ لا نتحكّم به** يُخزَّن في عمودٍ بعرض ٦٠: خادمٌ مُعطَّل أو
        // وسيطٌ يردّ نصّاً طويلاً يُسقط الحفظ بـ1406 على MySQL **بعد نجاح
        // الاختبار** — أي بعد أن يقول الحارسُ «سليم». القصُّ عند المصدر يخدم
        // كلَّ مستدعٍ لا موضعَ الحفظ وحده.
        return hub_fit((string) ($v['server_version'] ?? '؟'), 60) ?: '؟';
    }

    /** تنفيذ قراءة على موديل */
    public function call(string $model, string $method, array $args = [], array $kw = [])
    {
        return $this->rpc('object', 'execute_kw',
            [$this->db, $this->login(), $this->key, $model, $method, $args, $kw]);
    }

    /** بحث شركاء بالاسم — للربط الذكي */
    public function partners(string $q): array
    {
        return (array) $this->call('res.partner', 'search_read',
            [[['name', 'ilike', $q]]],
            ['fields' => ['id', 'name', 'email'], 'limit' => 8]);
    }

    /** أرقام شريك (عميل/جهة) — مبيعات وفواتير ومتبقٍ ومشتريات، مخبأة ١٠ دقائق */
    public function stats(int $pid, bool $fresh = false): array
    {
        $key = $this->cacheKey('stats:' . $pid);
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, 600, function () use ($pid) {
            /*
             * **العدُّ بـsearch_count والمجموعُ بـread_group** — كما تفعل
             * الشقيقة channelStats: كان الجلبُ بحدّ ١٠٠٠ ثم جمعٌ محلي، فشريكٌ
             * له أكثر يُعرض «غيرُ المحصّل» (رقمُ الدَّين!) منقوصاً **بلا أي
             * إشارة**. السقوطُ الاحتياطي يجمع محلياً ويرفع `approx` الصادقة.
             */
            $invDomain = [['partner_id', '=', $pid], ['move_type', '=', 'out_invoice'], ['state', '=', 'posted']];
            $billDomain = [['partner_id', '=', $pid], ['move_type', '=', 'in_invoice'], ['state', '=', 'posted']];
            $orderDomain = [['partner_id', '=', $pid], ['state', 'in', ['sale', 'done']]];

            $approx = false;
            $sum = function (string $model, array $domain, array $fields) use (&$approx): array {
                try {
                    $g = (array) $this->call($model, 'read_group', [$domain, $fields, []]);

                    return array_map(fn ($f) => (float) ($g[0][$f] ?? 0), array_combine($fields, $fields));
                } catch (\Throwable $e) {
                    $rows = (array) $this->call($model, 'search_read', [$domain],
                        ['fields' => $fields, 'limit' => 2000]);
                    if (count($rows) >= 2000) $approx = true;   // بلغ الحدّ: منقوصٌ فيُقال

                    return array_map(fn ($f) => (float) array_sum(array_column($rows, $f)),
                        array_combine($fields, $fields));
                }
            };

            $inv = $sum('account.move', $invDomain, ['amount_total', 'amount_residual']);
            $bills = $sum('account.move', $billDomain, ['amount_total']);
            try {
                $orders = $sum('sale.order', $orderDomain, ['amount_total']);
                $ordersN = (int) $this->call('sale.order', 'search_count', [$orderDomain]);
            } catch (\Throwable $e) {
                $orders = ['amount_total' => 0.0]; $ordersN = 0;   // قاعدة بلا تطبيق مبيعات
            }

            return [
                'sales'     => $orders['amount_total'],
                'salesN'    => $ordersN,
                'invoiced'  => $inv['amount_total'],
                'invoicedN' => (int) $this->call('account.move', 'search_count', [$invDomain]),
                'residual'  => $inv['amount_residual'],
                'bills'     => $bills['amount_total'],
                'billsN'    => (int) $this->call('account.move', 'search_count', [$billDomain]),
                'approx'    => $approx,
                'at'        => now()->format('H:i'),
            ];
        });
    }

    /** إسقاط كاش أرقام شريك — عند فك الربط أو طلب التحديث */
    public function forgetStats(int $pid): void
    {
        Cache::forget($this->cacheKey('stats:' . $pid));
    }

    /* ───────────── قنوات البيع — مبيعات المنصات من أودو ───────────── */

    /** المميِّزات المدعومة لتفريق قنوات البيع داخل أودو */
    public const CHANNEL_MODES = ['team', 'journal', 'tag', 'website'];

    /**
     * خيارات المميِّز حيّةً من أودو — المالك غير متأكدٍ كيف تتمايز قنواته،
     * فالشاشة تعرض ما في خادمه فعلاً بدل أن تطلب معرّفات غيبية.
     */
    public function channelOptions(string $mode): array
    {
        return (array) Cache::remember($this->cacheKey('opts:' . $mode), 600, fn () => match ($mode) {
            'team'    => $this->call('crm.team', 'search_read', [[]],
                            ['fields' => ['id', 'name'], 'limit' => 80]),
            'journal' => $this->call('account.journal', 'search_read', [[['type', '=', 'sale']]],
                            ['fields' => ['id', 'name', 'code'], 'limit' => 80]),
            'tag'     => $this->call('crm.tag', 'search_read', [[]],
                            ['fields' => ['id', 'name'], 'limit' => 120]),
            'website' => $this->call('website', 'search_read', [[]],
                            ['fields' => ['id', 'name'], 'limit' => 40]),
            default   => throw new \RuntimeException('مميِّز غير معروف: ' . $mode),
        });
    }

    /**
     * أرقام قناةٍ واحدة: إجمالي + عدد.
     *
     * **الفرق الدلاليّ مقصود**: `team`/`tag`/`website` تُحصي **أوامر البيع**
     * المؤكدة (`sale.order`)، بينما `journal` يخصّ الدفاتر المحاسبية فيُحصي
     * **فواتير العملاء المرحّلة** (`account.move`) — والبطاقة تسمّي الوحدة
     * («أمر»/«فاتورة») حتى لا يُقارَن تفاحٌ ببرتقال.
     *
     * العدُّ بـ`search_count` (رخيصٌ ومضمونٌ عبر الإصدارات)، والإجماليُّ
     * بـ`read_group` — وأودو 17/18 بدأ يهجره، فله سقوطٌ احتياطي إلى
     * `search_read` وجمعٍ محليّ بحدّ ٢٠٠٠، وعندها `approx` **تصدُق**.
     */
    public function channelStats(string $projectId, array $ch, bool $fresh = false): array
    {
        $key = $this->cacheKey('chan:' . $projectId . ':' . ($ch['key'] ?? '?'));
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, 600, function () use ($ch) {
            $refId = (int) ($ch['ref_id'] ?? 0);
            [$model, $domain, $src] = match ((string) ($ch['mode'] ?? '')) {
                'team'    => ['sale.order', [['team_id', '=', $refId], ['state', 'in', ['sale', 'done']]], 'orders'],
                'tag'     => ['sale.order', [['tag_ids', 'in', [$refId]], ['state', 'in', ['sale', 'done']]], 'orders'],
                'website' => ['sale.order', [['website_id', '=', $refId], ['state', 'in', ['sale', 'done']]], 'orders'],
                'journal' => ['account.move', [['journal_id', '=', $refId], ['move_type', '=', 'out_invoice'], ['state', '=', 'posted']], 'invoices'],
                default   => throw new \RuntimeException('مميِّز غير معروف: ' . ($ch['mode'] ?? '؟')),
            };

            $count = (int) $this->call($model, 'search_count', [$domain]);

            $approx = false;
            try {
                $g = (array) $this->call($model, 'read_group',
                    [$domain, ['amount_total'], []]);
                $total = (float) ($g[0]['amount_total'] ?? 0);
            } catch (\Throwable $e) {
                $rows = (array) $this->call($model, 'search_read', [$domain],
                    ['fields' => ['amount_total'], 'limit' => 2000]);
                $total = (float) array_sum(array_column($rows, 'amount_total'));
                $approx = count($rows) >= 2000;   // بلغ الحدّ: المجموع منقوص فيُقال
            }

            return ['total' => $total, 'count' => $count, 'src' => $src,
                    'approx' => $approx, 'at' => now()->format('H:i')];
        });
    }

    /** إسقاط كاش قناةٍ — عند حذفها أو طلب التحديث */
    public function forgetChannel(string $projectId, string $chKey): void
    {
        Cache::forget($this->cacheKey('chan:' . $projectId . ':' . $chKey));
    }

    /* ───────────── التوافق الساكن — المواقع القائمة كما هي ───────────── */

    /** هل اكتملت بيانات الاتصال الافتراضي في الإعدادات؟ */
    public static function configured(): bool
    {
        return self::for(null)->ready();
    }

    public static function uid(): int
    {
        return self::for(null)->login();
    }

    public static function version(): string
    {
        return self::for(null)->serverVersion();
    }

    // exec/searchPartners/partnerStats الساكنة أُزيلت: بلا مستدعٍ منذ صارت
    // البطاقات تحلّ بـforRow — شيفرةُ «توافقٍ» بلا من يتوافق معها شيفرةٌ ميتة
}
