<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * سجل المنتجات (Product Master) — الطرازُ لا القطعة.
 *
 * «Dell Latitude 5550» صفٌّ واحدٌ هنا مهما بلغت القطعُ المملوكة منه؛ القطعُ
 * صفوفٌ في `assets` تشير إليه بـ`product_id`. الكودُ `LYN-PRD-XXXXXXXX`
 * يولّده النظام ولا يُطلب — على نمط كود العهدة تماماً.
 */
class Product extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'products';
    public const MODULE = 'products';
    public const DISPLAY = 'name';

    protected $guarded = ['id', 'version', 'created_by'];

    protected $casts = [
        'specs' => 'array',
        'tags' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            if (! $p->code) $p->code = self::nextCode();
        });
        // الباركود الأساسي يدخل سجل الهوية تلقائياً — فالمحلّل يجده بلا خطوة يدوية
        static::saved(function (self $p) {
            if ($p->code) \App\Support\Identity::attach('products', $p->id, 'lyn', $p->code,
                ['is_primary' => true, 'verified' => true, 'source' => 'توليد']);
            if ($p->barcode) \App\Support\Identity::attach('products', $p->id, 'gtin', $p->barcode);
            if ($p->mpn) \App\Support\Identity::attach('products', $p->id, 'mpn', $p->mpn);
        });
    }

    /** كما Asset::save: تصادمُ الكود عند التزامن يُحلّ بكودٍ جديدٍ لا بفشل الحفظ */
    public function save(array $options = []): bool
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return parent::save($options);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= 5 || (string) $e->getCode() !== '23000'
                    || ! str_contains(mb_strtolower($e->getMessage()), 'code')) throw $e;
                $this->code = self::nextCode();
            }
        }
    }

    /** الكود الدائم التالي: LYN-PRD-00000001 — تسلسلٌ فوق أعلى مستعمَل، والمحذوف يحجز كودَه */
    public static function nextCode(): string
    {
        $prefix = 'LYN-PRD-';
        $last = self::withTrashed()->where('code', 'like', $prefix . '%')
            ->orderByDesc('code')->value('code');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix)))) + 1 : 1;

        do {
            $candidate = $prefix . sprintf('%08d', $n);
            $n++;
        } while (self::withTrashed()->where('code', $candidate)->exists());

        return $candidate;
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(RecordIdentifier::class, 'record_id')
            ->where('module', 'products');
    }
}
