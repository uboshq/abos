{{--
    একটা অফারের পাতা — নিয়ম, অবস্থা, আর যা করা যায় (স্পেক §৫ View, §৬ ধাপ ২–৪)।

    ⛔ নিয়মের ঘরগুলো কেবল খসড়ায় দেখা যায়। অনুমোদনের পরে ধাপ বদলানো
    গেলে অনুমোদনকারী যা দেখেছিলেন আর যা চলছে, দুইটা আলাদা হত।

    ⚠️ এই পাতায় কোনো `@php` ব্লক নেই — তালিকার পাতায় `@php`-র ভিতরে একটা
    Blade মন্তব্য গোটা পাতাটা ৫০০ করেছিল।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $offer->code }} · {{ $offer->name() }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$offer->code.' · '.$offer->name()" />
    </x-slot:header>

    <x-ui.errors />

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-2xs uppercase text-(--color-ink-muted)">{{ __('promotion::field.status') }}</dt>
                    <dd><x-ui.badge :tone="$offer->status->tone()">{{ $offer->status->label() }}</x-ui.badge></dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase text-(--color-ink-muted)">{{ __('promotion::field.type') }}</dt>
                    <dd>{{ $offer->type->label() }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase text-(--color-ink-muted)">{{ __('promotion::field.combines') }}</dt>
                    <dd>{{ $offer->combines->label() }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase text-(--color-ink-muted)">{{ __('promotion::field.period') }}</dt>
                    <dd>
                        {{ $offer->starts_on->format('d/m/y') }}{{ $offer->starts_at ? ' '.substr($offer->starts_at, 0, 5) : '' }}
                        —
                        {{ $offer->ends_on->format('d/m/y') }}{{ $offer->ends_at ? ' '.substr($offer->ends_at, 0, 5) : '' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase text-(--color-ink-muted)">{{ __('promotion::field.priority') }}</dt>
                    <dd>{{ $offer->priority }}</dd>
                </div>
                <div>
                    <dt class="text-2xs uppercase text-(--color-ink-muted)">{{ __('promotion::field.approved_by') }}</dt>
                    <dd>{{ $offer->approver?->name ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        {{--
            ⭐ অবস্থার বোতাম — প্রতিটা কেবল তখনই দেখা যায় যখন পথটা খোলা
            আর মানুষটার চাবি আছে।

            ⓘ যে বোতাম চাপলে ভুল আসে সেটা না দেখানোই ভালো। ⚠️ কিন্তু সেবা
            তবু নিজে থামায় — বোতাম লুকানো একমাত্র পাহারা নয়।
        --}}
        <div class="flex flex-wrap gap-2">
            @foreach ([
                ['submit', \App\Modules\Promotion\Support\PromotionStatus::SUBMITTED],
                ['activate', \App\Modules\Promotion\Support\PromotionStatus::ACTIVE],
                ['pause', \App\Modules\Promotion\Support\PromotionStatus::PAUSED],
                ['cancel', \App\Modules\Promotion\Support\PromotionStatus::CANCELLED],
            ] as [$action, $target])
                @if ($offer->status->canBecome($target) || ($action === 'activate' && $offer->status === \App\Modules\Promotion\Support\PromotionStatus::DRAFT))
                    @can('promotion.'.$action)
                        <form method="POST" action="{{ route('promotion.'.$action, $offer) }}">
                            @csrf
                            <x-ui.button type="submit" :tone="$action === 'cancel' ? 'danger' : 'secondary'">
                                {{ __('promotion::action.'.$action) }}
                            </x-ui.button>
                        </form>
                    @endcan
                @endif
            @endforeach
        </div>

        {{--
            ⭐ অনুমোদন — স্পেক §১৪। স্তরগুলো কোম্পানির ছক থেকে (Approval Centre);
            ছক না থাকলে একটাই সই, আজকের মতো।

            ⓘ সই আর ফেরতের বোতাম কেবল তাঁর জন্য যিনি এই স্তরে সই দিতে পারেন
            ($canSign); সেবা তবু নিজে থামায় — বোতাম লুকানো একমাত্র পাহারা নয়।
        --}}
        @if ($sentBackBecause)
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
                <span class="font-semibold">{{ __('promotion::approval.last_reason') }}:</span> {{ $sentBackBecause }}
            </div>
        @endif

        @if ($offer->status === \App\Modules\Promotion\Support\PromotionStatus::SUBMITTED)
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('promotion::approval.progress') }}</h2>

                @foreach ($approvalPath as $step)
                    <div class="flex items-center justify-between border-b border-(--color-border) py-2 text-sm">
                        <span>{{ $step['level'] }} · {{ $step['name'] }}</span>
                        <span>
                            @if ($step['decision'])
                                {{ $step['by'] ?? '—' }} · {{ $step['at']?->format('d/m/y H:i') }}
                            @elseif ($step['current'])
                                <x-ui.badge tone="pending">{{ __('promotion::approval.waiting') }}</x-ui.badge>
                            @else
                                —
                            @endif
                        </span>
                    </div>
                @endforeach

                @can('promotion.approve')
                    @if ($canSign)
                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <form method="POST" action="{{ route('promotion.approve', $offer) }}">
                                @csrf
                                <x-ui.button type="submit" tone="primary">{{ __('promotion::approval.sign') }}</x-ui.button>
                            </form>

                            <form method="POST" action="{{ route('promotion.send_back', $offer) }}" class="grid gap-2">
                                @csrf
                                <x-ui.field name="reason" :label="__('promotion::approval.reason')" required />
                                <div>
                                    <x-ui.button type="submit" tone="danger">{{ __('promotion::approval.send_back') }}</x-ui.button>
                                </div>
                            </form>
                        </div>
                    @endif
                @endcan

                @if ((int) $offer->created_by === (int) auth()->id())
                    @can('promotion.submit')
                        <form method="POST" action="{{ route('promotion.withdraw', $offer) }}" class="mt-3">
                            @csrf
                            <x-ui.button type="submit" tone="secondary">{{ __('promotion::approval.withdraw') }}</x-ui.button>
                        </form>
                    @endcan
                @endif
            </section>
        @endif

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('promotion::section.steps') }}</h2>

            @forelse ($offer->conditions as $step)
                <div class="flex items-center justify-between border-b border-(--color-border) py-2 text-sm">
                    <span>
                        {{ $step->kind->label() }}:
                        {{ $step->value_from ?? '0' }} — {{ $step->value_to ?? '∞' }}
                        →
                        @foreach ($step->benefits as $benefit)
                            {{ $benefit->kind->label() }} {{ $benefit->amount }}{{ $benefit->giftProduct ? ' · '.$benefit->giftProduct->name() : '' }}
                        @endforeach
                    </span>

                    @if ($offer->status === \App\Modules\Promotion\Support\PromotionStatus::DRAFT)
                        @can('promotion.update')
                            <form method="POST" action="{{ route('promotion.step.destroy', [$offer, $step]) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" tone="danger">{{ __('core.action.delete') }}</x-ui.button>
                            </form>
                        @endcan
                    @endif
                </div>
            @empty
                {{-- ⚠️ ধাপ ছাড়া অফার কোনো বিলে কিছুই দেয় না — সেটা স্পষ্ট করে বলা --}}
                <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::message.no_steps') }}</p>
            @endforelse

            @if ($offer->status === \App\Modules\Promotion\Support\PromotionStatus::DRAFT)
                @can('promotion.update')
                    <form method="POST" action="{{ route('promotion.step.store', $offer) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <x-ui.select name="condition_kind" :label="__('promotion::field.condition_kind')"
                                     :options="collect($conditionKinds)->mapWithKeys(fn ($k) => [$k->value => $k->label()])"
                                     required />
                        <x-ui.field name="value_from" type="number" step="0.0001" :label="__('promotion::field.value_from')" />
                        <x-ui.field name="value_to" type="number" step="0.0001" :label="__('promotion::field.value_to')" />
                        <x-ui.select name="benefit_kind" :label="__('promotion::field.benefit_kind')"
                                     :options="collect($benefitKinds)->mapWithKeys(fn ($k) => [$k->value => $k->label()])"
                                     required />
                        <x-ui.field name="amount" type="number" step="0.0001" :label="__('promotion::field.amount')" required />
                        <x-ui.field name="gift_product_id" type="number" :label="__('promotion::field.gift_product')" />
                        <x-ui.field name="cap_per_bill" type="number" step="0.0001" :label="__('promotion::field.cap_per_bill')" />
                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="primary">{{ __('promotion::action.add_step') }}</x-ui.button>
                        </div>
                    </form>
                @endcan
            @endif
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-1 font-semibold">{{ __('promotion::section.scopes') }}</h2>

            {{-- ⓘ সারি না থাকা মানে "সব" — এটা না বললে মানুষ ভাবতেন খালি মানে "কেউ না" --}}
            <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('promotion::message.empty_scope_means_all') }}</p>

            @foreach ($offer->scopes as $scope)
                <div class="flex items-center justify-between border-b border-(--color-border) py-2 text-sm">
                    <span>{{ $scope->kind->label() }} #{{ $scope->target_id }}</span>

                    @if ($offer->status === \App\Modules\Promotion\Support\PromotionStatus::DRAFT)
                        @can('promotion.update')
                            <form method="POST" action="{{ route('promotion.scope.destroy', [$offer, $scope]) }}">
                                @csrf
                                @method('DELETE')
                                <x-ui.button type="submit" tone="danger">{{ __('core.action.delete') }}</x-ui.button>
                            </form>
                        @endcan
                    @endif
                </div>
            @endforeach

            @if ($offer->status === \App\Modules\Promotion\Support\PromotionStatus::DRAFT)
                @can('promotion.update')
                    <form method="POST" action="{{ route('promotion.scope.store', $offer) }}" class="mt-4 grid gap-3 sm:grid-cols-3">
                        @csrf
                        <x-ui.select name="kind" :label="__('promotion::field.scope_kind')"
                                     :options="collect($scopeKinds)->mapWithKeys(fn ($k) => [$k->value => $k->label()])"
                                     required />
                        <x-ui.field name="target_id" type="number" :label="__('promotion::field.target_id')" required />
                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="secondary">{{ __('promotion::action.add_scope') }}</x-ui.button>
                        </div>
                    </form>
                @endcan
            @endif
        </section>

        {{--
            ⭐ বাজেট — ছাদ আর খরচ পাশাপাশি (স্পেক §১৫)।

            ⓘ খরচের অঙ্কটা BudgetGuard::usage() থেকে, অর্থাৎ বিল থামানোর
            একই হিসাব। ⚠️ পাতার জন্য আলাদা হিসাব লিখলে একদিন পাতা বলত
            "জায়গা আছে", অথচ কাউন্টারে বিল আটকে যেত।
        --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('promotion::section.budget') }}</h2>

            @forelse ($budgets as $row)
                <div class="flex items-center justify-between border-b border-(--color-border) py-2 text-sm">
                    <span>{{ __('promotion::budget.'.$row['budget']->kind) }} · {{ __('promotion::budget.per_'.($row['budget']->per ?? 'offer')) }}</span>
                    <span class="tabular-nums">
                        @if ($row['used'] !== null)
                            {{ __('promotion::field.used') }} {{ $row['used'] }} / {{ __('promotion::field.ceiling') }} {{ $row['budget']->ceiling }}
                            <x-ui.badge :tone="$row['percent'] >= $row['budget']->warn_at_percent ? 'danger' : 'info'">{{ $row['percent'] }}%</x-ui.badge>
                        @else
                            {{ __('promotion::field.ceiling') }} {{ $row['budget']->ceiling }}
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::message.no_budget') }}</p>
            @endforelse

            @if ($budgetOpen)
                @can('promotion.budget')
                    <form method="POST" action="{{ route('promotion.budget.store', $offer) }}" class="mt-4 grid gap-3 sm:grid-cols-5">
                        @csrf
                        <x-ui.select name="kind" :label="__('promotion::field.budget_kind')"
                                     :options="collect($budgetKinds)->mapWithKeys(fn ($k) => [$k => __('promotion::budget.'.$k)])"
                                     required />
                        <x-ui.select name="per" :label="__('promotion::field.budget_per')"
                                     :options="collect($budgetWindows)->mapWithKeys(fn ($w) => [$w => __('promotion::budget.per_'.$w)])"
                                     required />
                        <x-ui.field name="ceiling" type="number" step="0.0001" :label="__('promotion::field.ceiling')" required />
                        <x-ui.field name="warn_at_percent" type="number" :value="80" :label="__('promotion::field.warn_at_percent')" />
                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="secondary">{{ __('promotion::action.save_budget') }}</x-ui.button>
                        </div>
                    </form>
                @endcan
            @endif
        </section>

        {{--
            ⭐ মেয়াদ বাড়ানো বা কমানো — মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর:
            "barate hole barabe, komate hole komabe, off korte hole korbe".

            ⚠️ রুট আর সেবা আগে থেকেই ছিল, কিন্তু ফর্ম ছিল না — অর্থাৎ
            মালিকের চাওয়া কাজটা কোডে ছিল, পর্দায় ছিল না। ⓘ বন্ধ করা
            উপরের "থামান" আর "বাতিল করুন" বোতাম।
        --}}
        @if ($reschedulable)
            @can('promotion.update')
                <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('promotion::section.period') }}</h2>

                    <form method="POST" action="{{ route('promotion.reschedule', $offer) }}" class="grid gap-3 sm:grid-cols-3">
                        @csrf
                        <x-ui.field name="ends_on" type="date" :value="$offer->ends_on->toDateString()" :label="__('promotion::field.ends_on')" required />
                        <x-ui.field name="ends_at" type="time" :value="$offer->ends_at ? substr($offer->ends_at, 0, 5) : null" :label="__('promotion::field.ends_at')" />
                        <div class="flex items-end">
                            <x-ui.button type="submit" tone="secondary">{{ __('promotion::action.reschedule') }}</x-ui.button>
                        </div>
                    </form>
                </section>
            @endcan
        @endif
    </div>
</x-layouts.app>
