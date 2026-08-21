{{--
    একটা ছক — কোন কাজে, কত টাকার উপরে, আর কে কে।

    স্তরগুলো তিনটা খালি সারি হিসেবে দেখানো হয়: বেশিরভাগ ছকে এক বা দুই
    স্তর, আর যাঁর তিনের বেশি লাগে তিনি সংরক্ষণ করে আবার খুলে যোগ করতে
    পারেন। খালি সারি ছাঁকা হয় সার্ভারে, তাই না-ছোঁয়া সারি কোনো
    ভ্যালিডেশন আটকায় না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.flows') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('approval::menu.flows')" />
    </x-slot:header>

    <form method="POST"
          action="{{ $flow->exists ? route('approval.flow.update', $flow->id) : route('approval.flow.store') }}"
          class="space-y-4">
        @csrf
        @if ($flow->exists)
            @method('PUT')
        @endif

        @if ($errors->any())
            <div role="alert"
                 class="rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                        text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div data-boxed class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4 sm:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('approval::field.module') }} · {{ __('approval::field.action') }}
                </span>

                {{-- মডিউল ও কাজ একটাই ঘরে, কারণ কাজটা মডিউলের ভেতরের —
                     আলাদা দুইটা ড্রপডাউন হলে ভুল জোড়া বাছা যেত --}}
                <select name="module_action" id="module-action" required
                        class="w-full rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 py-1.5 text-sm">
                    @foreach ($choices as $code => $entry)
                        <optgroup label="{{ $entry['label'] }}">
                            @foreach ($entry['actions'] as $action => $key)
                                <option value="{{ $code }}|{{ $action }}"
                                        @selected($flow->module === $code && $flow->action === $action)>
                                    {{ __($key) }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>

                {{-- সার্ভারে দুইটা আলাদা ঘর দরকার, তাই ভাঙা মানটা লুকানো
                     ঘরে বসে; JavaScript ছাড়া চলে না বলে দুইটাই পাঠানো হয় --}}
                <input type="hidden" name="module" id="flow-module" value="{{ old('module', $flow->module) }}">
                <input type="hidden" name="action" id="flow-action" value="{{ old('action', $flow->action) }}">
            </label>

            <label class="block">
                <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('approval::field.threshold') }}
                </span>
                <input type="number" step="0.01" min="0" name="threshold_amount"
                       value="{{ old('threshold_amount', $flow->threshold_amount) }}"
                       placeholder="{{ __('approval::action.always') }}"
                       class="w-full rounded-(--radius-field) border border-(--color-border)
                              bg-(--color-surface-app) px-2 py-1.5 text-sm">
                <span class="mt-1 block text-2xs text-(--color-ink-muted)">
                    {{ __('approval::message.threshold_hint') }}
                </span>
            </label>

            <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $flow->is_active ?? true)) class="size-4">
                {{ __('approval::field.active') }}
            </label>
        </div>

        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 text-sm font-semibold">{{ __('approval::field.steps') }}</h2>

            @php
                $existing = old('steps', $flow->steps?->map(fn ($s) => [
                    'level' => $s->level,
                    'approver_type' => $s->approver_type,
                    'approver_id' => $s->approver_id,
                    'requires_all' => $s->requires_all,
                ])->all() ?? []);

                // যা আছে তার নিচে তিনটা খালি সারি
                $rows = array_pad(array_values($existing), count($existing) + 3, null);
            @endphp

            <div class="space-y-2">
                @foreach ($rows as $index => $step)
                    @php
                        $chosen = ($step['approver_type'] ?? '').'|'.($step['approver_id'] ?? '');
                    @endphp

                    <div class="grid gap-2 sm:grid-cols-[5rem_1fr_auto]">
                        <input type="number" name="steps[{{ $index }}][level]" min="1" max="9"
                               value="{{ $step['level'] ?? $index + 1 }}"
                               aria-label="{{ __('approval::field.level') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">

                        {{-- ধরন ও ব্যক্তি একটাই ঘরে।

                             আলাদা রাখলে "ধরন: রোল, অনুমোদনকারী: রফিক" এমন
                             অসম্ভব জোড়া বাছা যেত, আর সেটা সংরক্ষিতও হয়ে
                             যেত — ছকটা তখন কাউকেই মেলাত না। --}}
                        <select name="steps[{{ $index }}][approver]"
                                aria-label="{{ __('approval::field.approver') }}"
                                class="rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 py-1.5 text-sm">
                            <option value="">—</option>

                            <optgroup label="{{ __('approval::action.by_role') }}">
                                @foreach ($roles as $role)
                                    <option value="role|{{ $role->id }}" @selected($chosen === 'role|'.$role->id)>
                                        {{ $role->name }}
                                    </option>
                                @endforeach
                            </optgroup>

                            <optgroup label="{{ __('approval::action.by_user') }}">
                                @foreach ($users as $user)
                                    <option value="user|{{ $user->id }}" @selected($chosen === 'user|'.$user->id)>
                                        {{ $user->name }}
                                    </option>
                                @endforeach
                            </optgroup>
                        </select>

                        <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                            <input type="checkbox" name="steps[{{ $index }}][requires_all]" value="1"
                                   @checked($step['requires_all'] ?? false) class="size-4">
                            <span class="whitespace-nowrap">{{ __('approval::field.requires_all') }}</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            <x-ui.button tone="secondary" :href="route('approval.flow.index')">{{ __('core.action.cancel') }}</x-ui.button>
        </div>
    </form>

    {{-- মডিউল ও কাজ ভেঙে দুইটা লুকানো ঘরে বসানো।

         JavaScript বন্ধ থাকলেও ফর্মটা কাজ করে: ঘর দুইটা সার্ভার-রেন্ডার
         করা মান নিয়েই যায়, আর সম্পাদনার সময় ওগুলো আগে থেকেই ভরা থাকে। --}}
    <script>
        (() => {
            const picker = document.getElementById('module-action');
            const moduleField = document.getElementById('flow-module');
            const actionField = document.getElementById('flow-action');

            const split = () => {
                const [module, action] = (picker.value || '').split('|');
                moduleField.value = module ?? '';
                actionField.value = action ?? '';
            };

            picker.addEventListener('change', split);
            split();
        })();
    </script>
</x-layouts.app>
