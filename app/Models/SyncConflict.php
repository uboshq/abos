<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * যেটা নিয়ে ব্যবস্থাটা নিজে সিদ্ধান্ত নেয়নি — মানুষ নেবেন।
 *
 * ── কেন "শেষ লেখাই জেতে" নিয়মটা এখানে ভুল ──────────────────────────
 * সবচেয়ে সহজ নিয়ম হলো নতুনটা পুরনোটাকে চাপা দেবে। ওটা ব্যক্তির
 * সেটিংসে চলে — কেউ দুই ফোনে থিম বদলালে শেষেরটা থাকলেই হলো।
 *
 * **ব্যবসার নথিতে চলে না।** অফিসে কেউ অর্ডারের পরিমাণ কমিয়েছেন, আর
 * মাঠে সেলসম্যানের ফোনে পুরনো পরিমাণটা বসে আছে। শেষ লেখাটা জিতলে
 * অফিসের সিদ্ধান্তটা **নীরবে মুছে যেত**, আর কেউ কোনোদিন জানত না।
 *
 * ── কেন দুইটা রূপই রাখা হয় ─────────────────────────────────────────
 * ফোনেরটা আর সার্ভারেরটা, পাশাপাশি। একটা ছাড়া অন্যটা দেখে সিদ্ধান্ত
 * নেওয়া যায় না — "কোনটা রাখব" প্রশ্নের উত্তর দিতে দুইটাই লাগে।
 *
 * ⚠️ **আর ঠিক এই কারণেই সারিটা দুই পাশের যোগফলের চেয়ে বেশি গোপন।**
 * তাই দেখার দরজায় সাধারণ মডিউল-অনুমতি নয়, অডিট-স্তরের অনুমতি — যিনি
 * অর্ডার দেখতে পান তিনি এই সারিটা দেখতে পাওয়ার কথা নয়।
 */
class SyncConflict extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    public const PENDING = 'PENDING_MANUAL_RESOLUTION';

    public const RESOLVED = 'RESOLVED';

    protected $table = 'sync_conflicts';

    protected $fillable = [
        'company_id', 'device_id', 'module', 'entity_type', 'entity_id',
        'reason', 'client_payload_json', 'server_snapshot_json',
        'status', 'detected_at', 'resolved_at', 'resolved_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function needsResolution(): bool
    {
        return $this->status === self::PENDING;
    }
}
