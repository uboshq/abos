<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Control Panel — সব মডিউলের সুইচ এক জায়গায়।
 *
 * প্রতিটা মডিউলের নিজের সেটিংস পর্দাও আছে, আর সেটাই রোজকার জায়গা।
 * এই পর্দাটা আলাদা কারণে: নতুন কোম্পানি চালু করার সময় কেউ একবার বসে
 * পুরো সিস্টেমটা নিজের ব্যবসার মতো করে নেয় — তখন আটটা মডিউলের আটটা
 * পর্দা ঘুরে বেড়ানো অর্থহীন।
 *
 * এখানে কোনো মডিউলের নাম লেখা নেই, আর কখনো থাকবে না (সেকশন ১৯.৭):
 * মডিউল যা ঘোষণা করে সেটাই দেখায়। নতুন মডিউল যোগ হলে তার সুইচগুলো
 * নিজে থেকেই এখানে আসে — আর সেটাই "কোর না ছুঁয়ে নতুন মডিউল" কথাটার
 * মানে।
 */
class ControlPanelController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ModuleRegistry $registry,
        private readonly MenuBuilder $menu,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.settings.manage')];
    }

    public function edit(Request $request): View
    {
        return view('system_admin::control-panel.edit', [
            'menu' => $this->menu->forUser($request->user()),
            'modules' => $this->byModule(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /*
         * পুরো অ্যারেটা একবারে — input('settings.'.$key) দিয়ে নয়।
         *
         * সেটিং-এর কী-তে ডট আছে ("accounts.backdate_days"), আর Laravel-এর
         * input() ডটকে পথ ধরে নেয়। Accounts-এর সেটিংস পর্দায় ঠিক এই
         * ভুলটাই ছিল: প্রতিটা মান null আসত আর কিছুই সেভ হত না, নীরবে।
         *
         * @var array<string, mixed> $submitted
         */
        $submitted = (array) $request->input('settings', []);

        $changed = 0;
        $refused = [];

        foreach ($this->settings->definitions() as $key => $definition) {
            $raw = $submitted[$key] ?? null;

            $value = match ($definition['type']) {
                // চেকবক্স না দেখালে ব্রাউজার কিছুই পাঠায় না, তাই
                // অনুপস্থিতিই "বন্ধ"
                'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'integer' => $raw === null || $raw === '' ? null : (int) $raw,
                default => $raw,
            };

            if ($value === null) {
                continue;
            }

            if ($this->settings->get($key) === $value) {
                continue;
            }

            /*
             * যে পর্দায় কাগজ আছে সেটা আড়াল করতে দেওয়া হয় না।
             *
             * সুইচ বন্ধ করলে মেনু থেকে সারিটা উধাও হয়। যে কোম্পানির
             * দশটা অর্ডার ঝুলে আছে তার অর্ডার-পর্দা কেউ বন্ধ করে দিলে
             * ওই দশটা কাগজের আর কোনো দরজা থাকত না — অথচ সেগুলো বাতিলও
             * হয়নি, শেষও হয়নি। তাই খালি পর্দাই কেবল আড়াল করা যায়।
             *
             * মডিউলের নাম কোরে নেই: ক্লাসটা module.php বলে দেয় ('holds'),
             * কোর শুধু গুনে দেখে (১৯.৭)।
             */
            $holds = $definition['holds'] ?? null;

            if ($value === false && $holds !== null && $holds::query()->exists()) {
                $refused[] = __($definition['label']);

                continue;
            }

            $this->settings->set($key, $value);
            $changed++;
        }

        if ($refused !== []) {
            return back()
                ->with('saved', trans_choice(
                    'system_admin::message.switches_saved',
                    $changed,
                    ['count' => $changed],
                ))
                ->withErrors(['settings' => __('system_admin::validation.screen_holds_records', [
                    'screens' => implode('; ', $refused),
                ])]);
        }

        return back()->with('saved', trans_choice(
            'system_admin::message.switches_saved',
            $changed,
            ['count' => $changed],
        ));
    }

    /**
     * সুইচগুলো মডিউল ও গ্রুপ অনুসারে সাজানো।
     *
     * গ্রুপের নাম মডিউলের নিজের অনুবাদ থেকে আসে
     * ("accounts::settings_group.entry"), না থাকলে একটা সাধারণ নাম —
     * কারণ কোরের এখানে জানার কথা নয় কোন মডিউলে কোন গ্রুপ আছে।
     *
     * @return list<array<string, mixed>>
     */
    private function byModule(): array
    {
        $definitions = $this->settings->definitions();

        $modules = [];

        foreach ($this->registry->all() as $module) {
            $groups = [];

            foreach ($definitions as $key => $definition) {
                if (($definition['module'] ?? null) !== $module->code) {
                    continue;
                }

                $group = $definition['group'] ?? 'general';

                $groups[$group][] = [
                    ...$definition,
                    'key' => $key,
                    'value' => $this->settings->get($key),
                ];
            }

            // যে মডিউল কোনো সুইচ ঘোষণা করেনি তার জন্য খালি একটা কার্ড
            // দেখানো হয় না — খালি কার্ড শুধু জায়গা নেয়
            if ($groups === []) {
                continue;
            }

            $modules[] = [
                'code' => $module->code,
                'label' => $module->label(),
                'groups' => $groups,
            ];
        }

        return $modules;
    }
}
