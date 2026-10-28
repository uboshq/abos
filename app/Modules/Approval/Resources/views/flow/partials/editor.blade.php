{{--
    খোলা নিয়ম — ধাপগুলো ছবির মতো কার্ডে: তৈরি → ধাপ ১ → ধাপ ২ …, প্রতিটায় "কে" আর "কত টাকা থেকে"; বাকি সব "আরও ▾"-এর ভেতরে।

    ⭐ অনুমোদন পর্দা ভাগ ৩, দফা ১৭ (পরিকল্পনার পাতা ৪)। ⛔ আগে প্রতিটা ধাপ ১১ ঘরের দুই সারি — কোন ঘড়ি কোন ধাপের, পড়া কঠিন।
    ⓘ ঘরের নাম আর মান আগের মতোই (`steps[i][…]`, `conditions[i][…]`), তাই সেবা আর তার পাহারা অবিকল একই; নতুন শুধু "কত টাকা থেকে"।

    ⭐ "পরখ করুন": একটা অঙ্ক দিলে দেখায় কাগজটা কার কার কাছে যাবে — ইঞ্জিনের একই নিয়ম ([[ApprovalFlow::stepsAt()]]): ধাপের
    "কত টাকা থেকে" খালি বা অঙ্ক তার সমান-বেশি হলে ধাপটা থাকে; নিয়মের নিজের সীমার নিচে হলে নিয়মই ধরে না।

    ⓘ খালি কার্ড তিনটা (আগের তিন খালি সারির মতো), "+ ধাপ" চাপলে একটা একটা করে খোলে; না-ছোঁয়া কার্ড সার্ভারে ছাঁকা হয়।
--}}
@php
    $existing = old('steps', $flow->steps?->map(fn ($s) => [
        'level' => $s->level,
        'step_name' => $s->step_name,
        'approver' => $s->approver_type.'|'.$s->approver_id,
        'requires_all' => $s->requires_all,
        'min_approvals' => $s->min_approvals,
        'sla_hours' => $s->sla_hours,
        'warn_hours' => $s->warn_hours,
        'escalate_hours' => $s->escalate_hours,
        'escalate_to' => $s->escalate_to_type === null ? '' : $s->escalate_to_type.'|'.$s->escalate_to_id,
        'from_amount' => $s->from_amount === null ? '' : rtrim(rtrim((string) $s->from_amount, '0'), '.'),
    ])->all() ?? []);

    $rows = array_pad(array_values($existing), count($existing) + 3, null);

    $conditions = old('conditions', $flow->conditions?->map(fn ($c) => [
        'field' => $c->field, 'operator' => $c->operator, 'value' => $c->value,
    ])->all() ?? []);
    $conditionRows = array_pad(array_values($conditions), count($conditions) + 3, null);

    $pair = old('module', $flow->module) !== null ? old('module', $flow->module).'|'.old('action', $flow->action) : array_key_first($fields).'';
    $work = $flow->exists ? __($choices[$flow->module]['actions'][$flow->action] ?? $flow->action) : __('approval::action.new_flow');
    $input = 'w-full rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-1.5 text-sm';
@endphp

