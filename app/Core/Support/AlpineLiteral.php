<?php

declare(strict_types=1);

namespace App\Core\Support;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use UnitEnum;

/**
 * সার্ভারের একটা মান, Alpine অ্যাট্রিবিউটের ভিতরে — যেভাবে CSP-Alpine পড়তে পারে।
 *
 * ── ⛔ Laravel-এর `@js` কেন চলে না, ১৯ সেপ্টেম্বর ২০২৬ ───────────────────
 * `@js(['a' => 'বিক্রয়'])` লেখে:
 *
 *     JSON.parse('{"a":"বি…"}')
 *
 * দুইটা কারণে এটা CSP-Alpine-এ ভাঙে:
 *   ১. `JSON` একটা বৈশ্বিক নাম — CSP সংস্করণ কম্পোনেন্টের বাইরের কোনো
 *      নাম দেখে না, তাই `Undefined variable: JSON`
 *   ২. ⚠️ আরও খারাপ: ওর পার্সার `\uXXXX` চেনে না। `'ব'` পড়ে
 *      **`u09ac`** — কোনো ভুলের বার্তা ছাড়াই। প্রতিটা বাংলা লেবেল
 *      পর্দায় সংখ্যা-অক্ষরের জঞ্জাল হয়ে বসত।
 *
 * ── ⭐ কী লেখা হয় বদলে ─────────────────────────────────────────────────
 * সরাসরি একটা লিটারাল — `{"a":"বিক্রয়"}` — ইউনিকোড যেমন আছে তেমনই, আর
 * HTML-এর জন্য এস্কেপ করা (`&quot;`)। ⓘ ব্রাউজার অ্যাট্রিবিউট পড়ার সময়ই
 * `&quot;`-কে `"` বানায়, তাই Alpine পায় একটা শুদ্ধ অবজেক্ট — কোনো ফাংশন
 * ডাকা ছাড়া, কোনো এস্কেপ ছাড়া।
 *
 * ⓘ অ্যাট্রিবিউটের বাইরে নিরাপদ কেন: `e()` দুই রকম উদ্ধৃতিই এস্কেপ করে,
 * আর `<`/`&`-ও — তাই `"…"` বা `'…'` যে অ্যাট্রিবিউটেই বসুক, সেটা ভাঙে না।
 */
final class AlpineLiteral
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    public static function from(mixed $data): string
    {
        return e(self::json($data));
    }

    /** এস্কেপ করার আগের লেখাটা — পরীক্ষার জন্য আলাদা */
    public static function json(mixed $data): string
    {
        if ($data instanceof Htmlable && ! $data instanceof Arrayable
            && ! $data instanceof Jsonable && ! $data instanceof JsonSerializable) {
            $data = $data->toHtml();
        }

        if ($data instanceof BackedEnum) {
            $data = $data->value;
        } elseif ($data instanceof UnitEnum) {
            $data = $data->name;
        }

        if ($data instanceof Jsonable) {
            $data = json_decode($data->toJson(), true, 512, JSON_THROW_ON_ERROR);
        } elseif ($data instanceof Arrayable && ! $data instanceof JsonSerializable) {
            $data = $data->toArray();
        }

        return json_encode($data, self::FLAGS);
    }
}
