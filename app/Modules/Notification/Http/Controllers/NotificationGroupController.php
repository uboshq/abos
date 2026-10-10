<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Controllers;

use App\Core\Notifications\RecipientResolver;
use App\Core\Services\MenuBuilder;
use App\Core\Services\NotificationAudit;
use App\Http\Controllers\Controller;
use App\Models\NotificationRecipientGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ⭐ প্রাপক-দল — মানুষ, রোল, শাখা, বিভাগ মিলিয়ে নাম দেওয়া দল (মালিকের স্পেক §৪ "Recipient Management"; ধাপ ৩)।
 * ⓘ সদস্য গোনা হয় পাঠানোর সময়; পর্দায় আজকের সদস্যরা দেখানো হয় ([[RecipientResolver]])।
 */
class NotificationGroupController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly MenuBuilder $menu) {}

    public function index(Request $request): View
    {
        $rows = NotificationRecipientGroup::query()->orderBy('name')->paginate(self::PER_PAGE)->withQueryString();

        return view('notification::groups.index', [
            'menu' => $this->menu->forUser($request->user()),
            'rows' => $rows,
        ]);
    }

    public function create(Request $request): View
    {
        return $this->form($request, new NotificationRecipientGroup(['is_active' => true, 'members' => []]));
    }

    public function store(Request $request): RedirectResponse
    {
        $group = NotificationRecipientGroup::query()->create($this->validated($request));
        app(NotificationAudit::class)->record('group_save', $group, 'done', ['group_id' => $group->id]);

        return redirect()->route('notification.groups.edit', $group)->with('saved', __('notification::group.saved'));
    }

    public function edit(Request $request, NotificationRecipientGroup $group): View
    {
        return $this->form($request, $group);
    }

    public function update(Request $request, NotificationRecipientGroup $group): RedirectResponse
    {
        $group->fill($this->validated($request))->save();
        app(NotificationAudit::class)->record('group_save', $group, 'done', ['group_id' => $group->id]);

        return redirect()->route('notification.groups.edit', $group)->with('saved', __('notification::group.saved'));
    }

    private function form(Request $request, NotificationRecipientGroup $group): View
    {
        return view('notification::groups.form', [
            'menu' => $this->menu->forUser($request->user()),
            'group' => $group,
            'choices' => RecipientChoices::all(),
            'people' => $group->exists ? app(RecipientResolver::class)->resolve(['groups' => [$group->id]]) : collect(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $choices = RecipientChoices::ids();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'members' => ['nullable', 'array'],
            'members.users' => ['nullable', 'array'], 'members.users.*' => [Rule::in($choices['users'])],
            'members.roles' => ['nullable', 'array'], 'members.roles.*' => [Rule::in($choices['roles'])],
            'members.branches' => ['nullable', 'array'], 'members.branches.*' => [Rule::in($choices['branches'])],
            'members.departments' => ['nullable', 'array'], 'members.departments.*' => [Rule::in($choices['departments'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $members = [];

        foreach (NotificationRecipientGroup::KINDS as $kind) {
            $members[$kind] = array_values(array_unique(array_map('intval', (array) ($data['members'][$kind] ?? []))));
        }

        return ['name' => $data['name'], 'members' => $members, 'is_active' => (bool) ($data['is_active'] ?? false)];
    }
}
