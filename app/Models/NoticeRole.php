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

    /**
     * ⛔ এই সারির অডিট কার খাতায় বসবে — ২৩ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ সারিটার নিজের `company_id` নেই, আর থাকারও দরকার নেই: সে
     * নোটিশের সাথেই বাঁচে-মরে। ⚠️ কিন্তু অডিট-ইঞ্জিন প্রশ্নটা করবেই,
     * আর উত্তর না পেলে সে **চলতি প্রসঙ্গ** থেকে আইডিটা নিত।
     *
     * ⛔ ওটাই ফাঁদ: প্রসঙ্গটা যিনি কাজটা করছেন তাঁর, আর সারিটা যার
     * সেটা অন্য কোম্পানির হতে পারে — তখন অডিটের দাগ **ভুল খাতায়**
     * পড়ত, আর ভুল খাতার দাগ না-থাকা দাগের চেয়ে খারাপ।
     *
     * ⭐ তাই উত্তরটা নোটিশের কাছ থেকেই — কাগজটা কার, সে-ই জানে।
     */
    public function auditCompanyId(): ?int
    {
        return $this->notice?->company_id;
    }

    /** ⓘ শাখা নোটিশের নেই — নোটিশ কোম্পানির জিনিস, ডিপোর নয়। */
    public function auditBranchId(): ?int
    {
        return null;
    }
}
