<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা নোটিশ কোন ভূমিকার জন্য।
 *
 * ⚠️ ভূমিকাটা **নামে** রাখা, আইডিতে নয় — কারণ ভূমিকা কোম্পানি ধরে বসে
 * (teams), আর একই নামের ভূমিকা প্রতিটা কোম্পানিতে আলাদা সারি। ⓘ কারণটা
 * মাইগ্রেশনে পুরো লেখা।
 */
final class NoticeRole extends Model
{
    public $timestamps = false;

    protected $fillable = ['notice_id', 'role'];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
