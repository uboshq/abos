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
        $chosen = $companies->has($request->integer('company')) ? $request->integer('company') : $companies->keys()->first();

        $parties = $chosen === null ? [] : $this->lens->within($user, (int) $chosen, null, fn () => [
            SisterLink::CUSTOMER => Customer::acrossDealers()->inViewedBranch()->orderBy('code')->get()->map(fn (Customer $c) => ['id' => (int) $c->id, 'name' => trim($c->code.' — '.$c->name())])->all(),
            SisterLink::SUPPLIER => Supplier::query()->inViewedBranch()->orderBy('code')->get()->map(fn (Supplier $s) => ['id' => (int) $s->id, 'name' => trim($s->code.' — '.$s->name())])->all(),
        ]);

        $links = SisterLink::acrossAllCompanies()
            ->whereIn('company_id', $companies->keys()->all() ?: [0])
            ->whereIn('sister_company_id', $companies->keys()->all() ?: [0])
            ->orderBy('company_id')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (SisterLink $link) => [
                'id' => (int) $link->id,
                'company' => $companies[$link->company_id]['name'],
                'sister' => $companies[$link->sister_company_id]['name'],
                'type' => $link->party_type,
                'party' => $this->lens->within($user, (int) $link->company_id, null, fn () => ($link->party_type === SisterLink::CUSTOMER
                    ? Customer::acrossDealers()->inViewedBranch()->find($link->party_id)
                    : Supplier::query()->inViewedBranch()->find($link->party_id))?->name() ?? '#'.$link->party_id),
            ]);

        return view('executive::links', [
            'menu' => $this->menu->forUser($user),
            'companies' => $companies->values()->all(),
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
