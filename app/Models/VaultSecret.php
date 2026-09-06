<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use App\Traits\HasVersions;
use App\Traits\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** الخزنة الآمنة */
class VaultSecret extends Model
{
    use HasFactory, HasUuid, SoftDeletes, Auditable, HasVersions, Searchable;

    protected $table = 'vault_secrets';
    public const MODULE = 'vault';
    public const DISPLAY = 'title';

    protected $guarded = ['id', 'version', 'created_by'];

    /** عمودُ السرّ لا تُكتب قيمتُه في سجل التدقيق — بصمةٌ فقط (انظر `Auditable::auditRedact`) */
    public const AUDIT_SECRET = ['secret_cipher'];

    protected static function booted(): void
    {
        // ترقية صامتة: أي سجل قديم بقيمة غير مشفّرة يُشفَّر عند أول حفظ لأي حقل
        static::saving(function (self $m) {
            $raw = $m->getRawOriginal('secret_cipher');
            if ($raw === null || $raw === '' || $m->isDirty('secret_cipher')) return;
            try { \Illuminate\Support\Facades\Crypt::decryptString($raw); }
            catch (\Throwable $e) { $m->secret_cipher = $raw; }   // التعيين يمرّ بالكاست فيشفِّر
        });

        // (WP-4.5) ختمُ التدوير الصادق: يتحرّك حين يتغيّر secret_cipher **فقط** —
        // تعديلُ ملاحظةٍ كان «يجدّد» السرَّ زوراً عبر updated_at فيسقط من فحص
        // التدوير وهو بائت. الإنشاءُ أولُ تدوير. (يُسجَّل بعد خطّاف الترقية أعلاه
        // عمداً — فترقيةُ نصٍّ قديم غير مشفَّر تغييرٌ فعليّ للعمود تُختم كذلك.)
        static::saving(function (self $m) {
            if ($m->isDirty('secret_cipher') && hub_has_col('vault_secrets', 'rotated_at')) {
                $m->rotated_at = now();
            }
        });
    }

    protected $casts = [
        'secret_cipher' => \App\Casts\EncryptedOrPlain::class,
        'rotated_at' => 'datetime',   // آخرُ تدويرٍ فعليّ (WP-4.5) — يُختم في booted أعلاه
        'allowed_ids' => 'array',
        'custom' => 'array',
        'meta' => 'array',
        'archived' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Project::class, 'project_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Application::class, 'app_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Server::class, 'server_id');
    }
}
