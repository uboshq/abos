<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StorageLocation;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * গুদামের ভিতরের জায়গাগুলো — ব্লক ▸ র‍্যাক ▸ শেলফ।
 *
 * ── কেন গুদামের নিজের চাবি, নতুন চাবি নয় ─────────────────────────────
 * ⛔ নতুন একটা `PermissionKey` বসালে **সেটা প্রথমে কেউ পেত না** — এই
 * রিপোতে চাবি বিলি হয় enum-এ লেখা ডিফল্ট রোল ধরে, আর নতুন চাবি কোনো
 * রোলে থাকে না। ফল হত একটা লাইভ পর্দা যেটা সবার জন্য ৪০৩।
 *
 * ⭐ আর যুক্তিটাও মেলে: তাক গুদামের **সংজ্ঞার অংশ**, আলাদা কোনো
 * লেনদেন নয়। যিনি গুদাম বানাতে পারেন, তিনিই তার তাক সাজাতে পারেন।
 *
 * ⚠️ বসানোর অনুমতি (`inventory.stock.place`) এর থেকে আলাদা, আর
 * থাকবেও — তাক সাজানো মাসে একবারের কাজ, বসানো রোজকার।
 */
class StorageLocationController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:inventory.warehouse.view', only: ['index']),
            new Middleware('can:inventory.warehouse.update', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request, Warehouse $warehouse): View
    {
        return view('inventory::warehouse.places', [
            'menu' => $this->menu->forUser($request->user()),
            'warehouse' => $warehouse,

            /*
             * ⓘ গোটা গাছটা একবারে — গুদামে পাঁচশো শেলফ থাকলেও এটা
             * একটা কোয়েরি। ⛔ স্তর ধরে আলাদা করে আনলে তিনটা কোয়েরি
             * হত, আর গভীরতা বাড়ালে চারটা।
             */
            'places' => StorageLocation::query()
                ->where('warehouse_id', $warehouse->id)
                ->inWalkingOrder()
                ->get(),
        ]);
    }

    public function store(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $this->validated($request, $warehouse);

        StorageLocation::create([
            'warehouse_id' => $warehouse->id,
            'branch_id' => $warehouse->branch_id,
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('saved', __('inventory::message.place_created'));
    }

    public function update(Request $request, Warehouse $warehouse, StorageLocation $place): RedirectResponse
    {
        $this->sameWarehouse($warehouse, $place);

        $place->update($this->validated($request, $warehouse, $place));

        return back()->with('saved', __('inventory::message.place_updated'));
    }

    /**
     * মোছা নয়, নিষ্ক্রিয় করা।
     *
     * ⚠️ চলাচলের সারিগুলো এই জায়গাটার দিকে দেখায়। মুছে ফেললে গত
     * বছরের একটা কার্টন কোথায় ছিল সেই উত্তরটা চিরতরে যেত — আর ঠিক
     * ঐ প্রশ্নটাই রিকলের দিনে করা হয়।
     */
    public function destroy(Warehouse $warehouse, StorageLocation $place): RedirectResponse
    {
        $this->sameWarehouse($warehouse, $place);

        $place->update(['is_active' => false]);

        return back()->with('saved', __('inventory::message.place_retired'));
    }

    /**
     * ⛔ URL-এ অন্য গুদামের জায়গার আইডি বসিয়ে দেওয়া আটকানো।
     *
     * ⓘ দুইটা আইডিই পথে আছে, তাই Laravel নিজে থেকে মেলায় না — এটা
     * `scoped bindings` না লিখলে চুপচাপ কাজ করে যেত।
     */
    private function sameWarehouse(Warehouse $warehouse, StorageLocation $place): void
    {
        abort_unless((int) $place->warehouse_id === (int) $warehouse->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Warehouse $warehouse, ?StorageLocation $place = null): array
    {
        $data = $request->validate([
            'code' => [
                'required', 'string', 'max:32',
                /*
                 * ⚠️ অনন্যতা **বাবার নিচে**, গুদাম ধরে নয় — প্রতিটা
                 * র‍্যাকের নিচে "শেলফ ১" থাকা স্বাভাবিক।
                 */
                Rule::unique('inv_storage_locations', 'code')
                    ->where('warehouse_id', $warehouse->id)
                    ->where('parent_id', $request->input('parent_id'))
                    ->whereNull('deleted_at')
                    ->ignore($place?->id),
            ],
            'name_en' => ['required', 'string', 'max:191'],
            'name_bn' => ['nullable', 'string', 'max:191'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('inv_storage_locations', 'id')->where('warehouse_id', $warehouse->id),
            ],
            'is_active' => ['nullable', 'boolean'],
        ]);

        /*
         * গভীরতাটা **বাবার থেকে গোনা হয়**, ফর্ম থেকে নেওয়া হয় না।
         *
         * ⛔ ব্যবহারকারীকে বাছতে দিলে "একটা র‍্যাকের নিচে আরেকটা ব্লক"
         * বসানো যেত, আর গাছটা তখন আর ব্লক▸র‍্যাক▸শেলফ থাকত না — পর্দার
         * তিনটা ঘর ভুল সারি দেখাত।
         *
         * ⚠️ তিনের বেশি গভীরে যেতে দেওয়া হয় না, কারণ আজকের পর্দা
         * তিনটাই আঁকে। টেবিলটা গভীরতর গাছ ধরে রাখতে পারে, তাই সীমাটা
         * এখানে — একদিন পর্দা বদলালে এই একটা লাইনই বদলাবে।
         */
        $parent = filled($data['parent_id'] ?? null)
            ? StorageLocation::findOrFail($data['parent_id'])
            : null;

        $depth = ($parent?->depth ?? 0) + 1;

        abort_if($depth > StorageLocation::SHELF, 422);

        return [...$data, 'depth' => $depth];
    }
}
