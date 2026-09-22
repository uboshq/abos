<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Concerns\HasPublicId;
use App\Core\Concerns\IsAudited;
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
    /*
     * ⭐ ঘরের দুইটা নিয়ম — ২৩ সেপ্টেম্বর ২০২৬।
     *
     * ⚠️ প্রথম খসড়ায় দুইটাই বাদ পড়েছিল, কারণ সারিটা "কেবল একটা
     * জোড়" মনে হয়েছিল। ⛔ কিন্তু প্রশ্নটা ভাবুন: *"এই নোটিশটা
     * কে কাদের জন্য করেছিলেন, আর কখন বদলেছিলেন?"* — অডিট ছাড়া
     * ঐ প্রশ্নের উত্তর নেই।
     *
     * ⓘ ধরা পড়েছে ঘরের পুরো পাহারা চালিয়ে, নিজের লেখা পরীক্ষায়
     * নয় — নিজের পরীক্ষা কেবল সেটুকুই মাপে যেটুকু আমি ভেবেছি।
     */
    use HasPublicId;
    use IsAudited;

    public $timestamps = false;

    protected $fillable = ['notice_id', 'role'];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }
}
