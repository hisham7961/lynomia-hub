<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **اقتراحُ تغييرٍ من الذكاء** — يكتبه `App\Support\Ai\Proposals\ProposalService` وحدَه، ويقرّره إنسان.
 * الرؤيةُ لا تُقرأ من هنا: `ProposalService::canAct` يقرّر (صلاحيّةُ التعديل + النطاق + حجبُ الحقل).
 */
class AiProposal extends Model
{
    use HasUuid;

    protected $table = 'ai_proposals';

    protected $guarded = ['id'];

    protected $casts = [
        'evidence' => 'array',
        'confidence' => 'integer',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
