<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * কাগজের সারি তালা দিয়ে আবার পড়া — হাতের কপি বাসি হলে তাজা অবস্থা বসে।
 *
 * ── কেন লাগল, ৩০ সেপ্টেম্বর ২০২৬ (চূড়ান্ত অডিট ⛔৭, ⛔১১) ─────────────────
 * টাকার কাজগুলো অবস্থা ("খোলা", "পোস্ট হয়নি") দেখত হাতের কপি থেকে, লেনদেনের বাইরে। একই
 * পাতা দুই ট্যাবে, বা বোতামে দুইবার চাপ — দ্বিতীয় অনুরোধের কপিতে তখনো "খোলা", আর টাকা
 * দ্বিতীয়বার খাতায় বসত ([[TheSecondClickPostedTheMoneyAgainTest]])।
 *
 * ⭐ লেনদেনের **ভিতরে** ডাকুন, তারপর অবস্থার যাচাই আবার — দ্বিতীয়জন প্রথমজনের কমিট পর্যন্ত
 * অপেক্ষা করেন আর তাজা অবস্থা দেখে ফেরেন ([[DepositClaimService::lockPending()]]-এর ছাঁচ)।
 * ⚠️ লেনদেনের বাইরে `FOR UPDATE` কোয়েরি শেষ হতেই খুলে যায় — তাই বাইরে ডাকা অর্থহীন।
 */
trait ReadsTheRowUnderLock
{
    protected function lockFresh(Model $model): void
    {
        /*
         * ⛔ দেখার শাখার দেয়াল ছাড়া — কোম্পানির দেয়াল থাকে (fe, ১৩ অক্টোবর ২০২৬; [[ARowInHandIsLockedAcrossBranchesTest]])। ⚠️ কাগজটা
         * আগেই দেয়াল পেরিয়ে হাতে এসেছে; `newQuery()` আবার দেয়াল বসাত, আর অন্য শাখা দেখানো মানুষের হাতে "No query results" —
         * পাকা, সম্পাদনা মাঝপথে থামত। ⓘ দেয়াল না থাকা মডেলে নামটা কিছুই করে না।
         */
        $fresh = $model->newQuery()->withoutGlobalScope('user-branch')->whereKey($model->getKey())->lockForUpdate()->firstOrFail();

        $model->setRawAttributes($fresh->getAttributes(), true);
    }
}
