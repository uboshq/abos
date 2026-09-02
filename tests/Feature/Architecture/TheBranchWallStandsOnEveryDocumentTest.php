<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Concerns\HasDocumentStatus;
use App\Core\Concerns\ScopedToUserBranch;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use ReflectionClass;
use Tests\TestCase;

/**
 * শাখার দেয়াল প্রতিটা দলিলের উপর দাঁড়ায় — কেবল কয়েকটার উপর নয়।
 *
 * ── কী ভাঙা ছিল, ২ সেপ্টেম্বর ২০২৬ ───────────────────────────────────
 * [[ScopedToUserBranch]] কোম্পানির **ভেতরে** দেয়াল তোলে: যাঁর শাখা বাঁধা
 * আছে তিনি অন্য শাখার সারি দেখেন না, আর দেয়ালটা কোয়েরি স্তরে বসে বলে
 * রুট-বাইন্ডিংও ছাঁকা হয় — অর্থাৎ আইডি জেনেও খোলা যায় না।
 *
 * **কিন্তু trait-টা বসানো ছিল মাত্র চারটা দলিলে**: বিক্রয় বিল, ক্রয় বিল,
 * জাবেদা ও আদায়। একই পরিবারের বাকিগুলোয় নয় — বিক্রয়াদেশ, চালান,
 * ফেরত, ক্রয়াদেশ, প্রাপ্তি, পরিশোধ, স্থানান্তর, উৎপাদন, চালানপত্র,
 * বেতনের দৌড়।
 *
 * অর্থাৎ দেয়ালটা ছিল **অর্ধেক দেয়াল**: একজন শাখা-সীমিত ব্যবহারকারী
 * অন্য শাখার বিলটা দেখতে পেতেন না, অথচ সেই বিলের **ক্রয়াদেশটা** দেখতে
 * পেতেন — আর ক্রয়াদেশে দর, পরিমাণ ও সরবরাহকারী সবই লেখা।
 *
 * ── কেন আজ কিছুই বদলায়নি, তবু কাজটা আজই ──────────────────────────────
 * আজ কোনো ব্যবহারকারীর শাখা-সীমা বসানো নেই, তাই trait-টা কারও জন্য
 * কিছু ছাঁকে না — **আজকের আচরণ অবিকল আগের মতো**। কিন্তু যেদিন প্রথম
 * ব্যবহারকারীর সীমা বসবে, সেদিন দেয়ালটা হয় পুরো থাকবে, নয় থাকবে না।
 * সেদিন এটা খুঁজে বের করার চেয়ে আজ বসিয়ে রাখা সস্তা।
 *
 * ── কেন পাহারাটা দরকার ───────────────────────────────────────────────
 * নতুন দলিল যোগ হবে, আর `use ScopedToUserBranch;` লাইনটা ভুলে যাওয়ার
 * **কোনো লক্ষণ নেই**: পর্দা খোলে, তালিকা আসে, কেউ অভিযোগ করেন না।
 * ভুলটা কেবল সেই ব্যবহারকারীর কাছে দেখা যায় যাঁর সেটা দেখার কথা নয়,
 * আর তিনি বলতে আসেন না।
 */
class TheBranchWallStandsOnEveryDocumentTest extends TestCase
{
    /**
     * যেসব সারিতে দেয়াল ইচ্ছাকৃতভাবে নেই — আর কেন।
     *
     * চারটাই **মাস্টার**, দলিল নয়। এরা `HasDocumentStatus` ব্যবহার করে
     * কেবল সচল/নিষ্ক্রিয় অবস্থাটা রাখার জন্য, আর সেটাই এদের এই
     * তালিকায় টেনে এনেছে।
     *
     * @var array<class-string, string>
     */
    private const NO_WALL_ON_PURPOSE = [
        Customer::class => 'গ্রাহক কোম্পানির, শাখার নয়। এক শাখা থেকে খোলা গ্রাহক '
            .'অন্য শাখা থেকেও কেনেন, আর দেয়াল তুললে দ্বিতীয় শাখা তাঁকে '
            .'আবার খুলতেন — একই মানুষ দুইবার, দুই বকেয়া।',

        Supplier::class => 'একই কারণ। `branch_id` কেবল বলে কোথা থেকে খোলা হয়েছিল, '
            .'কার সম্পত্তি তা নয়।',

        Account::class => 'হিসাবের তালিকা পুরো কোম্পানির একটাই। শাখা ধরে ছাঁকলে '
            .'রেওয়ামিল শাখাভেদে আলাদা হত, আর সেটা হিসাববিজ্ঞান নয়।',

        CashTill::class => 'ক্যাশ ড্রয়ার একটা সেটিংস সারি, দলিল নয়। ⚠️ এখানে দেয়াল '
            .'তোলা যুক্তিসঙ্গত হত — এক শাখার লোক অন্য শাখার ড্রয়ার দেখেন '
            .'কেন — কিন্তু সেটা আচরণ বদলায়, তাই মালিকের সিদ্ধান্তের অপেক্ষায় '
            .'(২ সেপ্টেম্বর ২০২৬)।',
    ];

