<?php

declare(strict_types=1);

namespace App\Modules\Executive\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Models\SisterLink;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ভাই-কোম্পানির পক্ষ — "এই কোম্পানির এই ক্রেতা/সরবরাহকারী = আমাদের ঐ কোম্পানি"।
 *
 * ⛔ দুইটা কোম্পানিই মানুষটার মালিকের কেন্দ্রের তালিকায় থাকতে হবে ([[CompanyLens::companies()]]), আর যে
 * কোম্পানির পক্ষ জোড়া হচ্ছে সেখানে `executive.links.manage` চাবি — ঐ কোম্পানিতে বসে জিজ্ঞেস করা।
 * ⓘ জোড়া বসলে বা উঠলে মানুষটার মালিকের কেন্দ্রের ক্যাশ বাসি হয়, যাতে গ্রুপের মোট সাথে সাথে বদলায়।
 */
final class LinksController extends Controller implements HasMiddleware
{
    public const KEY = 'executive.links.manage';

    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly CompanyLens $lens,
        private readonly Board $board,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:'.self::KEY)];
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $companies = collect($this->lens->companies($user))->keyBy('id');

        /*
         * ⛔ পক্ষের তালিকা কেবল সেই কোম্পানির, যেখানে মানুষটার জোড়া দেওয়ার চাবি আছে — ঐ কোম্পানিতে বসে জিজ্ঞেস
         * করা, [[store()]]-এর একই প্রশ্ন। ec, Executive লাইন-নিরীক্ষা, ১৪ অক্টোবর ২০২৬: রুটের পাহারা কেবল চলতি
         * কোম্পানির চাবি দেখে, তাই `?company=` দিয়ে যে কোম্পানিতে কেবল `executive.view` আছে, সেখানকার সব
         * ক্রেতা আর সরবরাহকারীর কোড-নাম খুলে যেত। ⓘ "আসলে আমাদের কোন কোম্পানি" তালিকা আগের মতোই সবগুলো।
         */
        $owning = $companies->filter(fn (array $c) => $user->canInCompany($c['id'], self::KEY));
        $chosen = $owning->has($request->integer('company')) ? $request->integer('company') : $owning->keys()->first();

        $parties = $chosen === null ? [] : $this->lens->within($user, (int) $chosen, null, fn () => [
            // ⭐ "কোড — নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); জায়গা একবারে
            SisterLink::CUSTOMER => Customer::acrossDealers()->inViewedBranch()->with(Customer::PLACE)->orderBy('code')->get()->map(fn (Customer $c) => ['id' => (int) $c->id, 'name' => trim($c->code.' — '.$c->nameWithPoint())])->all(),
            SisterLink::SUPPLIER => Supplier::query()->inViewedBranch()->orderBy('code')->get()->map(fn (Supplier $s) => ['id' => (int) $s->id, 'name' => trim($s->code.' — '.$s->name())])->all(),
        ]);

        $links = SisterLink::acrossAllCompanies()
            ->whereIn('company_id', $companies->keys()->all() ?: [0])
            ->whereIn('sister_company_id', $companies->keys()->all() ?: [0])
            ->orderBy('company_id')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        /*
         * ⭐ পক্ষের নাম কোম্পানি ধরে একবারে — গ্রাহক হলে "নাম · পয়েন্ট · শাখা" (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম)।
         * ⓘ আগে সারি প্রতি একটা প্রশ্ন ছিল; এখন পাতার প্রতিটা কোম্পানিতে ধরন প্রতি একটা (জায়গাসহ)।
         */
        $partyNames = [];

