<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * একটা কাগজ বেরোনোর একটা ঘটনা — ছাপা, নামানো, পাঠানো, বা খোলা।
 *
 * ── কেন গোনা নয়, ঘটনা ───────────────────────────────────────────────
 * ⓘ "কতবার" প্রশ্নের উত্তর গোনায় থাকে, কিন্তু "কবে", "কে" আর "কোন
 * মাপে" — থাকে না। ⚠️ আর মালিকের আসল প্রশ্নটা তিন নম্বরটাই: গ্রাহক
 * বলছেন বিল পাননি, তখন জানতে হয় পাঠানো হয়েছিল কি না, আর খোলা হয়েছিল
 * কি না।
 *
 * ⛔ এই কারণেই `shared` আর `opened` আলাদা: **আমরা পাঠিয়েছি** আর
 * **তিনি পেয়েছেন** এক কথা নয়, আর ঠিক ঐ পার্থক্যটাই তর্ক থামায়।
 */
class DocumentDelivery extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    /** ছাপা হয়েছে — ব্রাউজারে PDF খুলে */
    public const PRINTED = 'printed';

    /** ফাইল হিসেবে নামানো হয়েছে */
    public const DOWNLOADED = 'downloaded';

    /** গোপন লিংক বানানো হয়েছে — অর্থাৎ পাঠানো হয়েছে */
    public const SHARED = 'shared';

    /** গ্রাহক লিংকটা খুলেছেন */
    public const OPENED = 'opened';

    /** @var list<string> */
    public const WAYS = [self::PRINTED, self::DOWNLOADED, self::SHARED, self::OPENED];

    protected $table = 'doc_deliveries';

    protected $fillable = [
        'company_id', 'branch_id', 'document_type', 'document_id', 'document_no',
        'paper', 'how', 'share_id', 'from_ip', 'created_by',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(DocumentShare::class, 'share_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