    /**
     * শাখা রাখে এমন প্রতিটা দলিল দেয়ালের ভেতরে।
     */
    public function test_every_document_that_records_a_branch_is_walled(): void
    {
        $documents = $this->documentModels();

        $this->assertNotSame([], $documents, implode(PHP_EOL, [
            'একটাও দলিল-মডেল খুঁজে পাওয়া গেল না।',
            '',
            'তাহলে পাহারাটা কিছুই দেখছে না, অথচ সবুজ — যে ব্যর্থতাটা',
            'সবচেয়ে খারাপ, কারণ সেটা আত্মবিশ্বাস দেয়।',
        ]));

        $open = [];

        foreach ($documents as $model) {
            if (array_key_exists($model, self::NO_WALL_ON_PURPOSE)) {
                continue;
            }

            if (! $this->uses($model, ScopedToUserBranch::class)) {
                $open[] = class_basename($model);
            }
        }

        $this->assertSame([], $open, implode(PHP_EOL, [
            'এই দলিলগুলো শাখা রাখে, কিন্তু শাখার দেয়ালের বাইরে:',
            '',
            implode(PHP_EOL, $open),
            '',
            'অর্থাৎ শাখা-সীমিত একজন ব্যবহারকারী আইডি জানলেই অন্য শাখার',
            'নথিটা খুলতে পারবেন।',
            '',
            'মডেলে `use ScopedToUserBranch;` যোগ করুন। সত্যিই দেয়াল না',
            'থাকার কথা হলে এই ফাইলের NO_WALL_ON_PURPOSE তালিকায় কারণসহ',
            'লিখুন।',
        ]));
    }

    /**
     * ছাড়ের তালিকাটা বাসি হয়ে পড়ে থাকে না।
     *
     * একটা মডেল মুছে গেলে বা দেয়ালের ভেতরে চলে এলে উপরের পরীক্ষাটা
     * সবুজই থাকত, আর ব্যাখ্যাটা চিরকাল পড়ে থাকত — এমন একটা সিদ্ধান্তের
     * কারণ যা আর কেউ নেয়নি।
     */
    public function test_the_exemption_list_stays_true(): void
    {
        $stale = [];

        foreach (array_keys(self::NO_WALL_ON_PURPOSE) as $model) {
            if (! class_exists($model)) {
                $stale[] = class_basename($model).' — ক্লাসটাই নেই';

                continue;
            }

            if ($this->uses($model, ScopedToUserBranch::class)) {
                $stale[] = class_basename($model).' — এখন দেয়ালের ভেতরে, ছাড়টা অর্থহীন';
            }
        }

        $this->assertSame([], $stale, implode(PHP_EOL, [
            'ছাড়ের তালিকায় বাসি সারি:',
            '',
            implode(PHP_EOL, $stale),
        ]));
    }

    // ── মাপার যন্ত্রপাতি ─────────────────────────────────────────────

    /**
     * দলিল = অবস্থা রাখে **আর** শাখা রাখে।
     *
     * দুইটা শর্তই দরকার। কেবল `branch_id` দেখলে প্রতিটা সেটিংস সারিও
     * তালিকায় আসত; কেবল অবস্থা দেখলে শাখাহীন কাগজও আসত, আর ওদের
     * উপর দেয়াল তোলার কিছু নেই।
     *
     * @return list<class-string>
     */
    private function documentModels(): array
    {
        $models = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Modules'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if (! $file->isFile() || ! str_contains($path, '/Models/') || ! str_ends_with($path, '.php')) {
                continue;
            }

            $class = 'App\\'.str_replace('/', '\\', substr($path, strpos($path, '/app/') + 5, -4));

            if (! class_exists($class)) {
                continue;
            }

            if (! $this->uses($class, HasDocumentStatus::class)) {
                continue;
            }

            /*
             * শাখা রাখে কি না — মডেলের নিজের ঘোষণা ধরে, ডাটাবেজে
             * জিজ্ঞেস করে নয়। কলামটা থাকলেও মডেল যদি ওটা ভরে না,
             * তবে দেয়াল তোলার মতো কিছু নেই।
             */
            if (! in_array('branch_id', (new $class)->getFillable(), true)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * ক্লাসটা এই trait ব্যবহার করে কি না — উত্তরাধিকার সহ।
     *
     * @param  class-string  $class
     * @param  class-string  $trait
     */
    private function uses(string $class, string $trait): bool
    {
        $reflection = new ReflectionClass($class);
        $traits = [];

        while ($reflection !== false) {
            $traits = [...$traits, ...$reflection->getTraitNames()];
            $reflection = $reflection->getParentClass();
        }

        return in_array($trait, $traits, true);
    }
}
