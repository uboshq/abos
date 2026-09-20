<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Module\ModuleRegistry;
use Tests\TestCase;

/**
 * যে অধিকার আমরা দিই, সেটা কিছু একটা আটকায়।
 *
 * ── ⛔ কেন এটা পাহারার মতো জিনিস ─────────────────────────────────────
 * ভূমিকার পর্দায় প্রতিটা অনুমতি একটা টিকের ঘর। ⚠️ যে ঘরটা কোথাও যাচাই
 * হয় না, সেটা কেবল **অকেজো নয় — মিথ্যা**: কেউ ওটা টিক দিয়ে ভাবেন
 * অধিকারটা দেওয়া হলো, আর অন্য কেউ টিক তুলে ভাবেন আটকানো হলো। দুইজনেই
 * ভুল, আর কেউ কোনোদিন টের পান না।
 *
 * ⓘ ২১ সেপ্টেম্বর ২০২৬-এর নিরীক্ষায় দুইটা এমন ঘর পাওয়া গেছে, আর তার
 * একটা ছিল **`accounts.voucher.approve`** — অর্থাৎ একটা "অনুমোদনের
 * অধিকার" যেটা কিছুই আটকাত না। দুইটাই তোলা হয়েছে।
 *
 * ── কী গোনা হয় ──────────────────────────────────────────────────────
 * প্রতিটা মডিউলের `module.php`-তে ঘোষিত নাম, আর সেই নামটা কোডে কোথাও
 * আছে কি না — `can:` মিডলওয়্যার, `@can` ব্লেড, `$this->authorize()`,
 * `->can()`, বা মেনুর `permission` ঘর। ⓘ ঘোষণার ফাইলটা নিজে বাদ, নাহলে
 * প্রতিটা নাম নিজেকেই খুঁজে পেত।
 */
final class EveryRightWeHandOutStopsSomethingTest extends TestCase
{
    /**
     * ⏳ এখনো কিছুই আটকায় না — আর এই তালিকাটা **বড় হবে না**।
     *
     * ⚠️ নতুন নাম এখানে যোগ করা মানে পাহারাটাকে ফাঁকি দেওয়া। ⓘ সঠিক
     * উত্তর দুইটার একটা: হয় অনুমতিটা কোথাও যাচাই করুন, নয় ঘোষণা থেকে
     * তুলে দিন।
     *
     * ⓘ নিচের নয়টার প্রথম পাঁচটা দেখতে "মডিউলের ছাতা-অনুমতি"-র মতো
     * (`sales.manage`, `purchase.manage`…)। ⚠️ সেটা ইচ্ছাকৃত হতে পারে,
     * কিন্তু তাহলে কারণটা লেখা দরকার — নাহলে পরের জন একই প্রশ্নে সময়
     * দেবেন, ঠিক যেমন আজ দেওয়া হলো।
     *
     * @var array<string, string>
     */
    private const NOT_YET_CHECKED = [
        /*
         * ⓘ এটা পাহারাটা নিজে ধরেছে, আমার হাতে-চালানো খোঁজা নয় — নামটা
         * দুইটা সেবার **মন্তব্যে** আছে, যেখানে লেখা ছিল ওটা ভুল চাবি
         * ছিল আর যাচাইটা অন্য নামে সরেছে। ⚠️ ঘোষণাটা রয়ে গিয়েছিল।
         */
        'sales.discount.override' => 'ভুল চাবি বলে যাচাইটা সরে গেছে, ঘোষণা রয়ে গেছে (২১ সেপ্টেম্বর ২০২৬)',
        'customer.manage' => 'ছাতা-অনুমতি, এখনো কোথাও যাচাই হয় না (২১ সেপ্টেম্বর ২০২৬)',
        'sales.manage' => 'ছাতা-অনুমতি, এখনো কোথাও যাচাই হয় না (২১ সেপ্টেম্বর ২০২৬)',
        'purchase.manage' => 'ছাতা-অনুমতি, এখনো কোথাও যাচাই হয় না (২১ সেপ্টেম্বর ২০২৬)',
        'inventory.manage' => 'ছাতা-অনুমতি, এখনো কোথাও যাচাই হয় না (২১ সেপ্টেম্বর ২০২৬)',
        'supplier.manage' => 'ছাতা-অনুমতি, এখনো কোথাও যাচাই হয় না (২১ সেপ্টেম্বর ২০২৬)',
        'restaurant.view' => 'রেস্তোরাঁর পর্দাগুলো এখনো অন্য অনুমতিতে চলে (২১ সেপ্টেম্বর ২০২৬)',
        'restaurant.kitchen.manage' => 'একই — রান্নাঘরের পর্দা এখনো এটা মাপে না',
        'hr.identity.view' => 'কর্মীর পরিচয়পত্রের ঘরগুলো এখনো আলাদা করে পাহারা পায়নি',
        'system_admin.audit.view' => 'অডিট পর্দা `governance.audit.view` মাপে; এই নামটা রয়ে গেছে',
    ];

