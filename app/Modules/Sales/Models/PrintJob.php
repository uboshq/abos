<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * একটা কাগজ, তার অবস্থা, আর কতবার ছাপা হয়েছে।
 *
 * ── কেন এটা অডিটে ───────────────────────────────────────────────────
 * ছাপা যন্ত্রের কাজ, ব্যবসার সিদ্ধান্ত নয় — এই যুক্তিতে একে অডিটের
 * বাইরে রাখা যেত। কিন্তু যে বিপদটার জন্য DUPLICATE বসানো হয়েছে,
 * সেটা যন্ত্রের নয়: কর্মী একটা কপি দেখিয়ে দ্বিতীয়বার টাকা নিতে
 * পারেন, বা ক্রেতা দুইটা কাগজ নিয়ে দুইবার ফেরত চাইতে পারেন।
 *
 * সেই প্রশ্নের উত্তর — "দ্বিতীয় কপিটা কে ছেপেছিলেন" — এই টেবিলে
 * নেই। `created_by` প্রথমবারের মানুষটার নাম রাখে, পরেরবারগুলোর নয়।
 * অডিট রাখে: প্রতিটা পুনঃছাপা একটা সারি, printed_count ৩ → ৪, আর
 * সাথে কে চেপেছিলেন।
 */
class PrintJob extends Model
{
    use BelongsToCompany;
    use HasPublicId;
    use IsAudited;

    /** সারিতে আছে, এখনো ছাপা হয়নি। */
    public const WAITING = 'waiting';

    /** ছাপা হয়ে গেছে — অন্তত একবার। */
    public const PRINTED = 'printed';

    /** চেষ্টা হয়েছে, ব্যর্থ — কারণটা `failure`-এ। */
    public const FAILED = 'failed';

    protected $table = 'sal_print_jobs';

    protected $fillable = [
        'company_id', 'branch_id',
        'document_type', 'document_id', 'document_no', 'paper',
        'status', 'printed_count', 'printed_at', 'failure', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'printed_at' => 'datetime',
            'printed_count' => 'integer',
        ];
    }

    /**
     * এই কাগজটা কি আগে ছাপা হয়েছে।
     *
     * এটাই DUPLICATE বসানোর একমাত্র শর্ত। "দ্বিতীয়বার" গোনার জন্য
     * সময় দেখা হয় না — একই মিনিটে দুইবার ছাপাও দ্বিতীয়বারই।
     */
    public function isReprint(): bool
    {
        return $this->printed_count > 0;
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereIn('status', [self::WAITING, self::FAILED]);
    }
}
