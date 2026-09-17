<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * সারির নিজের `company_id` নেই — দেয়ালটা তার কাগজের।
 *
 * ── ⛔ নিরীক্ষার ফলাফল ২.৩, ১২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * *"১৪টি মডেলে `company_id` নেই — সবগুলোই চাইল্ড সারি। আজ এগুলো নিরাপদ,
 * কারণ প্যারেন্ট স্কোপযুক্ত। কিন্তু নিরাপত্তাটা **পরোক্ষ** — কেউ সরাসরি
 * `SalesInvoiceLine::find($id)` লিখলে কোনো দেয়াল নেই।"*
 *
 * ⚠️ "আজ নিরাপদ" কথাটার পুরো ভরসা একটা অভ্যাসের উপর: *সবাই সবসময় চালান
 * ধরে লাইনে পৌঁছাবেন।* ⛔ একজন একবার সরাসরি কোয়েরি লিখলেই এক কোম্পানির
 * চালানের লাইন আরেক কোম্পানির পর্দায় চলে আসত — আর সেটা কেউ টের পেত না,
 * কারণ ভুল কিছু ঘটছে বলে মনে হত না।
 *
 * ── ⭐ কেন `company_id` কলাম বসানো হলো না ─────────────────────────────
 * নিরীক্ষা দুইটা পথ দিয়েছিল: *"হয় `company_id` যোগ করুন, নয়তো প্যারেন্ট
 * ধরে বাধ্যতামূলক জয়েন।"*
 *
 * ⛔ প্রথমটা মানে তেরোটা চালু টেবিলে মাইগ্রেশন, আর প্রতিটায় পুরনো লক্ষ
 * সারি ভরে দেওয়া (backfill)। ⚠️ আর তার চেয়েও খারাপ: তখন একই সত্য **দুই
 * জায়গায়** থাকত — লাইনের নিজের `company_id`, আর চালানের `company_id`।
 * ⓘ দুইটা একদিন আলাদা হলে (ভুল ইমপোর্ট, হাতে লেখা SQL) কোনটা সত্যি তা
 * কেউ বলতে পারত না, আর "নিরাপত্তা" নিজেই একটা অমিল হয়ে দাঁড়াত।
 *
 * ⭐ তাই দ্বিতীয় পথ: সত্যটা এক জায়গাতেই থাক — **কাগজের উপর** — আর
 * লাইনগুলো সেখান থেকেই তাদের পরিচয় নিক।
 *
 * ── ⓘ কীভাবে ব্যবহার করতে হয় ─────────────────────────────────────────
 *     use BelongsToCompanyThroughParent;
 *
 *     protected function companyParent(): string
 *     {
 *         return 'invoice';   // এই মডেলের BelongsTo সম্পর্কের নাম
 *     }
 *
 * ⚠️ খরচ একটা `EXISTS` উপ-কোয়েরি, আর সেটা প্যারেন্টের প্রাইমারি কি ধরে
 * চলে — অর্থাৎ ইনডেক্সেই মেটে। ⓘ চালান ধরে লাইন আনলে শর্তটা বাড়তি, কিন্তু
 * ক্ষতিকর নয়; আর সরাসরি কোয়েরিতে ওটাই একমাত্র দেয়াল।
 */
trait BelongsToCompanyThroughParent
{
    /**
     * কোন সম্পর্কটা এই সারির কাগজ — অর্থাৎ কার `company_id` এটাকে বাঁধে।
     *
     * @return string একটা `BelongsTo` সম্পর্কের নাম
     */
    abstract protected function companyParent(): string;

    public static function bootBelongsToCompanyThroughParent(): void
    {
        static::addGlobalScope('company-through-parent', function (Builder $builder): void {
            $companyId = CompanyContext::id();

            /*
             * ⓘ কনসোল, মাইগ্রেশন ও সিডারে কোনো কোম্পানি প্রসঙ্গ থাকে না —
             * ঠিক [[BelongsToCompany]]-র মতোই, আর একই কারণে।
             *
             * ⚠️ কিন্তু ওয়েব রিকোয়েস্টে প্রসঙ্গ না থাকা মানে কিছু একটা
             * ভুল, তাই সেটা চেপে যাওয়া হয় না। ⛔ চুপ করে গেলে দেয়ালটা
             * নীরবে খুলে যেত — আর নীরবে খোলা দেয়াল দেয়াল নয়।
             */
            if ($companyId === null) {
                if (app()->runningInConsole()) {
                    return;
                }

                throw new RuntimeException(
                    'No company in context while querying '.static::class.'. '
                    .'Every web request must resolve a company before touching tenant data.'
                );
            }

            $model = $builder->getModel();

            /** @var BelongsTo<Model, Model> $relation */
            $relation = $model->{$model->companyParentRelation()}();

            $parent = $relation->getRelated();
            $parentTable = $parent->getTable();
            $childTable = $model->getTable();

            $builder->whereExists(function (QueryBuilder $query) use (
                $parentTable, $parent, $childTable, $relation, $companyId
            ): void {
                $query->select(DB::raw('1'))
                    ->from($parentTable)
                    ->whereColumn(
                        $parentTable.'.'.$parent->getKeyName(),
                        $childTable.'.'.$relation->getForeignKeyName(),
                    )
                    ->where($parentTable.'.company_id', $companyId);
            });
        });
    }

    /**
     * ⓘ `companyParent()` protected, আর গ্লোবাল স্কোপ বাইরে থেকে ডাকে —
     * তাই এই ছোট দরজাটা। ⚠️ `companyParent()`-কেই public করলে যেকোনো
     * কোড ওটা বদলে দেওয়ার চেষ্টা করতে পারত।
     */
    public function companyParentRelation(): string
    {
        return $this->companyParent();
    }
}