        foreach ($links->getCollection()->groupBy('company_id') as $companyId => $rows) {
            $partyNames += $this->lens->within($user, (int) $companyId, null, function () use ($rows, $companyId) {
                $ids = fn (bool $customers) => $rows->filter(fn (SisterLink $l) => ($l->party_type === SisterLink::CUSTOMER) === $customers)
                    ->pluck('party_id')->map(fn ($id) => (int) $id)->all() ?: [0];
                $out = [];

                foreach (Customer::acrossDealers()->inViewedBranch()->whereIn('customers.id', $ids(true))->with(Customer::PLACE)->get() as $c) {
                    $out[$companyId.':'.SisterLink::CUSTOMER.':'.$c->id] = $c->nameWithPoint();
                }

                foreach (Supplier::query()->inViewedBranch()->whereIn('suppliers.id', $ids(false))->get() as $s) {
                    $out[$companyId.':supplier:'.$s->id] = $s->name();
                }

                return $out;
            });
        }

        $links->through(fn (SisterLink $link) => [
            'id' => (int) $link->id,
            'company' => $companies[$link->company_id]['name'],
            'sister' => $companies[$link->sister_company_id]['name'],
            'type' => $link->party_type,
            'party' => $partyNames[$link->company_id.':'.($link->party_type === SisterLink::CUSTOMER ? SisterLink::CUSTOMER : 'supplier').':'.$link->party_id]
                ?? '#'.$link->party_id,
        ]);

        return view('executive::links', [
            'menu' => $this->menu->forUser($user),
            'companies' => $companies->values()->all(),
            'owning' => $owning->values()->all(),
            'chosen' => $chosen,
            'parties' => $parties,
            'links' => $links,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'party' => ['required', 'string', 'regex:/^(customer|supplier):[0-9]+$/'],
            'sister_company_id' => ['required', 'integer', 'different:company_id'],
        ]);

        $user = $request->user();
        $company = (int) $data['company_id'];
        $sister = (int) $data['sister_company_id'];
        [$type, $partyId] = explode(':', (string) $data['party']);
        $ids = array_column($this->lens->companies($user), 'id');

        if (! in_array($company, $ids, true) || ! in_array($sister, $ids, true) || ! $user->canInCompany($company, self::KEY)) {
            throw ValidationException::withMessages(['company_id' => __('executive::today.not_yours')]);
        }

        // ⓘ পক্ষটা ঐ কোম্পানিরই কি না — ঐ কোম্পানিতে বসে খোঁজা
        $exists = $this->lens->within($user, $company, null, fn () => $type === SisterLink::CUSTOMER
            ? Customer::acrossDealers()->inViewedBranch()->whereKey((int) $partyId)->exists()
            : Supplier::query()->inViewedBranch()->whereKey((int) $partyId)->exists());

        if (! $exists) {
            throw ValidationException::withMessages(['party' => __('executive::links.no_such_party')]);
        }

        if (SisterLink::acrossAllCompanies()->where('company_id', $company)->where('party_type', $type)->where('party_id', (int) $partyId)->exists()) {
            throw ValidationException::withMessages(['party' => __('executive::links.already')]);
        }

        $this->lens->within($user, $company, null, fn () => SisterLink::query()->create([
            'company_id' => $company,
            'party_type' => $type,
            'party_id' => (int) $partyId,
            'sister_company_id' => $sister,
            'created_by' => $user->id,
        ]));

        $this->board->refresh($user);

        return redirect()->route('executive.links', ['company' => $company])->with('saved', __('executive::links.saved'));
    }

    public function destroy(Request $request, int $link): RedirectResponse
    {
        $user = $request->user();
        $row = SisterLink::acrossAllCompanies()->findOrFail($link);

        if (! in_array((int) $row->company_id, array_column($this->lens->companies($user), 'id'), true)
            || ! $user->canInCompany((int) $row->company_id, self::KEY)) {
            abort(403);
        }

        $this->lens->within($user, (int) $row->company_id, null, fn () => SisterLink::query()->whereKey($row->id)->firstOrFail()->delete());
        $this->board->refresh($user);

        return redirect()->route('executive.links')->with('saved', __('executive::links.removed'));
    }
}