<section id="rule" data-flow-editor="{{ $flow->id ?? 'new' }}" class="mt-4 scroll-mt-4"
         data-fields="{{ json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) }}"
         data-words="{{ json_encode(['skips' => __('approval::message.try_skips'), 'notCaught' => __('approval::message.try_not_caught')], JSON_UNESCAPED_UNICODE) }}"
         x-data="flowRule({ pair: '{{ $pair }}', shown: {{ max(1, count($existing)) }} })"
         x-on:input="touch()" x-on:change="touch()">

    <form method="POST" id="flow-form"
          action="{{ $flow->exists ? route('approval.flow.update', $flow->id) : route('approval.flow.store') }}">
        @csrf
        @if ($flow->exists)
            @method('PUT')
        @endif

        @if ($errors->any())
            <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            {{-- ⓘ মাথা: কোন নিয়ম, আর ডানে শেষ বদল · কে · কবে · কেন (পরিকল্পনার ছবির ডান কোণ) --}}
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-base font-semibold">{{ __('approval::field.rule_of', ['name' => $work]) }}</h2>
                @if ($lastChange !== null)
                    <p class="text-2xs text-(--color-ink-muted)">
                        {{ __('approval::message.last_change', ['who' => $lastChange['who'] ?? '—', 'at' => \App\Core\Support\DateFormat::format($lastChange['at'])]) }}@if (filled($flow->remarks)) · {{ __('approval::field.why') }}: “{{ $flow->remarks }}”@endif
                    </p>
                @endif
            </div>

            {{-- ⓘ নিয়মের নিজের ঘর — কাজ, কত টাকা থেকে, কেন, চালু। ⛔ বাদ নয়: এগুলো ছাড়া নতুন নিয়ম বানানোই যেত না। --}}
            <div class="mt-3 grid gap-3 @container sm:grid-cols-2 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)_minmax(0,1.6fr)_auto]">
                <label class="grid content-start gap-0.5">
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.module') }} · {{ __('approval::field.action') }}</span>
                    {{-- ⓘ মডিউল ও কাজ এক ঘরে — আলাদা হলে ভুল জোড়া বাছা যেত; ভাঙা মান লুকানো দুই ঘরে --}}
                    <select id="module-action" required x-model="pair" class="{{ $input }}">
                        @foreach ($choices as $code => $entry)
                            <optgroup label="{{ $entry['label'] }}">
                                @foreach ($entry['actions'] as $action => $key)
                                    <option value="{{ $code }}|{{ $action }}" @selected($pair === $code.'|'.$action)>{{ __($key) }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <input type="hidden" name="module" :value="module" value="{{ explode('|', $pair)[0] }}">
                    <input type="hidden" name="action" :value="action" value="{{ explode('|', $pair)[1] ?? '' }}">
                    {{-- ⛔ নথি-ধরনটা ফিরে যায়, নাহলে খুলে সংরক্ষণ করলেই নিয়মটা সব ধরনের কাগজ ধরত --}}
                    <input type="hidden" name="document_type" value="{{ old('document_type', $flow->document_type) }}">
                </label>

                <label class="grid content-start gap-0.5">
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.threshold') }}</span>
                    <input type="number" step="0.01" min="0" name="threshold_amount" x-ref="threshold"
                           value="{{ old('threshold_amount', $flow->threshold_amount === null ? '' : rtrim(rtrim((string) $flow->threshold_amount, '0'), '.')) }}"
                           placeholder="{{ __('approval::action.always') }}" class="{{ $input }} text-end">
                </label>

                {{-- ⓘ কেন বসানো — ছয় মাস পরে কেউ কারণ খুঁজে পেত না (মালিক, ২২ সেপ্টেম্বর ২০২৬) --}}
                <label class="grid content-start gap-0.5">
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.why') }}</span>
                    <input type="text" name="remarks" maxlength="500" value="{{ old('remarks', $flow->remarks) }}"
                           placeholder="{{ __('approval::message.remarks_hint') }}" class="{{ $input }}">
                </label>

                <label class="flex min-h-(--spacing-touch) items-end gap-2 pb-1 text-sm">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $flow->is_active ?? true)) class="size-4">
                    {{ __('approval::field.active') }}
                </label>
            </div>

            {{-- ⭐ ধাপের কার্ড — তৈরি → ধাপ ১ → … ; ⓘ সারি মুড়ে যায়, তাই ১৩৬৬-এ মেনু খোলা রেখেও কিছু কাটে না --}}
            <ol class="mt-4 flex flex-wrap items-start gap-2" aria-label="{{ __('approval::field.steps') }}">
                <li class="flex min-w-36 flex-1 basis-36 flex-col gap-1 rounded-(--radius-card) border border-(--color-border) p-3">
                    <span class="flex items-center gap-2 text-sm">
                        <span class="grid size-6 place-items-center rounded-full bg-(--color-badge-success-bg) text-2xs font-semibold text-(--color-badge-success-ink)">0</span>
                        {{ __('approval::field.maker') }}
                    </span>
                    <span class="font-semibold">{{ __('approval::message.maker_who') }}</span>
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::message.maker_cannot') }}</span>
                </li>

                @foreach ($rows as $index => $step)
                    @php
                        $chosen = (string) ($step['approver'] ?? '');
                        $target = (string) ($step['escalate_to'] ?? '');
                        $level = $step['level'] ?? $index + 1;
                    @endphp
                    <li aria-hidden="true" class="self-center text-(--color-ink-muted)" x-show="{{ $index }} < shown">›</li>
                    {{-- ⓘ কার্ড চওড়ায় ভাগ করে নেয় (১০rem থেকে) — ১৩৬৬-এ মেনু খোলা রেখে তৈরি + তিন ধাপ + "ধাপ যোগ" এক সারিতে --}}
                    <li data-step-card class="flex min-w-40 flex-1 basis-40 flex-col gap-2 rounded-(--radius-card) border border-(--color-border) p-3"
                        x-show="{{ $index }} < shown" x-data="{ level: @js((int) $level) }">
                        {{-- ⓘ "① ধাপ [1]" এক লাইনে — নম্বর বদলালে একই স্তরে দুইজন (N-of-M) বসানো যায় --}}
                        <label class="flex items-center gap-2">
                            <span class="grid size-6 flex-none place-items-center rounded-full bg-(--color-badge-info-bg) text-2xs font-semibold text-(--color-badge-info-ink)" x-text="level">{{ $level }}</span>
                            <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.level') }}</span>
                            <input type="number" name="steps[{{ $index }}][level]" min="1" max="9" value="{{ $level }}" x-model.number="level"
                                   data-step-level class="w-14 rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-app) px-2 py-0.5 text-sm">
                        </label>

                        <label class="grid content-start gap-0.5">
                            <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.step_name') }}</span>
                            <input type="text" name="steps[{{ $index }}][step_name]" maxlength="64" value="{{ $step['step_name'] ?? '' }}"
                                   placeholder="{{ __('approval::field.step_name') }}" data-step-name class="{{ $input }}">
                        </label>

                        {{-- ⓘ ধরন ও ব্যক্তি এক ঘরে ("role|3") — আলাদা হলে অসম্ভব জোড়া সংরক্ষিত হত --}}
                        <label class="grid content-start gap-0.5">
                            <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.approver') }}</span>
                            <select name="steps[{{ $index }}][approver]" data-step-who class="{{ $input }}">
                                <option value="">—</option>
                                <optgroup label="{{ __('approval::action.by_role') }}">
                                    @foreach ($roles as $role)
                                        <option value="role|{{ $role->id }}" @selected($chosen === 'role|'.$role->id)>{{ $role->name }}</option>
                                    @endforeach
                                </optgroup>
                                <optgroup label="{{ __('approval::action.by_user') }}">
                                    @foreach ($users as $user)
                                        <option value="user|{{ $user->id }}" @selected($chosen === 'user|'.$user->id)>{{ $user->name }}</option>
                                    @endforeach
                                </optgroup>
                            </select>
                        </label>

                        {{-- ⭐ "কত টাকা থেকে" — ধাপ ১ সবসময় যেকোনো অঙ্ক (সেবার নিয়ম) --}}
                        <label class="grid content-start gap-0.5">
                            <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.from_amount') }}</span>
                            <input type="number" step="0.01" min="0" name="steps[{{ $index }}][from_amount]" value="{{ $step['from_amount'] ?? '' }}"
                                   data-step-from x-bind:disabled="level === 1" placeholder="{{ __('approval::field.any_amount') }}"
                                   class="{{ $input }} text-end disabled:opacity-60">
                        </label>

                        {{-- ⭐ "আরও ▾" — সময়সীমা, মনে করানো, দেরি হলে কার কাছে, কয়জনের অনুমোদন; সারাংশে সময়সীমা --}}
                        <details class="text-sm" @if ($errors->has('steps.'.$index.'.*')) open @endif>
                            <summary class="cursor-pointer text-(--color-link)">
                                {{ __('approval::action.more') }}
                                @if (! empty($step['sla_hours']))
                                    <span class="text-2xs text-(--color-ink-muted)">({{ __('approval::message.more_summary', ['hours' => $step['sla_hours']]) }})</span>
                                @endif
                            </summary>
                            <div class="mt-2 grid gap-2">
                                <label class="grid content-start gap-0.5">
                                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.min_approvals') }}</span>
                                    <input type="number" name="steps[{{ $index }}][min_approvals]" min="1" max="9" value="{{ $step['min_approvals'] ?? '' }}"
                                           title="{{ __('approval::message.min_approvals_hint') }}" class="{{ $input }}">
                                </label>
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="steps[{{ $index }}][requires_all]" value="1" @checked($step['requires_all'] ?? false) class="size-4">
                                    {{ __('approval::field.requires_all') }}
                                </label>
                                <label class="grid content-start gap-0.5">
                                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.sla_hours') }}</span>
                                    <input type="number" name="steps[{{ $index }}][sla_hours]" min="1" max="8760" value="{{ $step['sla_hours'] ?? '' }}"
                                           title="{{ __('approval::message.sla_hint') }}" class="{{ $input }}">
                                </label>
                                <label class="grid content-start gap-0.5">
                                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.warn_hours') }}</span>
                                    <input type="number" name="steps[{{ $index }}][warn_hours]" min="1" max="8760" value="{{ $step['warn_hours'] ?? '' }}" class="{{ $input }}">
                                </label>
                                <label class="grid content-start gap-0.5">
                                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.escalate_hours') }}</span>
                                    <input type="number" name="steps[{{ $index }}][escalate_hours]" min="1" max="8760" value="{{ $step['escalate_hours'] ?? '' }}" class="{{ $input }}">
                                    <span class="text-2xs text-(--color-ink-muted)" data-escalate-hint>{{ __('approval::field.escalate_hours_hint') }}</span>
                                </label>
                                <label class="grid content-start gap-0.5">
                                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.escalate_to') }}</span>
                                    <select name="steps[{{ $index }}][escalate_to]" title="{{ __('approval::message.escalate_hint') }}" class="{{ $input }}">
                                        <option value="">—</option>
                                        <optgroup label="{{ __('approval::action.by_role') }}">
                                            @foreach ($roles as $role)
                                                <option value="role|{{ $role->id }}" @selected($target === 'role|'.$role->id)>{{ $role->name }}</option>
                                            @endforeach
                                        </optgroup>
                                        <optgroup label="{{ __('approval::action.by_user') }}">
                                            @foreach ($users as $user)
                                                <option value="user|{{ $user->id }}" @selected($target === 'user|'.$user->id)>{{ $user->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    </select>
                                </label>
                            </div>
                        </details>
                    </li>
                @endforeach

                <li x-show="shown < {{ count($rows) }}">
                    <button type="button" x-on:click="more()"
                            class="grid h-full min-h-24 w-24 place-items-center rounded-(--radius-card) border-2 border-dashed border-(--color-border) text-sm text-(--color-ink-muted) hover:bg-(--color-surface-hover)">
                        + {{ __('approval::action.add_step') }}
                    </button>
                </li>
            </ol>

            {{-- ⭐ পরখ করুন — অঙ্ক দিলে কার কার কাছে যাবে; বাদ পড়া ধাপ ধূসর আর কারণ লেখা --}}
            <div class="mt-4 flex flex-wrap items-end gap-3" data-flow-try>
                <label class="grid content-start gap-0.5">
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.try_amount') }}</span>
                    <input type="number" step="0.01" min="0" x-model="tryAmount" class="w-48 {{ $input }} text-end">
                </label>
                <div class="grid content-start gap-0.5" aria-live="polite">
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::field.goes_to') }}</span>
                    <p class="flex min-h-8 flex-wrap items-center gap-1 text-sm">
                        <template x-for="(step, i) in chain.steps" :key="step.level">
                            <span class="inline-flex items-center gap-1">
                                <span x-show="i > 0" class="text-(--color-ink-muted)">›</span>
                                <span class="rounded-full px-2 py-0.5 text-2xs"
                                      :class="chipClass(step)"
                                      x-text="step.who"></span>
                            </span>
                        </template>
                        <span class="text-2xs text-(--color-ink-muted)" x-show="chain.note !== ''" x-text="chain.note"></span>
                    </p>
                </div>
            </div>

            {{-- ⭐ শর্ত (ঐচ্ছিক) — ঘর তালিকা থেকে বাছাই; শাখা হলে শাখার তালিকা। ⛔ হাতে লেখা ঘর আর নেই: ভুল নাম মানে নিয়ম কখনো
                 ধরত না, নীরবে। ⓘ তালিকা এই কাজের সাম্প্রতিক কাগজ থেকে ([[ApprovalFlowController::conditionFields()]]). --}}
            <div class="mt-4 rounded-(--radius-field) border-s-4 border-(--color-link) bg-(--color-surface-hover) p-3">
                <p class="text-sm font-semibold">{{ __('approval::field.conditions_optional') }}
                    <span class="font-normal text-2xs text-(--color-ink-muted)">— {{ __('approval::message.conditions_pick') }}</span>
                </p>
                <p class="mt-1 text-2xs text-(--color-ink-muted)" x-show="! hasFields">
                    {{ __('approval::message.no_fields_yet') }}
                </p>
                <div class="mt-2 grid gap-2" x-show="hasFields">
                    @foreach ($conditionRows as $index => $condition)
                        <div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_6rem_minmax(0,1fr)]" x-data="{ field: @js((string) ($condition['field'] ?? '')) }">
                            <select name="conditions[{{ $index }}][field]" x-model="field" aria-label="{{ __('approval::field.condition_field') }}" class="{{ $input }}">
                                <option value="">{{ __('approval::field.condition_field') }}</option>
                                <template x-for="(label, key) in currentFields" :key="key">
                                    <option :value="key" x-text="label" :selected="key === field"></option>
                                </template>
                            </select>
                            <select name="conditions[{{ $index }}][operator]" aria-label="{{ __('approval::field.condition_operator') }}" class="{{ $input }}">
                                @foreach (\App\Models\ApprovalCondition::OPERATORS as $operator)
                                    <option value="{{ $operator }}" @selected(($condition['operator'] ?? '=') === $operator)>{{ $operator }}</option>
                                @endforeach
                            </select>
                            {{-- ⓘ শাখা হলে নাম থেকে বাছাই; অন্য ঘরে মান লেখা (অঙ্ক, শতাংশ) --}}
                            <template x-if="field === 'branch_id'">
                                <select name="conditions[{{ $index }}][value]" aria-label="{{ __('approval::field.condition_value') }}" class="{{ $input }}">
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected((string) ($condition['value'] ?? '') === (string) $branch->id)>{{ $branch->name() }}</option>
                                    @endforeach
                                </select>
                            </template>
                            <template x-if="field !== 'branch_id'">
                                <input type="text" name="conditions[{{ $index }}][value]" maxlength="255" value="{{ $condition['value'] ?? '' }}"
                                       placeholder="{{ __('approval::field.condition_value') }}" aria-label="{{ __('approval::field.condition_value') }}" class="{{ $input }}">
                            </template>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ⓘ নিচের স্থির পট্টি: বাঁয়ে নিরীক্ষার কথা আর মোছা, ডানে বাতিল · সংরক্ষণ (পরিকল্পনার ছবির নিচের সারি) --}}
        <x-ui.form-actions :cancel="route('approval.flow.index', request()->except(['open']))">
            <span class="text-2xs text-(--color-ink-muted)">{{ __('approval::message.audit_note') }}</span>
            @if ($flow->exists && [$flow->module, $flow->action] !== $payroll)
                <x-ui.button type="submit" tone="ghost" form="flow-delete">{{ __('approval::action.delete') }}</x-ui.button>
            @endif
        </x-ui.form-actions>
    </form>

    @if ($flow->exists && [$flow->module, $flow->action] !== $payroll)
        {{-- ⓘ মোছা আলাদা ফর্মে (ফর্মের ভেতরে ফর্ম চলে না) — বোতামটা উপরের পট্টিতে, `form=` দিয়ে বাঁধা --}}
        <form method="POST" id="flow-delete" action="{{ route('approval.flow.destroy', $flow->id) }}"
              data-confirm="{{ __('approval::message.confirm_flow_delete') }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @endif
</section>
