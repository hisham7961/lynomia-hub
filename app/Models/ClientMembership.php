<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * عضويّةُ عميلٍ مطبَّعة (Work OS · الطور A · WP-A.2 · SF-2).
 *
 * مصدرُ الحقيقةِ لمن ينتمي لأيّ عميلٍ وبأيّ صفة: بدل قائمةِ معرّفاتٍ خامّةٍ في
 * `users.clients` (بلا دورٍ ولا حالةٍ ولا دورةِ حياة)، صفٌّ لكلِّ (عميل، مستخدم)
 * يحمل دورَه وحالتَه وأثرَ دعوته وتفعيله. عليه يُبنى `hub_client_ids`، و
 * `users.clients` يبقى عموداً توافقيّاً رجعيّاً.
 *
 * الأدوارُ والحالاتُ تُتحقَّق في التطبيق (allowlist) لا كـenum على DB — درسُ C10
 * وnotifications_hub: إضافةُ قيمةِ enum على MySQL ALTER شبهُ مدمِّر، فالعمودُ
 * نصٌّ واسعٌ (١٢) والحارسُ في `booted`.
 */
class ClientMembership extends Model
{
    use HasUuid, SoftDeletes, Auditable;

    protected $table = 'client_memberships';

    public const MODULE = 'client_memberships';

    /** القيَمُ المسموحة — تُفرَض في `saving` (لا DB enum · درسُ C10) */
    public const ROLES = ['owner', 'lead', 'technical', 'finance', 'viewer'];

    public const STATUSES = ['invited', 'active', 'suspended'];

    protected $guarded = ['id'];

    /** الافتراضات على النموذج نفسه — لا تُترك للقاعدة وحدَها */
    protected $attributes = [
        'role' => 'viewer',
        'status' => 'invited',
    ];

    protected $casts = [
        'invited_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // حارسُ القيَم — يرفض دوراً أو حالةً خارج allowlist قبل أيّ كتابة.
        // هذا هو «enum التطبيق» البديلُ عن DB enum (C10): إضافةُ قيمةٍ جديدةٍ
        // مستقبلاً سطرٌ هنا لا ALTER على MySQL.
        static::saving(function (self $m): void {
            if ($m->role !== null && ! in_array($m->role, self::ROLES, true)) {
                throw new \InvalidArgumentException("دورُ عضويةٍ غيرُ صالح: {$m->role}");
            }
            if ($m->status !== null && ! in_array($m->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("حالةُ عضويةٍ غيرُ صالحة: {$m->status}");
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** عضويّةٌ فعّالة (تمنح وصولاً) — المعيار الوحيد الذي يعتمده hub_client_ids */
    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }

    /**
     * تعبئةٌ خلفيةٌ إلزامية (C3): لكلِّ مستخدمٍ له `users.clients` غيرُ فارغة تُخلق
     * عضويّةٌ فعّالة (role=viewer, status=active) لكلِّ عميلٍ في قائمته — فيُرجع
     * `hub_client_ids` المجموعةَ نفسَها بعد الترقية بلا أن يفقد أحدٌ وصولَه.
     *
     * مُتكرِّرةُ التنفيذ: حارسُ وجودٍ على (client_id, user_id) — يشمل المحذوفَ
     * ناعماً لأنّ الفهرسَ الفريدَ يشمله — فلا تُضاعف صفّاً ولا تصطدم بالقيد.
     * تُكتَب خاماً (DB::table) فلا تُطلق أحداثَ النموذج ولا تُغرق التدقيقَ لحظةَ
     * الهجرة. تُرجع عددَ العضويّاتِ المُنشأة.
     */
    public static function backfillFromLegacyClients(): int
    {
        if (! Schema::hasTable('client_memberships') || ! Schema::hasTable('users')) {
            return 0;
        }

        $now = now();
        $made = 0;

        DB::table('users')->whereNull('deleted_at')->whereNotNull('clients')
            ->orderBy('id')->select('id', 'clients')
            ->each(function ($u) use (&$made, $now) {
                $clients = is_string($u->clients) ? (json_decode($u->clients, true) ?: []) : [];
                if (! is_array($clients) || ! $clients) {
                    return;
                }

                foreach (array_unique(array_filter(array_map('strval', $clients))) as $cid) {
                    if ($cid === '') {
                        continue;
                    }
                    // حارسُ التكرار: أيُّ صفٍّ سابق (ولو محذوفاً ناعماً) يمنع الإدراج
                    $exists = DB::table('client_memberships')
                        ->where('client_id', $cid)->where('user_id', $u->id)->exists();
                    if ($exists) {
                        continue;
                    }

                    DB::table('client_memberships')->insert([
                        'id' => (string) Str::uuid(),
                        'client_id' => $cid,
                        'user_id' => $u->id,
                        'role' => 'viewer',
                        'status' => 'active',
                        'invited_by' => null,
                        'invited_at' => null,
                        'activated_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                        'deleted_at' => null,
                    ]);
                    $made++;
                }
            });

        return $made;
    }
}