    public function test_every_declared_permission_is_checked_somewhere(): void
    {
        $declared = $this->declaredPermissions();

        /*
         * ⚠️ দাবিটা আগে — ঘোষণা না পড়তে পারলে নিচের তালিকা খালি আসত আর
         * পরীক্ষাটা সবুজ দেখিয়ে কিছুই মাপত না।
         */
        $this->assertGreaterThan(150, count($declared),
            'অনুমতির ঘোষণাই পাওয়া যায়নি — পরীক্ষাটা কিছু মাপছে না।');

        $haystack = $this->everythingButTheDeclarations();

        $idle = [];

        foreach ($declared as $permission => $module) {
            if (array_key_exists($permission, self::NOT_YET_CHECKED)) {
                continue;
            }

            if (! str_contains($haystack, "'".$permission."'")
                && ! str_contains($haystack, 'can:'.$permission)
                && ! str_contains($haystack, '"'.$permission.'"')) {
                $idle[] = $module.' — '.$permission;
            }
        }

        sort($idle);

        $this->assertSame([], $idle, implode("\n", [
            'এই অনুমতিগুলো ঘোষিত, কিন্তু কোথাও যাচাই হয় না।',
            '',
            '⚠️ ভূমিকার পর্দায় ওগুলো টিকের ঘর হয়ে বসে থাকে, আর কিছুই আটকায় না —',
            'অর্থাৎ ঘরটা মিথ্যা বলে।',
            '',
            'হয় কোথাও যাচাই করুন (can: / @can / authorize()), নয় ঘোষণা থেকে তুলুন।',
            '',
            ...$idle,
        ]));
    }

    /**
     * ছাড়ের তালিকায় মৃত নাম জমে থাকে না।
     *
     * ⓘ অনুমতিটা যাচাই হতে শুরু করলে, বা ঘোষণা থেকে উঠে গেলে, সারিটা
     * এখানে পড়ে থাকত — আর পরের জন ভাবতেন কাজটা এখনো বাকি।
     */
    public function test_the_waiting_list_names_only_permissions_that_exist(): void
    {
        $declared = $this->declaredPermissions();

        $stale = array_values(array_diff(array_keys(self::NOT_YET_CHECKED), array_keys($declared)));

        sort($stale);

        $this->assertSame([], $stale, implode("\n", [
            'এই নামগুলো আর ঘোষিত নয় — অপেক্ষার তালিকা থেকে মুছে ফেলুন:',
            ...$stale,
        ]));
    }

    /**
     * @return array<string, string> অনুমতি => মডিউলের নাম
     */
    private function declaredPermissions(): array
    {
        $out = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->permissions as $permission) {
                $out[$permission] = $module->code;
            }
        }

        return $out;
    }

    /**
     * ঘোষণার ফাইলগুলো বাদে গোটা কোডবেস, এক তারে।
     *
     * ⚠️ `module.php` বাদ, নাহলে প্রতিটা নাম নিজের ঘোষণাতেই নিজেকে খুঁজে
     * পেত আর পরীক্ষাটা সবসময় সবুজ থাকত। ⓘ কিন্তু মেনুর `permission`
     * ঘরও ঐ ফাইলেই — তাই সেটা আলাদা করে তোলা হয়।
     */
    private function everythingButTheDeclarations(): string
    {
        $text = '';

        foreach ([base_path('app'), base_path('resources/views'), base_path('routes')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                if (str_ends_with($path, '/module.php')) {
                    /*
                     * ⓘ মেনুর সারিগুলো ঘোষণা নয়, **ব্যবহার** — ওখানে নাম
                     * থাকা মানে সত্যিই একটা পর্দা ঐ অনুমতির পিছনে।
                     */
                    $text .= implode("\n", $this->menuPermissionsIn($file->getPathname()));

                    continue;
                }

                $text .= (string) file_get_contents($file->getPathname());
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function menuPermissionsIn(string $path): array
    {
        preg_match_all("/'permission' => '([a-z_.]+)'/", (string) file_get_contents($path), $found);

        return array_map(fn (string $p) => "'".$p."'", $found[1]);
    }
}
