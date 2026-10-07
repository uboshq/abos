{{--
    কোন রোল কত টাকা পর্যন্ত সই দিতে পারে — ধাপ ৪।

    ── ⛔ যা ভাঙা ছিল, ২৪ সেপ্টেম্বর ২০২৬ ──────────────────────────────
    প্রবাহে নাম থাকলেই **যেকোনো অঙ্কে** সই দেওয়া যেত। ⚠️ অর্থাৎ যে
    সুপারভাইজারকে পাঁচ হাজারের ছাড়ের জন্য ধাপে বসানো হয়েছে, তিনি
    পাঁচ লাখের ছাড়েও একই বোতামে সই দিতে পারতেন — আর কোথাও কিছু লাল
    হত না, কারণ নিয়মটা কোনোদিন লেখাই হয়নি।

    ── ⚠️ কেন ক্রমটা এখানে গুরুত্বপূর্ণ ─────────────────────────────────
    ⓘ একজনের একাধিক সারি খাটতে পারে, আর **সবচেয়ে নির্দিষ্ট** সারিটা
    জেতে। ⛔ তালিকাটা অন্য ক্রমে দেখালে মালিক উপরের সারিটা পড়ে ধরে
    নিতেন ওটাই চলছে — আর পর্দাটা সত্যি বলেও ভুল বোঝাত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.limits') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('approval::menu.limits')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            {{ $errors->first() }}
        </div>
    @endif

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2 text-sm">
        {{ __('approval::message.limit_note') }}
    </p>

    <div class="grid gap-4 lg:grid-cols-[22rem_1fr] lg:items-start">
        {{-- ── নতুন সীমা ───────────────────────────────────────── --}}
        <section data-boxed
                 class="min-w-0 rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('approval::action.add_limit') }}</h2>

            <form method="POST" action="{{ route('approval.limit.store') }}" class="space-y-3">
                @csrf

                <x-ui.select name="role_id" :label="__('approval::field.role')" required
                             :options="$roles->pluck('name', 'id')"
                             :selected="old('role_id')" />

                {{-- ⓘ খালি = সব মডিউলে, খালি = সব কাজে, খালি = সব শাখায়।

                     ⚠️ তিনটাই একই ছাঁচ, আর সেটা ইচ্ছাকৃত: *"সবখানে
                     পাঁচ লাখ"* লিখতে হলে তিনটাই খালি রাখলেই হয়। ⛔ এক
                     জায়গায় "সব" নামে একটা আসল বিকল্প বসালে দুইভাবে একই
                     কথা বলা যেত, আর একদিন দুইটা আলাদা আচরণ করত। --}}
                <x-ui.select name="module" :label="__('approval::field.module')"
                             :options="collect($choices)->map(fn ($c) => $c['label'])"
                             :placeholder="__('approval::field.limit_everywhere')"
                             :selected="old('module')" />

                <x-ui.select name="action" :label="__('approval::field.action')"
                             :options="collect($labels)"
                             :placeholder="__('approval::field.limit_every_action')"
                             :selected="old('action')" />

                <x-ui.select name="branch_id" :label="__('approval::field.branch')"
                             :options="$branches->pluck('name_en', 'id')"
                             :placeholder="__('approval::field.limit_every_branch')"
                             :selected="old('branch_id')" />

                <x-ui.field name="max_amount" type="number" step="0.01" min="0"
                            :label="__('approval::field.max_amount')"
                            :placeholder="__('approval::field.limit_no_ceiling')"
                            :value="old('max_amount')"
                            :hint="__('approval::message.max_amount_hint')" />

                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        </section>

        {{-- ── যা বসানো আছে ────────────────────────────────────── --}}
        {{-- ⛔ খালি সীমা **শূন্য নয়** — পর্দায় সেটা লেখাই হয়।

             ⚠️ একটা ফাঁকা ঘর দেখে পাঠক ভাবতেন সীমাটা বসানো হয়নি,
             অথচ *'সীমা নেই'* একটা বসানো সিদ্ধান্ত।

             ⓘ মন্তব্যটা ট্যাগের **বাইরে**, আর সেটা নিয়ম:
             অ্যাট্রিবিউটে একটা ডবল কোট গোটা ট্যাগটাকে কাঁচা লেখা করে দেয়
             ([[NoBladeTagIsQuietlyLeftAsTextTest]])। --}}
        <div data-boxed
             class="min-w-0 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            <x-ui.table
                :empty="__('approval::message.no_limits')"
                :rows="$limits"
                :columns="[
                    ['key' => 'role', 'label' => __('approval::field.role'),
                     'render' => fn ($l) => $l->role?->name ?? '—'],
                    ['key' => 'module', 'label' => __('approval::field.module'),
                     'render' => fn ($l) => $l->module === null
                         ? __('approval::field.limit_everywhere')
                         : ($choices[$l->module]['label'] ?? $l->module)],
                    ['key' => 'action', 'label' => __('approval::field.action'),
                     'render' => fn ($l) => $l->action === null
                         ? __('approval::field.limit_every_action')
                         : ($labels[$l->action] ?? $l->action)],
                    ['key' => 'branch', 'label' => __('approval::field.branch'),
                     'render' => fn ($l) => $l->branch?->name_en ?? __('approval::field.limit_every_branch')],

                    ['key' => 'max_amount', 'label' => __('approval::field.max_amount'),
                     'numeric' => true, 'width' => '10rem',
                     'render' => fn ($l) => $l->max_amount === null
                         ? __('approval::field.limit_no_ceiling')
                         : \App\Core\Support\Money::format($l->max_amount)],
                    ['key' => 'off', 'label' => '', 'width' => '6rem',
                     'render' => fn ($l) => view('approval::limit.partials.remove', ['limit' => $l])],
                ]" />
        </div>
    </div>
</x-layouts.app>
