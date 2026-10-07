<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ একজন মানুষ একটা কাগজ শেষ কবে খুলেছিলেন — ২ অক্টোবর ২০২৬।
 *
 * ⓘ Ctrl+K-এর খালি বাক্স এটা পড়ে ([[StartingPoints::recent()]])।
 *
 * ⛔ সারিটা কেবল ঠিকানা: কোন শ্রেণি, কোন আইডি, কখন। ⚠️ নম্বর বা নাম এখানে
 * রাখা হয় না, কারণ দেখানোর আগে কাগজটা আজকের দেয়াল দিয়ে আবার তোলা হয় —
 * যা আজ খোলা যায় না, তার একটা অক্ষরও পর্দায় আসে না।
 *
 * ⓘ কোম্পানির ছাঁকনি গ্লোবাল স্কোপে ([[BelongsToCompany]]): এক কোম্পানিতে
 * খোলা কাগজ অন্য কোম্পানির খালি বাক্সে আসে না।
 */
class RecentPaper extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'recent_papers';

    protected $fillable = [
        'user_id', 'company_id', 'paper_type', 'paper_id', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'paper_id' => 'integer',
            'opened_at' => 'datetime',
        ];
    }
}
