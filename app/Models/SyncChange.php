<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা ফোন যা পাঠিয়েছে, আর তার সাথে যা হয়েছে।
 *
 * ── এটাই দুইবার বসা ঠেকায় ───────────────────────────────────────────
 * মোবাইল নেটওয়ার্কে একই অনুরোধ দুইবার পৌঁছানো **নিয়ম, ব্যতিক্রম নয়**।
 * উত্তরটা পথে হারালে ফোন আবার পাঠায়, আর ফোনের দিক থেকে দুইটা চেষ্টা
 * দেখতে হুবহু এক।
 *
 * পাহারা না থাকলে একই অর্ডার দুইবার বসত, আর **কোনো যাচাই লাল হত না** —
 * দুইটা অর্ডারই বৈধ, দুইটাতেই সব ঘর ভরা। ঠিক সেই আকারের বাগ যেটা
 * `posted_documents` প্রহরী-টেবিল খতিয়ানের জন্য ঠেকায়, আর সমাধানও একই:
 * `(device_id, change_id)`-তে unique, ডাটাবেজে, কোডে নয়।
 *
 * ── চারটা পরিণতি, আর ফোন কোনটাকে কী ধরে ─────────────────────────────
 *
 *   APPLIED    বসেছে                     → ফোন কিউ থেকে মোছে
 *   DUPLICATE  আগেই বসেছিল               → ফোন কিউ থেকে মোছে
 *   REJECTED   ব্যবসার নিয়মে আটকেছে       → ফোন কারণসহ ধরে রাখে
 *   CONFLICT   সার্ভারে নতুনতর বদল আছে    → ফোন কারণসহ ধরে রাখে
 *
 * শেষ দুইটা **মুছে ফেলা হয় না, কোনো পাশেই** — সার্ভারে এই সারিতে,
 * ফোনে `SyncEngine.rejectedItems`-এ। যে বদলটা ঘটেনি সেটাও একটা ঘটনা,
 * আর "০টা অপেক্ষমাণ" দেখে সেলসম্যানের ধরে নেওয়ার কথা যে সব পৌঁছে গেছে।
 */
class SyncChange extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const APPLIED = 'APPLIED';

    public const DUPLICATE = 'DUPLICATE';

    public const REJECTED = 'REJECTED';

    public const CONFLICT = 'CONFLICT';

    protected $table = 'sync_changes';

    protected $fillable = [
        'company_id', 'device_id', 'change_id', 'module', 'entity_type',
        'entity_id', 'operation', 'payload_json', 'client_version',
        'status', 'message', 'applied_entity_id', 'user_id', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'client_version' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ফোনের কাছে কাজটা শেষ কি না।
     *
     * ফোনের `reasonIfRejected()` ঠিক এই একই দুইটা অবস্থাকে "শেষ" ধরে।
     * নিয়মটা দুই পাশে আলাদাভাবে লেখা, আর সেটাই উদ্দেশ্য: একটা পাশ
     * বদলালে অন্যটা তখনো পুরনো নিয়ম ধরে থাকবে, আর টেস্ট সেটা ধরবে।
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::APPLIED, self::DUPLICATE], true);
    }
}
