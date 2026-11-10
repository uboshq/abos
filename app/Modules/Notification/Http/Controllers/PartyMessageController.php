<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\PaperTrail;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\PartyMessage;
use App\Models\PartyMessageTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ⭐ পার্টিকে বার্তা — লেখাগুলো (বাংলা/English, কোন কাগজের জন্য) আর পাঠানোর খাতা (মালিকের "ok", ১০ নভেম্বর ২০২৬)।
 *
 * ⓘ লেখা বদলাতে কোড লাগে না — মালিক বা যাঁকে চাবি দেওয়া হয়েছে, তিনি এখান থেকেই বদলান।
 * ⛔ লেখা মুছে ফেলা যায় না, বন্ধ করা যায় — খাতার পুরনো সারিগুলো কোন লেখায় গিয়েছিল, তা যেন হারিয়ে না যায়।
 */
class PartyMessageController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function templates(Request $request): View
    {
        PartyMessageTemplate::ensureStarters((int) CompanyContext::id());

        return view('notification::party.templates', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => PartyMessageTemplate::query()->orderBy('channel')->orderByRaw('document_type IS NULL DESC')
                ->orderBy('document_type')->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new PartyMessageTemplate([
            'channel' => PartyMessageTemplate::WHATSAPP, 'is_active' => true, 'sort' => 100,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $template = PartyMessageTemplate::query()->create($this->validated($request) + ['created_by' => $request->user()?->id]);

        return redirect()->route('notification.party_templates.edit', $template)->with('saved', __('notification::party.saved'));
    }

    public function edit(Request $request, PartyMessageTemplate $template): View
    {
        return $this->form($request, $template);
    }

    public function update(Request $request, PartyMessageTemplate $template): RedirectResponse
    {
        $template->fill($this->validated($request))->save();

        return redirect()->route('notification.party_templates.edit', $template)->with('saved', __('notification::party.saved'));
    }

    /**
     * ⭐ খাতা — কে, কবে, কোন কাগজ, কোন পার্টি, কোন নম্বরে, কোন ভাষায়, ঠিক কোন লেখা।
     * ⓘ শাখার দেয়াল: যিনি সব শাখা দেখেন না, তিনি নিজের নাগালের শাখার সারিই দেখেন।
     */
    public function messages(Request $request): View
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'document_type' => ['nullable', Rule::in(array_keys(PaperTrail::DOCUMENT_ROUTES))],
            'sent_by' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // ⓘ শাখার দেয়াল মডেলেই ([[ScopedToUserBranch]])
        $rows = PartyMessage::query()
            ->with(['sender:id,name', 'template:id,name'])
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d)->addDay()->startOfDay()))
            ->when($f['document_type'] ?? null, fn ($q, $t) => $q->where('document_type', $t))
            ->when($f['sent_by'] ?? null, fn ($q, $u) => $q->where('sent_by', (int) $u))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('document_no', 'like', '%'.$s.'%')
                ->orWhere('party_name', 'like', '%'.$s.'%')->orWhere('phone', 'like', '%'.$s.'%')))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('notification::party.messages', [
            'menu' => $this->menu->forUser($user),
            'rows' => $rows,
            'filters' => $f,
            'senders' => User::query()->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
                ->whereIn('id', PartyMessage::query()->select('sent_by')->distinct())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function form(Request $request, PartyMessageTemplate $template): View
    {
        return view('notification::party.form', [
            'menu' => $this->menu->forUser($request->user()),
            'template' => $template,
            'types' => array_keys(PaperTrail::DOCUMENT_ROUTES),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // ⓘ এখন কেবল WhatsApp; SMS আসবে নিজের সিমের ব্যবস্থার সাথে
            'channel' => ['required', Rule::in([PartyMessageTemplate::WHATSAPP])],
            'document_type' => ['nullable', Rule::in(array_keys(PaperTrail::DOCUMENT_ROUTES))],
            'body_bn' => ['required', 'string', 'max:1000'],
            'body_en' => ['required', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $errors = [];

        foreach (['body_bn', 'body_en'] as $part) {
            if ($unknown = PartyMessageTemplate::unknownIn($data[$part])) {
                $errors[$part] = __('notification::party.unknown', ['names' => implode(', ', array_map(fn ($n) => '{'.$n.'}', $unknown))]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $data['name'],
            'channel' => $data['channel'],
            'document_type' => $data['document_type'] ?? null,
            'body_bn' => str_replace("\r\n", "\n", $data['body_bn']),
            'body_en' => str_replace("\r\n", "\n", $data['body_en']),
            'sort' => (int) ($data['sort'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
