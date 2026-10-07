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

                {{-- ⛔ নথি-ধরনটা ফিরে যায়, নাহলে সম্পাদনা করলেই মুছে যেত।

                     ⚠️ ঘরটা পর্দায় নেই (আজ ওটা হাতে বসানো হয় না), আর
                     সেবা অনুপস্থিত চাবিকে *"সব ধরনে"* পড়ত। ⓘ ফল: একটা
                     নথি-নির্দিষ্ট ছক কেবল খুলে সংরক্ষণ করলেই **সব ধরনের
                     কাগজ ধরতে শুরু করত** — কোনো ত্রুটি ছাড়া। --}}
                <input type="hidden" name="document_type"
                       value="{{ old('document_type', $flow->document_type) }}">
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

            {{-- কেন নিয়মটা বসানো — মালিকের চাওয়া, ২২ সেপ্টেম্বর ২০২৬।

                 ছয় মাস পরে "ক্রয়ের পরিশোধে দুইজনের সই" দেখে কেউ কারণ
                 খুঁজে পেত না। তখন হয় নিয়মটা ভয়ে রয়ে যায়, নয় কেউ কারণ
                 না জেনেই তুলে দেয় — দুইটাই খারাপ। --}}
            <label class="block sm:col-span-2">
                <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('approval::field.why') }}
                </span>
                <textarea name="remarks" rows="2" maxlength="500"
                          placeholder="{{ __('approval::message.remarks_hint') }}"
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-app) px-2 py-1.5 text-sm">{{ old('remarks', $flow->remarks) }}</textarea>
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
                    'step_name' => $s->step_name,
                    'approver_type' => $s->approver_type,
                    'approver_id' => $s->approver_id,
                    'requires_all' => $s->requires_all,
                    'min_approvals' => $s->min_approvals,
                    'sla_hours' => $s->sla_hours,
                    'warn_hours' => $s->warn_hours,
                    'escalate_hours' => $s->escalate_hours,
                    'escalate_to_type' => $s->escalate_to_type,
                    'escalate_to_id' => $s->escalate_to_id,
                ])->all() ?? []);

                // যা আছে তার নিচে তিনটা খালি সারি
                $rows = array_pad(array_values($existing), count($existing) + 3, null);
            @endphp

            <div class="space-y-2">
                @foreach ($rows as $index => $step)
                    @php
                        $chosen = ($step['approver_type'] ?? '').'|'.($step['approver_id'] ?? '');
                    @endphp

                    <div class="grid gap-2 sm:grid-cols-[5rem_10rem_1fr_auto]">
                        <input type="number" name="steps[{{ $index }}][level]" min="1" max="9"
                               value="{{ $step['level'] ?? $index + 1 }}"
                               aria-label="{{ __('approval::field.level') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">

                        {{-- ধাপের নাম — "সুপারভাইজার", "সিইও"।

                             নম্বর বলে ক্রমটা, নাম বলে কাজটা। অনুমোদনের
                             অনুরোধ খুলে "ধাপ ২" দেখে কেউ বলতে পারত না
                             ওটা কে, অথচ যিনি সই করবেন তাঁর কাছে ঐ
                             প্রশ্নটাই প্রথম।

                             ঐচ্ছিক, কারণ পুরনো ছকগুলোর নাম নেই — আর
                             বাধ্য করলে মানুষ "ধাপ ২" লিখে ফর্ম পার
                             করতেন, যা নম্বরটার চেয়ে বেশি কিছু বলত না। --}}
                        <input type="text" name="steps[{{ $index }}][step_name]" maxlength="64"
                               value="{{ $step['step_name'] ?? '' }}"
                               placeholder="{{ __('approval::field.step_name') }}"
                               aria-label="{{ __('approval::field.step_name') }}"
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

                    {{-- ⭐ ঘড়ি, গন্তব্য আর কয়জনের সই — ২৪ সেপ্টেম্বর ২০২৬।

                         ⓘ একই ধাপের দ্বিতীয় সারি, প্রথমটার নিচে ইন্ডেন্ট করা।
                         ⛔ আলাদা কার্ডে নিলে "কোন ঘড়িটা কোন ধাপের" প্রশ্নটা
                         আবার ফিরত, আর একটা ভুল মিলে তিন ঘণ্টার সীমা চলে যেত
                         অন্য ধাপে — দুইটাই বৈধ, তাই কোথাও কিছু লাল হত না।

                         ⚠️ ঘরগুলো খালি রাখলে কিছুই বদলায় না: `null` মানে
                         "এই ধাপে ঘড়ি নেই", আর পুরনো ছকগুলো অবিকল আগের মতো চলে। --}}
                    <div class="mb-1 grid gap-2 border-l-2 border-(--color-border) pl-3
                                sm:ml-2 sm:grid-cols-[6rem_6rem_6rem_6rem_1fr]">
                        <input type="number" name="steps[{{ $index }}][min_approvals]" min="1" max="9"
                               value="{{ $step['min_approvals'] ?? '' }}"
                               placeholder="{{ __('approval::field.min_approvals') }}"
                               title="{{ __('approval::message.min_approvals_hint') }}"
                               aria-label="{{ __('approval::field.min_approvals') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">

                        <input type="number" name="steps[{{ $index }}][sla_hours]" min="1" max="8760"
                               value="{{ $step['sla_hours'] ?? '' }}"
                               placeholder="{{ __('approval::field.sla_hours') }}"
                               title="{{ __('approval::message.sla_hint') }}"
                               aria-label="{{ __('approval::field.sla_hours') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">

                        <input type="number" name="steps[{{ $index }}][warn_hours]" min="1" max="8760"
                               value="{{ $step['warn_hours'] ?? '' }}"
                               placeholder="{{ __('approval::field.warn_hours') }}"
                               aria-label="{{ __('approval::field.warn_hours') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">

                        <input type="number" name="steps[{{ $index }}][escalate_hours]" min="1" max="8760"
                               value="{{ $step['escalate_hours'] ?? '' }}"
                               placeholder="{{ __('approval::field.escalate_hours') }}"
                               aria-label="{{ __('approval::field.escalate_hours') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">

                        {{-- ⓘ গন্তব্যও একটাই ঘরে, সইকারীর মতোই — কারণটা
                             [[ApprovalFlowRequest::prepareForValidation()]]-এ। --}}
                        @php
                            $target = ($step['escalate_to_type'] ?? '').'|'.($step['escalate_to_id'] ?? '');
                        @endphp

                        <select name="steps[{{ $index }}][escalate_to]"
                                title="{{ __('approval::message.escalate_hint') }}"
                                aria-label="{{ __('approval::field.escalate_to') }}"
                                class="rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 py-1.5 text-sm">
                            <option value="">{{ __('approval::field.escalate_to') }}</option>

                            <optgroup label="{{ __('approval::action.by_role') }}">
                                @foreach ($roles as $role)
                                    <option value="role|{{ $role->id }}" @selected($target === 'role|'.$role->id)>
                                        {{ $role->name }}
                                    </option>
                                @endforeach
                            </optgroup>

                            <optgroup label="{{ __('approval::action.by_user') }}">
                                @foreach ($users as $user)
                                    <option value="user|{{ $user->id }}" @selected($target === 'user|'.$user->id)>
                                        {{ $user->name }}
                                    </option>
                                @endforeach
                            </optgroup>
                        </select>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ⭐ টাকার অঙ্ক ছাড়াও শর্ত — ধাপ ৩।

             ⚠️ ঘরের নামটা হাতে লিখতে হয়, কারণ প্রতিটা মডিউল আলাদা ঘর
             পাঠায় আর কোনো একক তালিকা নেই। ⛔ ভুল নাম লিখলে ছকটা
             **কখনো ধরে না**, আর সেটা সম্পূর্ণ নীরব — তাই
             [[ApprovalExceptions]] ঐ ফাঁকটা আলাদা করে খুঁজে দেখায়। --}}
        <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
            <h2 class="mb-1 text-sm font-semibold">{{ __('approval::field.conditions') }}</h2>

            <p class="mb-3 text-2xs text-(--color-ink-muted)">
                {{ __('approval::message.conditions_hint') }}
            </p>

            @php
                $conditions = old('conditions', $flow->conditions?->map(fn ($c) => [
                    'field' => $c->field,
                    'operator' => $c->operator,
                    'value' => $c->value,
                ])->all() ?? []);

                // যা আছে তার নিচে তিনটা খালি সারি — ধাপগুলোর মতোই
                $conditionRows = array_pad(array_values($conditions), count($conditions) + 3, null);
            @endphp

            <div class="space-y-2">
                @foreach ($conditionRows as $index => $condition)
                    <div class="grid gap-2 sm:grid-cols-[1fr_7rem_1fr]">
                        <input type="text" name="conditions[{{ $index }}][field]" maxlength="64"
                               value="{{ $condition['field'] ?? '' }}"
                               placeholder="{{ __('approval::field.condition_field') }}"
                               aria-label="{{ __('approval::field.condition_field') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">

                        {{-- ⓘ তুলনাগুলো মডেল থেকে, এখানে হাতে লেখা নয় —
                             নতুন একটা যোগ হলে পর্দাটা নিজেই জেনে যায়। --}}
                        <select name="conditions[{{ $index }}][operator]"
                                aria-label="{{ __('approval::field.condition_operator') }}"
                                class="rounded-(--radius-field) border border-(--color-border)
                                       bg-(--color-surface-app) px-2 py-1.5 text-sm">
                            @foreach (\App\Models\ApprovalCondition::OPERATORS as $operator)
                                <option value="{{ $operator }}"
                                        @selected(($condition['operator'] ?? '') === $operator)>
                                    {{ $operator }}
                                </option>
                            @endforeach
                        </select>

                        <input type="text" name="conditions[{{ $index }}][value]" maxlength="255"
                               value="{{ $condition['value'] ?? '' }}"
                               placeholder="{{ __('approval::field.condition_value') }}"
                               aria-label="{{ __('approval::field.condition_value') }}"
                               class="rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 py-1.5 text-sm">
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
    <script @nonce>
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
