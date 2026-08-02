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

    /** جاهزة للنداء؟ الحقول الأربعة مكتملة ولا خطأ */
    public function ready(): bool
    {
        return $this->err === null
            && $this->url !== '' && $this->db !== '' && $this->user !== '' && $this->key !== '';
    }

    protected function cacheKey(string $suffix): string
    {
        return 'odoo:' . $this->connId . ':' . $suffix;
    }

    /* ───────────── النداء ───────────── */

    /** نداء JSON-RPC خام */
    protected function rpc(string $service, string $method, array $args)
    {
        if (! $this->ready()) {
            throw new \RuntimeException($this->err ?? 'بيانات الاتصال بأودو ناقصة');
        }

        $url = rtrim($this->url, '/') . '/jsonrpc';
        $resp = Http::timeout(12)->post($url, [
            'jsonrpc' => '2.0', 'method' => 'call', 'id' => rand(1, 99999),
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
        ]);

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

        return (string) ($v['server_version'] ?? '؟');
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
            $inv = (array) $this->call('account.move', 'search_read',
                [[['partner_id', '=', $pid], ['move_type', '=', 'out_invoice'], ['state', '=', 'posted']]],
                ['fields' => ['amount_total', 'amount_residual'], 'limit' => 1000]);

            $bills = (array) $this->call('account.move', 'search_read',
                [[['partner_id', '=', $pid], ['move_type', '=', 'in_invoice'], ['state', '=', 'posted']]],
                ['fields' => ['amount_total'], 'limit' => 1000]);

            try {
                $orders = (array) $this->call('sale.order', 'search_read',
                    [[['partner_id', '=', $pid], ['state', 'in', ['sale', 'done']]]],
                    ['fields' => ['amount_total'], 'limit' => 1000]);
            } catch (\Throwable $e) {
                $orders = [];   // قاعدة بلا تطبيق مبيعات
            }

            return [
                'sales'     => array_sum(array_column($orders, 'amount_total')),
                'salesN'    => count($orders),
                'invoiced'  => array_sum(array_column($inv, 'amount_total')),
                'invoicedN' => count($inv),
                'residual'  => array_sum(array_column($inv, 'amount_residual')),
                'bills'     => array_sum(array_column($bills, 'amount_total')),
                'billsN'    => count($bills),
                'at'        => now()->format('H:i'),
            ];
        });
    }

    /** إسقاط كاش أرقام شريك — عند فك الربط أو طلب التحديث */
    public function forgetStats(int $pid): void
    {
        Cache::forget($this->cacheKey('stats:' . $pid));
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

    public static function exec(string $model, string $method, array $args = [], array $kw = [])
    {
        return self::for(null)->call($model, $method, $args, $kw);
    }

    public static function searchPartners(string $q): array
    {
        return self::for(null)->partners($q);
    }

    public static function partnerStats(int $pid, bool $fresh = false): array
    {
        return self::for(null)->stats($pid, $fresh);
    }
}
