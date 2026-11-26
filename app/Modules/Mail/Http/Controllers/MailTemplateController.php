<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Customer\Models\Customer;
use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailSend;
use App\Modules\Mail\Models\MailTemplate;
use App\Modules\Mail\Services\MailDefaults;
use App\Modules\Mail\Services\MailFields;
use App\Modules\Mail\Services\MailTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ মেইলের টেমপ্লেট (নমুনা v4 পর্দা ৫) আর উৎসব ও দিবস (পর্দা ৬)।
 *
 * ⓘ কোম্পানির টেমপ্লেট `mail.manage` বানান ও বদলান, সবাই ব্যবহার করেন; নিজেরটা কেবল নিজে দেখেন ("কপি করে নিজের বানান")।
 * ⓘ ডানে আসল তথ্যে দেখা — একজন গ্রাহক বেছে নিলে ঘরগুলো তার তথ্যে বসে।
 * ⛔ নিজে থেকে পাঠানো প্রথমে বন্ধ; চালু করেন কেবল প্রশাসক। জাতীয় দিবসে কেবল শ্রদ্ধা, অফার নয়।
 * ⓘ "কে খুলেছে" গোনা হয় না (আগের অনুমোদিত পরিকল্পনা) — কেবল কতবার পাঠানো।
 */
