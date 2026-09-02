<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Services\MenuBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * যেকোনো মডিউলের ড্যাশবোর্ড — একটাই কন্ট্রোলার।
 *
 * ── কেন কোরে, প্রতিটা মডিউলে নয় ──────────────────────────────────────
 * বারোটা মডিউলে বারোটা কন্ট্রোলার মানে বারো জায়গায় একই কাজ: মেনু
 * তোলা, অনুমতি দেখা, ভিউ ডাকা। একটায় বদল আনলে বাকি এগারোটা পিছিয়ে
 * থাকত, আর কেউ সেটা খেয়াল করত না যতক্ষণ না দুইটা পর্দা পাশাপাশি দেখা হয়।
 *
 * ── কোর তবু কোনো মডিউলের নাম জানে না ─────────────────────────────────
 * `{module}` ঠিকানা থেকে আসে আর [[ModuleRegistry]]-তে মেলানো হয়। কোরে
 * `['inventory', 'sales', …]` বলে কোনো তালিকা নেই — নতুন মডিউল কেবল
 * `module.php`-তে ঘোষণা করলেই তার ড্যাশবোর্ড কাজ করে (§১৯.৭)।
 */
class ModuleDashboardController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly DashboardEngine $engine,
    ) {}

    public function show(Request $request, string $module): View
    {
        /*
         * ── কেন ৪০৪, ৫০০ নয় ─────────────────────────────────────────
         * `{module}` ঠিকানার অংশ, তাই যেকোনো লেখা আসতে পারে — পুরনো
         * বুকমার্ক, হাতে টাইপ, বা মুছে ফেলা মডিউল। ইঞ্জিন ব্যতিক্রম
         * ছোড়ে, আর সেটা এখানে না ধরলে ব্যবহারকারী একটা ভাঙা পাতা
         * দেখতেন — অথচ ঘটনাটা স্বাভাবিক: **এমন কোনো পাতা নেই।**
         */
        if (! $this->engine->has($module)) {
            throw new NotFoundHttpException;
        }

        $user = $request->user();

        /*
         * ── দরজাটাও বন্ধ, কেবল সংখ্যাগুলো নয় ─────────────────────────
         * ইঞ্জিন চাবিহীন সংখ্যা ঢাকে আর চাবিহীন টাইল বাদ দেয়। কিন্তু
         * **পাতাটা নিজে** ৩১ আগস্ট পর্যন্ত যেকোনো লগইন-করা মানুষের
         * জন্য খোলা ছিল: মেনুতে সারিটা না দেখলেও ঠিকানা টাইপ করলেই
         * শিরোনাম, উপশিরোনাম আর কাঠামোটা দেখা যেত।
         *
         * `EveryRouteIsGuardedTest` সেটা ধরেছে (২ সেপ্টেম্বর ২০২৬)।
         * চাবিটা মডিউলের নিজের মেনু-সারি থেকে আসে, তাই কোর এখানেও
         * কোনো মডিউলের নাম জানে না (§১৯.৭)।
         */
        $permission = $this->engine->permissionFor($module);

        abort_if($permission === null, 403);

        $this->authorize($permission);

        $dashboard = $this->engine->for($module, $user);

        return view('dashboard.module', [
            'menu' => $this->menu->forUser($user),
            'module' => $module,
            'dashboard' => $dashboard,
        ]);
    }
}
