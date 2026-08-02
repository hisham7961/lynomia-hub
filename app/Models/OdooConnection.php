<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * اتصالُ أودو — خادمٌ وقاعدةٌ ومستخدمُ قراءةٍ ومفتاح.
 *
 * ليس وحدةً في سجل `config/hub.php` بل جدولٌ إداريّ بمتحكّمٍ للمالك
 * (سابقة Webhook): لا يخضع للتنطيق العام ولا يظهر في القوائم، والتدقيق
 * يُكتب صراحةً بـ`hub_audit()` في المتحكم كما تفعل شاشة الإعدادات.
 */
class OdooConnection extends Model
{
    use HasUuid, SoftDeletes;

    protected $table = 'odoo_connections';

    protected $guarded = ['id'];

    protected $casts = [
        'key_cipher' => \App\Casts\EncryptedOrPlain::class,
        'active'     => 'boolean',
        'last_ok_at' => 'datetime',
    ];
}
