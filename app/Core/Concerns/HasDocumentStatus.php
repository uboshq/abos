<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Support\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * ডকুমেন্টের অবস্থা ও সফট ডিলিট — ক্রস-কাটিং নিয়ম ৫।
 *
 * কোনো লেনদেন হার্ড-ডিলিট হবে না। একটা ভাউচার মুছে ফেললে ট্রায়াল ব্যালেন্স
 * মিলবে না এবং কেন মিলছে না তার কোনো উত্তরও থাকবে না — কারণ প্রমাণটাই মুছে
 * গেছে। বাতিল করা যায়, মোছা যায় না।
 */
trait HasDocumentStatus
{
    public function initializeHasDocumentStatus(): void
    {
        if (! isset($this->attributes['status'])) {
            $this->attributes['status'] = DocumentStatus::DRAFT;
        }
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::DRAFT);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::CONFIRMED);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::CANCELLED);
    }

    /**
     * হিসাবে গোনা হবে এমন ডকুমেন্ট — ড্রাফট ও বাতিল বাদ।
     *
     * তালিকাটা `DocumentStatus::POSTED`-এ, এখানে নয়: একই নিয়ম সংজ্ঞা
     * বলার সময়ও লাগে (`Metric`), আর দুই জায়গায় দুইবার লিখলে একদিন
     * দুইটা আলাদা হয়ে যেত — গতবার ঠিক তাই হয়েছিল।
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->whereIn('status', DocumentStatus::POSTED);
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === DocumentStatus::CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::CANCELLED;
    }

    /** ড্রাফট ছাড়া কিছু সরাসরি সম্পাদনা করা যায় না — কনফার্মড বদলাতে অনুমোদন লাগে। */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }
}
