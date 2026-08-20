<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * একই জিনিস দুইবার ঢোকা ঠেকানো।
 *
 * ── কী ভাঙা ছিল ─────────────────────────────────────────────────────
 * গ্রাহক ও সরবরাহকারীতে অনন্য ছিল কেবল `code` — নাম নয়, ফোন নয়। ফলে
 * "রহিম স্টোর", "রহিম  স্টোর" আর "Rahim Store" তিনটা আলাদা সারি হত,
 * তিনটাতেই আলাদা বকেয়া জমত, আর কেউ জানত না মোট পাওনা কত।
 *
 * নকল সারি মুছে ফেলা যায় না — দুইটাতেই বিল ঝুলে থাকে। তাই একমাত্র
 * উপায় হলো **ঢোকার সময়েই ঠেকানো**, আর সেটাই এখানে।
 *
 * ── ফোন আর নাম এক জিনিস নয় ──────────────────────────────────────────
 * একই ফোন নম্বর মানে প্রায় নিশ্চিতভাবে একই মানুষ — ওটা আটকানো হয়।
 *
 * একই নাম মানে সেটা নয়। "রহিম স্টোর" নামে দুই বাজারে দুইটা আলাদা দোকান
 * সত্যিই থাকতে পারে, আর ওটাকে আটকালে সৎ ব্যবহারকারী কাজই করতে পারতেন
 * না। তাই নামের বেলায় **দেখানো হয়, আটকানো হয় না**: মিলে যাওয়া সারিগুলো
 * সামনে আসে, আর তিনি জেনেশুনে এগোতে পারেন।
 *
 * জোর করে এগোনোটা নীরব নয় — `allow_duplicate` সারিতে বসে, তাই ছয় মাস
 * পরে "এই দুইটা কেন আলাদা" প্রশ্নের উত্তর থাকে।
 */
final class DuplicateGuard
{
    /**
     * নামের তুলনাযোগ্য রূপ।
     *
     * বড়-ছোট হাতের অক্ষর, বাড়তি ফাঁক, আর যতিচিহ্ন সরানো হয়। এগুলোই
     * নকলের সবচেয়ে সাধারণ কারণ — "M/S. রহিম স্টোর" আর "MS রহিম স্টোর"
     * টাইপ করার সময় কেউ একই রকম লেখেন না।
     *
     * সামনের "M/S." বা "Messrs" সরানো হয় — বাংলাদেশে প্রতিষ্ঠানের নামের
     * আগে ওটা এত সাধারণ যে একই দোকান একবার ওটা নিয়ে, একবার ছাড়া ঢোকে।
     *
     * শর্তটা সংকীর্ণ রাখা হয়েছে: স্ল্যাশসহ `m/s`, বা পুরো শব্দ `messrs`।
     * খালি "MS" সরানো হয় না, নাহলে "MS Trading" নামের সত্যিকারের
     * প্রতিষ্ঠানটা "Trading" হয়ে যেত।
     *
     * বাংলা বানানের ভিন্নতা (ষ বনাম স) ইচ্ছা করে ছোঁয়া হয়নি: ওগুলো
     * মিলিয়ে দিলে সত্যিকারের আলাদা নামও এক হয়ে যেত।
     */
    public static function normaliseName(?string $name): string
    {
        $value = mb_strtolower(trim((string) $name));

        // যতিচিহ্ন সরানোর *আগে* — নাহলে স্ল্যাশটাই থাকত না
        $value = preg_replace('/^\s*(m\/s\.?|messrs\.?)\s*/u', '', $value) ?? $value;

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    /**
     * ফোনের তুলনাযোগ্য রূপ — কেবল অঙ্ক, আর দেশের কোড ছাড়া।
     *
     * একই নম্বর মানুষ তিন রকমে লেখেন: 01712345678, +8801712345678,
     * 8801712345678। তিনটাই এক, আর তিনটাই আলাদা সারি বানাত।
     *
     * শেষ ৯টা অঙ্ক রাখা হয়: বাংলাদেশের মোবাইল নম্বরে ওটাই আসল অংশ,
     * আর তাতে 0 বা 880 উপসর্গ থাকা-না-থাকা কোনো তফাত করে না।
     */
    public static function normalisePhone(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        return mb_substr($digits, -9);
    }

    /**
     * ফোন ধরে মিল — শক্ত সংকেত, তাই আটকায়।
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns  যে ঘরগুলোতে ফোন থাকতে পারে
     */
    public function assertPhoneIsFree(
        string $model,
        array $columns,
        ?string $phone,
        ?int $ignoreId = null,
        string $field = 'phone',
    ): void {
        $needle = self::normalisePhone($phone);

        if ($needle === '') {
            return;
        }

        $match = $this->search($model, $ignoreId)
            ->where(function (Builder $q) use ($columns, $needle) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', '%'.$needle);
                }
            })
            ->first();

        if ($match === null) {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('core.duplicate.phone_taken', [
                'name' => $this->label($match),
                'code' => (string) ($match->code ?? ''),
            ]),
        ]);
    }

    /**
     * নাম ধরে মিল — নরম সংকেত, তাই কেবল ফেরত দেওয়া হয়।
     *
     * কল করা কোড ঠিক করে এটা নিয়ে কী করবে: সাধারণত ব্যবহারকারীকে
     * দেখানো, আর তিনি জেনেশুনে এগোলে `allow_duplicate` বসানো।
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns  যে ঘরগুলোতে নাম থাকতে পারে
     * @return Collection<int, Model>
     */
    public function nameMatches(
        string $model,
        array $columns,
        ?string $name,
        ?int $ignoreId = null,
    ): Collection {
        $needle = self::normaliseName($name);

        if ($needle === '') {
            return collect();
        }

        /*
         * তুলনাটা PHP-তে, SQL-এ নয়।
         *
         * "M/S. রহিম স্টোর" থেকে যতিচিহ্ন সরানোর কাজটা SQL-এ করতে হলে
         * প্রতিটা ঘরে নেস্টেড REPLACE-এর স্তূপ লাগত, আর সেটা কোনো
         * ইনডেক্সও ব্যবহার করত না। মাস্টার ডাটার সারি হাজারের ঘরে,
         * লাখের নয় — তাই মেমোরিতে তুলনা করাই সৎ ও সহজ।
         */
        return $this->search($model, $ignoreId)
            ->get()
            ->filter(function (Model $row) use ($columns, $needle): bool {
                foreach ($columns as $column) {
                    if (self::normaliseName($row->{$column}) === $needle) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    private function search(string $model, ?int $ignoreId): Builder
    {
        $query = $model::query()->where('company_id', CompanyContext::id());

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query;
    }

    private function label(Model $row): string
    {
        return (string) ($row->name_en ?? $row->name_bn ?? $row->getKey());
    }
}