final class MailTemplateController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MailTemplates $templates, private readonly MailFields $fields) {}

    public static function middleware(): array
    {
        return [new Middleware('can:mail.use')];
    }

    public function index(Request $request, MailDefaults $defaults): View
    {
        $defaults->ensure();
        $user = $request->user();
        $category = (string) $request->query('cat', 'collect');
        $whose = (string) $request->query('whose', '');
        $look = (string) $request->query('look', 'all');

        $all = MailTemplate::query()->seenBy($user)->orderBy('name')->get();
        $list = $all
            ->when($whose === 'company', fn ($c) => $c->whereNull('user_id'), fn ($c) => $whose === 'mine' ? $c->where('user_id', $user->id) : $c->where('category', $category))
            ->when($look === 'designed', fn ($c) => $c->where('format', 'designed'))
            ->when($look === 'plain', fn ($c) => $c->where('format', 'plain'))
            ->values();

        $selected = $request->query('t') ? $all->firstWhere('public_id', (string) $request->query('t')) : $list->first();
        $customer = $request->query('customer') && $user->can('customer.view') ? Customer::query()->find((int) $request->query('customer')) : null;
        $values = $this->fields->values($user, null) + ($customer ? $this->fields->forCustomer($customer) : []);
        $company = (string) (Company::query()->whereKey(CompanyContext::companyId())->value('name_en') ?? '');

        return view('mail::templates.index', [
            'counts' => collect(MailTemplate::CATEGORIES)->mapWithKeys(fn ($c) => [$c => $all->where('category', $c)->count()]),
            'companyCount' => $all->whereNull('user_id')->count(),
            'mineCount' => $all->where('user_id', $user->id)->count(),
            'category' => $whose !== '' ? '' : $category,
            'whose' => $whose,
            'look' => $look,
            'list' => $list->map(fn (MailTemplate $t) => ['t' => $t] + $this->templates->render($t, $values, $company)),
            'selected' => $selected,
            'rendered' => $selected ? $this->templates->render($selected, $values, $company) : null,
            'customer' => $customer,
            'customers' => $user->can('customer.view') ? Customer::query()->whereNotNull('email')->orderBy('name_en')->limit(200)->get() : collect(),
            'fields' => MailFields::FIELDS,
            'sentCount' => $selected ? MailSend::query()->where('template_id', $selected->id)->count() : 0,
            'festivals' => $category === 'festival' ? MailFestival::query()->orderBy('on_date')->get() : collect(),
            'canManage' => $user->can('mail.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $mine = ! $request->user()->can('mail.manage') || $request->boolean('mine');
        $t = MailTemplate::query()->create($data + ['user_id' => $mine ? $request->user()->id : null, 'created_by' => $request->user()->id]);

        return redirect()->route('mail.templates', ['cat' => $t->category, 't' => $t->public_id])->with('mail_flash', __('mail::message.saved'));
    }

    public function update(Request $request, string $template): RedirectResponse
    {
        $t = $this->mine($request, $template);
        $data = $this->validated($request);

        if (! $request->user()->can('mail.manage')) {
            unset($data['auto']);
        }

        $t->fill($data)->save();

        return redirect()->route('mail.templates', ['cat' => $t->category, 't' => $t->public_id])->with('mail_flash', __('mail::message.saved'));
    }

    public function copy(Request $request, string $template): RedirectResponse
    {
        $t = MailTemplate::query()->seenBy($request->user())->where('public_id', $template)->firstOrFail();
        $copy = $t->replicate(['public_id', 'used', 'auto']);
        $copy->user_id = $request->user()->id;
        $copy->name = mb_substr($t->name.' '.__('mail::field.tpl.copy_suffix'), 0, 120);
        $copy->created_by = $request->user()->id;
        $copy->save();

        return redirect()->route('mail.templates', ['whose' => 'mine', 't' => $copy->public_id])->with('mail_flash', __('mail::message.saved'));
    }

    public function destroy(Request $request, string $template): RedirectResponse
    {
        $t = $this->mine($request, $template);
        $t->forceFill(['active' => false])->save();

        return redirect()->route('mail.templates', ['cat' => $t->category])->with('mail_flash', __('mail::message.template_hidden'));
    }

    /** ⭐ উৎসবের তারিখ আর চালু/বন্ধ — প্রশাসক; চাঁদের দিন প্রতি বছর হাতে */
    public function festival(Request $request, int $festival): RedirectResponse
    {
        abort_unless($request->user()->can('mail.manage'), 403);
        $f = MailFestival::query()->findOrFail($festival);
        $data = $request->validate(['on_date' => 'nullable|date', 'active' => 'nullable|boolean']);
        $f->fill(['on_date' => $data['on_date'] ?? null, 'active' => $request->boolean('active')])->save();

        return back()->with('mail_flash', __('mail::message.saved'));
    }

    /** ⓘ বদলাতে পারেন — নিজেরটা নিজে, কোম্পানিরটা `mail.manage` */
    private function mine(Request $request, string $template): MailTemplate
    {
        $t = MailTemplate::query()->seenBy($request->user())->where('public_id', $template)->firstOrFail();
        abort_unless($t->user_id === $request->user()->id || ($t->user_id === null && $request->user()->can('mail.manage')), 403);

        return $t;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'category' => ['required', Rule::in(MailTemplate::CATEGORIES)],
            'locale' => 'required|in:bn,en,both',
            'format' => 'required|in:designed,plain',
            'colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string|max:20000',
            'button' => 'nullable|string|max:60',
            'attach_paper' => 'nullable|boolean',
            'auto_on' => 'nullable|boolean',
            'auto_day' => 'nullable|in:sat,sun,mon,tue,wed,thu,fri',
            'auto_at' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        return [
            'name' => $data['name'], 'category' => $data['category'], 'locale' => $data['locale'], 'format' => $data['format'],
            'colour' => $data['colour'] ?? null, 'subject' => (string) ($data['subject'] ?? ''), 'body' => $data['body'],
            'button' => $data['button'] ?? null, 'attach_paper' => $request->boolean('attach_paper'),
            'auto' => $request->has('auto_on') || $request->has('auto_day')
                ? ['on' => $request->boolean('auto_on'), 'rule' => $data['category'] === 'festival' ? 'festival' : 'due_30', 'day' => $data['auto_day'] ?? 'mon', 'at' => $data['auto_at'] ?? '10:00']
                : null,
        ];
    }
}
