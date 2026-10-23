<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Services\FormBranchPin;
use App\Http\Middleware\KeepsAFormInItsOwnBranch;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * ⛔ নতুন কাগজের প্রতিটা ফর্ম খোলার শাখার ছাপ বয়ে আনে — RCV-0143 / মালিক: কোনোভাবেই অন্য শাখায় নয় (ec, ১৯ অক্টোবর ২০২৬)।
 *
 * ── কী ভেঙেছিল ───────────────────────────────────────────────────────
 * ফর্ম মনে রাখত না কোন শাখায় খোলা; জমার সময় উপরের শাখা যা বলত সেবা তা-ই নিত। অন্য ট্যাবে শাখা বদলালে কাগজ অন্য শাখায়।
 *
 * ── ⭐ এই ফাইলটা কেন ──────────────────────────────────────────────────
 * সারাইটা কেন্দ্রীয় — `@csrf` ছাপটা বসায় ([[FormBranchPin]]), আর পাহারাটা ওয়েবের দলে বসে ([[KeepsAFormInItsOwnBranch]])।
 * ⚠️ কেন্দ্রীয় সারাইয়ের দাম নীরবতা: override সরলে, পাহারার লাইন মুছলে বা বোতামের জায়গা খোলস থেকে হারালে কোনো পর্দা
 * ভাঙে না — কেবল কাগজ আবার অন্য শাখায় যায়। ⓘ তাই চারটা টুকরো, আর **সংখ্যাটা গোনা হয়** যাতে শূন্য পেয়ে সবুজ না থাকে
 * ([[EveryFormCarriesItsOwnTokenTest]]-এর একই শিক্ষা)।
 */
final class EveryNewPaperFormCarriesItsBranchTest extends TestCase
{
    /** ⭐ ১৯ অক্টোবর মেপে পাওয়া ৯০টা ফর্ম (`action="{{ route('….store'`)। সীমা ৮০ — কয়েকটা পর্দা মুছে যেতে পারে। */
    private const FEWEST_FORMS = 80;

