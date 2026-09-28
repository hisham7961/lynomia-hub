<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * **التزامٌ قاله موظّفٌ في تقريره** — يكتبه `App\Support\Ai\FollowUp` وحدَه، ويجيب عنه صاحبُه أو مديرُه المباشر.
 */
class AiCommitment extends Model
{
    use HasUuid;

    protected $table = 'ai_commitments';

    protected $guarded = ['id'];

    protected $casts = [
        'said_on' => 'date',
        'due_on' => 'date',
        'asked_count' => 'integer',
        'asked_at' => 'datetime',
        'answered_at' => 'datetime',
        'escalated_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
