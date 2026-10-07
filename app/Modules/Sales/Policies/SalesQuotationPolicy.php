<?php

declare(strict_types=1);

namespace App\Modules\Sales\Policies;

use App\Models\User;
use App\Modules\Sales\Models\SalesQuotation;

/**
 * SalesQuotation — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র তালিকা।
 *
 * ⓘ পাঁচটা চাবি: দেখা · তৈরি · সম্পাদনা (ধাপ এগোনোও) · বাতিল · আদেশে
 * রূপান্তর। ⚠️ রূপান্তর আলাদা চাবি, কারণ ওটা একটা নতুন কাগজ জন্ম দেয় —
 * দর লেখার লোক আর আদেশ কাটার লোক সব ডিপোতে এক নন।
 */
class SalesQuotationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('sales.quotation.view');
    }

    public function view(User $user, SalesQuotation $document): bool
    {
        return $user->can('sales.quotation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('sales.quotation.create');
    }

    /** সম্পাদনা কেবল খসড়ায় — সেবা স্তরও আটকায়, কিন্তু বোতামটা দেখানোই উচিত নয় */
    public function update(User $user, SalesQuotation $document): bool
    {
        return $user->can('sales.quotation.update')
            && $document->status === SalesQuotation::DRAFT;
    }

    /**
     * ধাপ এগোনো — জমা, অনুমোদনের খোঁজ, পাঠানো, ডিলারের উত্তর, আবার খসড়া।
     *
     * ⓘ সম্পাদনার চাবিতেই, কারণ এগুলো একই মানুষের কাজ; অবস্থার শর্তটা
     * সেবায় ([[SalesQuotationService]]), যাতে বার্তাটা বলে কেন হল না।
     */
    public function advance(User $user, SalesQuotation $document): bool
    {
        return $user->can('sales.quotation.update');
    }

    public function delete(User $user, SalesQuotation $document): bool
    {
        return $user->can('sales.quotation.cancel');
    }

    /**
     * আদেশে রূপান্তর।
     *
     * ⛔ আদেশ তৈরির চাবিও লাগে — নাহলে এই দরজাটা আদেশের নিজের দরজার
     * চেয়ে দুর্বল হত: যাঁর আদেশ কাটার অধিকার নেই, তিনি উদ্ধৃতির পথ ধরে
     * আদেশ কেটে ফেলতেন।
     */
    public function convert(User $user, SalesQuotation $document): bool
    {
        return $user->can('sales.quotation.convert')
            && $user->can('sales.order.create');
    }
}
