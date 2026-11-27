<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Core\Concerns\BelongsToCompany;
use App\Core\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ⭐ একটা মেইল একটা কাগজের সাথে বাঁধা (গ্রাহক, বিল, ক্রয় আদেশ…) — মেইলটা চেনা যায় তার নিজের `message_id` দিয়ে, তাই ফোল্ডার
 * বদলালেও বাঁধন থাকে। ⓘ মেইলের লেখা নয়, কেবল বিষয়, প্রেরক আর সময় — তালিকায় দেখানোর জন্য।
 */
class MailLink extends Model
{
    use BelongsToCompany;
    use HasPublicId;

    protected $table = 'mail_links';

    protected $fillable = ['mail_account_id', 'message_id', 'subject', 'from_email', 'sent_at', 'linkable_type', 'linkable_id', 'linked_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'linkable_id' => 'integer'];
    }

    /** @return BelongsTo<MailAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