    /**
     * ⛔ প্যারামিটারহীন POST, নাম `*.store` নয়, আর [[FormBranchPin::CREATE_DOORS]]-এও নয় — কেন পাহারার বাইরে, এক লাইনে।
     *
     * ⓘ প্যারামিটারওয়ালা দরজা (`/{invoice}/confirm` …) আগে থেকেই আছে এমন কাগজের উপর কাজ করে, তার নিজের শাখায় — তাই
     * এই তালিকায় নেই। ⚠️ নতুন প্যারামিটারহীন POST এলে এখানে বা CREATE_DOORS-এ বসাতেই হবে, নইলে দাবি লাল।
     */
    private const EXCLUDED = [
        'accounts.voucher.simulate' => 'preview of a receipt/payment voucher\'s ledger lines; writes nothing and makes no person from a typed name (claimed: TheReceiptAndPaymentFollowTheFinalDesignTest, voucher/ledger/people counts unchanged)',
        'purchase.payment.simulate' => 'preview of the ledger lines; writes nothing (claimed: ThePaySupplierScreenFollowsTheDesignTest, row counts and audit unchanged)',
        'accounts.asset.depreciate' => 'monthly run; each asset posts in its own branch',
        'accounts.asset.tax.run' => 'year-end tax book run over every asset, company level',
        'accounts.coa.install' => 'installs the standard chart, no paper',
        'accounts.control.catch_up' => 'number series upkeep, no paper',
        'accounts.period.close' => 'company period lock, no paper',
        'appearance.save' => 'personal appearance',
        'approval.inbox.bulk' => 'signs existing papers in their own branch',
        'branch.switch' => 'the header switch itself',
        'company.switch' => 'the header switch itself',
        'customer.binding.handover' => 'moves dealers between salesmen, no paper',
        'customer.binding.supervisor' => 'sets a supervisor, no paper',
        'documents.ocr.fields' => 'reads text from a file, writes nothing',
        'executive.open' => 'owner centre navigation',
        'executive.refresh' => 'refreshes owner figures',
        'finance.bank_facility.accrue' => 'monthly run; each facility posts in its own branch',
        'finance.capital.owner' => 'a setting',
        'finance.deposit.accrue' => 'monthly run; each deposit posts in its own branch',
        'finance.insurance.prepay' => 'monthly run; each policy posts in its own branch',
        'finance.profit.capitalise' => 'company decision, always the head branch (ProfitDistribution::companyBranch)',
        'finance.profit.declare' => 'company decision, always the head branch (ProfitDistribution::companyBranch)',
        'finance.profit.preview' => 'preview, writes nothing',
        'finance.rental.accrue' => 'monthly run; each contract posts in its own branch',
        'finance.tenancy.charge' => 'monthly run; each tenancy posts in its own branch',
        'finance.withdrawal.cap' => 'a setting',
        'governance.error.acknowledge_all' => 'marks error rows seen',
        'home.layout' => 'personal home layout',
        'hr.leave_type.install' => 'installs default leave types, no paper',
        'hr.salary_head.install' => 'installs default salary heads, no paper',
        'inventory.stock.hold' => 'hold on a warehouse stock, the branch is the warehouse',
        'inventory.stock.opening.principal.update' => 'a setting for opening stock',
        'inventory.stock.release' => 'releases a hold on a warehouse stock, the branch is the warehouse',
        'locale.switch' => 'personal language',
        'logout' => 'sign out',
        // ⓘ প্রতিটা মাস্টার তালিকার "ডিফল্ট বসান" — MasterListController::kinds()-এর লুপে জন্মানো, সবগুলো installDefaults() ডাকে (ec, ২০ অক্টোবর ২০২৬)
        'master_data.brand.install' => 'installs default master lists, no paper',
        'master_data.cost_center.install' => 'installs default master lists, no paper',
        'master_data.currency.install' => 'installs default master lists, no paper',
        'master_data.department.install' => 'installs default master lists, no paper',
        'master_data.designation.install' => 'installs default master lists, no paper',
        'master_data.employment_type.install' => 'installs default master lists, no paper',
        'master_data.money_category.install' => 'installs default master lists, no paper',
        'master_data.opportunity_stage.install' => 'installs default master lists, no paper',
        'master_data.party_type.install' => 'installs default master lists, no paper',
        'master_data.payment_method.install' => 'installs default master lists, no paper',
        'master_data.person.install' => 'installs default master lists, no paper',
        'master_data.price_list.install' => 'installs default master lists, no paper',
        'master_data.product_category.install' => 'installs default master lists, no paper',
        'master_data.reason.install' => 'installs default master lists, no paper',
        'master_data.sales_channel.install' => 'installs default master lists, no paper',
        'master_data.tax.install' => 'installs default master lists, no paper',
        'master_data.term.install' => 'installs default master lists, no paper',
        'master_data.transfer_mode.install' => 'installs default master lists, no paper',
        'master_data.unit.install' => 'installs default master lists, no paper',
        'master_data.vehicle.install' => 'installs default master lists, no paper',
        'master_data.vehicle_type.install' => 'installs default master lists, no paper',
        'master_data.location.install' => 'installs the location list, no paper',
        'mfa.begin' => 'two-step sign in',
        'mfa.confirm' => 'two-step sign in',
        'notification.center.archive' => 'personal notifications',
        'notification.channels.vapid' => 'push channel keys',
        'notification.quiet.save' => 'personal quiet hours',
        'notifications.banner.close' => 'personal notifications',
        'notifications.bulk' => 'personal notifications',
        'notifications.push.subscribe' => 'personal push',
        'notifications.push.unsubscribe' => 'personal push',
        'notifications.read-all' => 'personal notifications',
        'paper.share' => 'a share link for an existing paper',
        'password.email' => 'password reset mail',
        'profile.avatar' => 'personal profile',
        'profile.email.request' => 'personal profile',
        'promotion.coupon.redeem' => 'counts a coupon on the sale in progress; the sale door is guarded',
        'purchase.direct.overview' => 'summary before confirm, writes nothing',
        'sales.direct.depot_check.open' => 'moves an existing source paper into depot check, its own branch',
        'sales.direct.overview' => 'summary before confirm, writes nothing',
        'sales.direct.void' => 'voids the bill in progress',
        'sales.order.offers' => 'works out offers, writes nothing',
        'sales.portal.login.attempt' => 'dealer portal sign in',
        'sales.portal.logout' => 'dealer portal sign out',
        'system_admin.import.check' => 'dry run of an import, writes nothing',
        'system_admin.look.import' => 'appearance import',
        'system_admin.look.preview.stop' => 'appearance preview',
        'theme.switch' => 'personal theme',
    ];

    /**
     * ⛔ নতুন কাগজের প্রতিটা POST দরজা হয় `*.store`, নয় [[FormBranchPin::CREATE_DOORS]]-এ, নয় উপরের বাদের তালিকায় কারণসহ।
     *
     * ⓘ রাউটার থেকে পড়া, ফাইল থেকে নয় — দলের নামের উপসর্গ (`sales.` + `pos.`) রাউটারই জানে।
     */
    public function test_every_post_door_without_a_paper_in_hand_is_guarded_or_excused(): void
    {
        $unlisted = [];
        $seen = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true) || str_starts_with($route->uri(), 'api/')
                || ! str_starts_with($route->getActionName(), 'App\\') || $route->parameterNames() !== []) {
                continue;
            }

            $name = (string) $route->getName();
            $seen[] = $name;

            if ($name === '' || (! str_ends_with($name, '.store') && ! in_array($name, FormBranchPin::CREATE_DOORS, true)
                && ! array_key_exists($name, self::EXCLUDED))) {
                $unlisted[] = $name === '' ? '(নামহীন) '.$route->uri() : $name;
            }
        }

        $this->assertGreaterThan(100, count($seen), '⛔ প্যারামিটারহীন POST দরজাই পাওয়া গেল না — খোঁজার ছাঁচ ভেঙেছে।');

        sort($unlisted);
        $this->assertSame([], $unlisted, implode("\n", array_merge(
            ['⛔ এই POST দরজাগুলো `*.store` নয়, পাহারার তালিকায়ও নেই, বাদের তালিকায়ও নেই:', ''],
            $unlisted,
            ['', 'নতুন কাগজ বানালে বা টাকা নাড়ালে FormBranchPin::CREATE_DOORS-এ, নইলে EXCLUDED-এ কারণসহ।'],
        )));

        // ⚠️ বাসি নাম নয় — তালিকার প্রতিটা নাম আজও একটা প্যারামিটারহীন POST দরজা
        $stale = array_values(array_diff([...FormBranchPin::CREATE_DOORS, ...array_keys(self::EXCLUDED)], $seen));
        $this->assertSame([], $stale, "⛔ তালিকায় আছে, দরজা নেই:\n".implode("\n", $stale));
        $this->assertSame([], array_values(array_intersect(FormBranchPin::CREATE_DOORS, array_keys(self::EXCLUDED))),
            '⛔ একই দরজা পাহারাতেও, বাদেও।');
    }

    public function test_the_csrf_directive_stamps_the_branch(): void
    {
        $this->assertStringContainsString(FormBranchPin::class, Blade::compileString('@csrf'),
            '⛔ `@csrf` আর শাখার ছাপ বসাচ্ছে না — override-টা `AppServiceProvider::boot()`-এ।');

        $html = app(FormBranchPin::class)->field();
        $this->assertStringContainsString('name="'.FormBranchPin::FIELD.'"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
    }

    public function test_the_guard_stands_in_the_web_group(): void
    {
        $this->assertContains(KeepsAFormInItsOwnBranch::class, app('router')->getMiddlewareGroups()['web'] ?? [],
            '⛔ পাহারার ক্লাস আছে, কিন্তু ওয়েবের দলে বসানো নেই — ছাপ মেলানোই হয় না।');
        $this->assertNotContains(KeepsAFormInItsOwnBranch::class, app('router')->getMiddlewareGroups()['api'] ?? [],
            '⛔ ফোনের দরজায় ছাপ নেই — সেখানে পাহারা বসলে প্রতিটা লেখা থামত।');
    }

    public function test_the_button_has_its_place_in_the_shell(): void
    {
        $layout = (string) file_get_contents(resource_path('views/components/layouts/app.blade.php'));

        // ⓘ দুইবার — পুরো পাতা আর পপআপ (পিক), দুই শাখাতেই ফর্ম ফেরত আসে
        $this->assertSame(2, substr_count($layout, '<x-shell.branch-pin />'), '⛔ থামার কারণ আর "X-এই জমা দিন" বোতাম খোলসে নেই।');
    }

    public function test_every_form_that_creates_a_paper_carries_the_csrf_anchor(): void
    {
        $naked = [];
        $counted = 0;

        foreach ($this->bladeFiles() as $path => $source) {
            foreach ($this->storeForms($source) as $form) {
                $counted++;

                if (! str_contains($form, '@csrf')) {
                    $naked[] = $path;
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::FEWEST_FORMS, $counted,
            "⛔ নতুন কাগজের ফর্ম পাওয়া গেল মাত্র {$counted}টা — খোঁজার ছাঁচ ভেঙেছে, পর্দা কমেনি।");

        $naked = array_values(array_unique($naked));
        sort($naked);

        $this->assertSame([], $naked, implode("\n", array_merge(
            ['⛔ এই পর্দাগুলোর নতুন-কাগজের ফর্মে `@csrf` নেই — তাই শাখার ছাপও নেই:', ''],
            $naked,
        )));
    }

    /**
     * একটা ফাইলের যে POST ফর্মগুলো `*.store`-এ যায় (খোলা ট্যাগের `action="{{ route('….store'`)।
     *
     * ⚠️ `->` ট্যাগের ভিতরে থাকতে পারে (`$model->id`), তাই খোলা ট্যাগ `>` দেখে কাটা হয় না।
     *
     * @return list<string>
     */
    private function storeForms(string $source): array
    {
        $forms = [];
        $offset = 0;

        while (($open = strpos($source, '<form', $offset)) !== false) {
            $close = strpos($source, '</form>', $open);
            $offset = $open + 5;

            if ($close === false) {
                continue;
            }

            $form = substr($source, $open, $close - $open);

            if (stripos($form, 'method="POST"') === false) {
                continue;
            }

            if (preg_match('/^<form\b(?:[^>]|->)*?\baction\s*=\s*"\{\{\s*route\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/s', $form, $m) === 1
                && str_ends_with($m[1], '.store')) {
                $forms[] = $form;
            }
        }

        return $forms;
    }

    /** @return array<string, string> */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([resource_path('views'), app_path('Modules')] as $root) {
            $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($walk as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1))] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }
}
