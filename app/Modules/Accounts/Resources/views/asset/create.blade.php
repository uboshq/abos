{{--
    নতুন সম্পদ — নিজের পাতায়।

    আগে ফর্মটা তালিকার পাতায় গোঁজা ছিল, মাস শেষের দৌড়ের ঠিক নিচে।
    দুইটা আলাদা কাজ পাশাপাশি থাকায় পর্দাটা পড়তে হত আগে, বোঝা যেত পরে।

    ⓘ মাস শেষের দৌড়টা (এক ঘরের `month` ফর্ম) তালিকার পাতাতেই আছে, আর
    ইচ্ছাকৃতভাবে — ওটা একটা বোতাম, একটা পাতা নয়। কারণটা রুট ফাইলেও লেখা।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::asset.register_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::asset.register_title')"
                          :subtitle="__('accounts::asset.subtitle')" />
    </x-slot:header>

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('accounts.asset.store') }}"
          x-data="{ method: @js(old('method', '')) }"
          class="grid gap-3 rounded-(--radius-card) border border-(--color-border)
                 bg-(--color-surface-card) p-4 md:grid-cols-2 lg:grid-cols-4">
        @csrf

        {{-- ⭐ শ্রেণি — পাঁচ খাত, পদ্ধতি, আয়ু আর শেষ দামের হার একবারে (স্থায়ী সম্পদ ধাপ ১)। ⓘ নিচের খাত-পদ্ধতি খালি
             রাখলে শ্রেণিরটাই বসে; হাতে দিলে হাতেরটা জেতে। --}}
        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.category') }}</span>
            <select name="category_id"
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                <option value="">{{ __('accounts::asset.no_category') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.name') }}</span>
            <input type="text" name="name" required value="{{ old('name') }}"
                   class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.account') }}</span>
            <select name="asset_account_id"
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                <option value="">{{ __('accounts::asset.from_category') }}</option>
                @foreach ($assetAccounts as $account)
                    <option value="{{ $account->id }}" @selected(old('asset_account_id') == $account->id)>{{ $account->label() }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.cost') }}</span>
            <input type="number" step="0.01" min="0" name="cost" value="{{ old('cost') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
            <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.cost_hint') }}</span>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.salvage') }}</span>
            <input type="number" step="0.01" min="0" name="salvage" value="{{ old('salvage') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.acquired_on') }}</span>
            <x-ui.date name="acquired_on" :required="true"
                       :value="old('acquired_on', now()->toDateString())" />
        </label>

        {{-- ⭐ এ পর্যন্ত যতটা ক্ষয় ধরা হয়েছে — ২০ সেপ্টেম্বর ২০২৬।

             ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────
             তিন বছর চলা একটা ভ্যান নতুন হিসেবে ঢুকত: খাতায় তার দাম পুরো
             দেখাত, আর অবচয় শুরু হত আজ থেকে। ⚠️ অর্থাৎ তিন বছরের ক্ষয়
             একবারে মুছে যেত — সম্পদটা ফুলে থাকত, আর পরের বছরগুলোয় খরচ
             বেশি দেখাত।

             ⓘ ঘরটা খালি রাখলে কিছুই বদলায় না — নতুন জিনিসের ক্ষয় শূন্য।
             ⚠️ আর এই অঙ্কটা খরচে যায় না, যায় সঞ্চিত মুনাফায়: ওই ক্ষয়
             আগের বছরগুলোর, এই বছরের খরচ নয়। --}}
        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.opening_accumulated') }}</span>
            <input type="number" step="0.01" min="0" name="opening_accumulated"
                   value="{{ old('opening_accumulated', 0) }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
            <span class="text-2xs text-(--color-ink-muted)">
                {{ __('accounts::asset.opening_accumulated_hint') }}
            </span>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-sm font-medium">{{ __('accounts::asset.method') }}</span>
            <select name="method" x-model="method"
                    class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                           bg-(--color-surface-app) px-2">
                <option value="">{{ __('accounts::asset.from_category') }}</option>
                <option value="{{ \App\Modules\Accounts\Models\FixedAsset::STRAIGHT_LINE }}">
                    {{ __('accounts::asset.straight') }}
                </option>
                <option value="{{ \App\Modules\Accounts\Models\FixedAsset::REDUCING }}">
                    {{ __('accounts::asset.reducing') }}
                </option>
            </select>
        </label>

        {{-- একটা পদ্ধতিতে আয়ু লাগে, অন্যটায় হার — দুইটা একসাথে নয়। --}}
        <label class="flex flex-col gap-1" x-show="method !== 'reducing'">
            <span class="text-sm font-medium">{{ __('accounts::asset.life_months') }}</span>
            <input type="number" step="1" min="1" name="life_months" value="{{ old('life_months') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        <label class="flex flex-col gap-1" x-show="method === 'reducing'" x-cloak>
            <span class="text-sm font-medium">{{ __('accounts::asset.rate') }}</span>
            <input type="number" step="0.01" min="0" max="100" name="rate" value="{{ old('rate') }}"
                   class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                          bg-(--color-surface-app) px-2 text-end">
        </label>

        {{-- ⭐ কোথায়, কার হাতে, কী জিনিস — নিবন্ধনের ঘর (স্থায়ী সম্পদ ধাপ ১, IAS 16)। ⓘ সব ঐচ্ছিক; পরে গণনা, ওয়ারেন্টি
             আর বীমার মেয়াদের তালিকা এগুলো থেকেই আসে। --}}
        <fieldset class="grid gap-3 md:col-span-2 md:grid-cols-2 lg:col-span-4 lg:grid-cols-4">
            <legend class="mb-1 text-sm font-semibold">{{ __('accounts::asset.details') }}</legend>

            <x-ui.select name="branch_id" :label="__('accounts::asset.branch')"
                         :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])->all()"
                         :selected="old('branch_id', \App\Core\Support\CompanyContext::branchId())" placeholder="—" />
            <x-ui.field name="location" :label="__('accounts::asset.location')" :value="old('location')" />
            <x-ui.field name="department" :label="__('accounts::asset.department')" :value="old('department')" />
            <x-ui.select name="custodian_id" :label="__('accounts::asset.custodian')" :options="$employees"
                         :selected="old('custodian_id')" placeholder="—" />
            <x-ui.select name="supplier_id" :label="__('accounts::asset.supplier')" :options="$suppliers"
                         :selected="old('supplier_id')" placeholder="—" />
            <x-ui.select name="parent_id" :label="__('accounts::asset.parent')"
                         :options="$parents->mapWithKeys(fn ($p) => [$p->id => $p->document_no.' — '.$p->name])->all()"
                         :selected="old('parent_id')" placeholder="—" :hint="__('accounts::asset.parent_hint')" />
            <x-ui.field name="tag_no" :label="__('accounts::asset.tag_no')" :value="old('tag_no')" :hint="__('accounts::asset.tag_hint')" />
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.put_in_use_on') }}</span>
                <x-ui.date name="put_in_use_on" :value="old('put_in_use_on')" />
            </label>
            <x-ui.field name="serial_no" :label="__('accounts::asset.serial_no')" :value="old('serial_no')" />
            <x-ui.field name="model_no" :label="__('accounts::asset.model_no')" :value="old('model_no')" />
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.warranty_ends_on') }}</span>
                <x-ui.date name="warranty_ends_on" :value="old('warranty_ends_on')" />
            </label>
            <x-ui.field name="insurance_policy_no" :label="__('accounts::asset.insurance_policy_no')" :value="old('insurance_policy_no')" />
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">{{ __('accounts::asset.insured_until') }}</span>
                <x-ui.date name="insured_until" :value="old('insured_until')" />
            </label>
        </fieldset>

        {{-- ⭐ দামের ভাগ — কেনা, আনা, বসানো, শুল্ক (IAS 16.16)। ⓘ ভরলে যোগফলই দাম, উপরের "দাম" ঘর খালি রাখুন। --}}
        <fieldset class="md:col-span-2 lg:col-span-4">
            <legend class="text-sm font-semibold">{{ __('accounts::asset.cost_parts') }}</legend>
            <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.cost_parts_note') }}</p>

            <div class="grid gap-2 md:grid-cols-2 lg:grid-cols-5">
                @foreach (\App\Modules\Accounts\Models\AssetCostPart::KINDS as $i => $kind)
                    <label class="flex flex-col gap-1">
                        <span class="text-xs">{{ __('accounts::asset.part_'.$kind) }}</span>
                        <input type="hidden" name="cost_parts[{{ $i }}][kind]" value="{{ $kind }}">
                        <input type="number" step="0.01" min="0" name="cost_parts[{{ $i }}][amount]"
                               value="{{ old('cost_parts.'.$i.'.amount') }}"
                               class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-app) px-2 text-end">
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- ⭐ টাকাটা কোথা থেকে এল — ২০ সেপ্টেম্বর ২০২৬।

             ⛔ আগে এই প্রশ্নটাই ছিল না, তাই সম্পদটা কেবল একটা রেকর্ড হয়ে
             থাকত আর খাতায় একটা সারিও উঠত না — অথচ অবচয় বসতে থাকত। মালিক
             অফিসের কম্পিউটার বসিয়ে ধরলেন: "এই টাকাটা কোথা থেকে যাবে আর
             কোথায় জমা হবে?"

             ⓘ শেষ বিকল্পটা ("আগেই বসানো") না রাখলে পুরনো অভ্যাসে যিনি
             ভাউচার কেটে আসেন, তাঁর কেনা দুইবার খাতায় উঠত। --}}
        <fieldset class="md:col-span-2 lg:col-span-4"
                  x-data="{ funded: @js(old('funded_by', $pickedLine ? \App\Modules\Accounts\Services\FixedAssetService::FUNDED_BILL : \App\Modules\Accounts\Services\FixedAssetService::FUNDED_CAPITAL)) }">
            <legend class="text-sm font-medium">{{ __('accounts::asset.funded_by') }}</legend>
            <p class="mb-2 text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.funded_by_note') }}</p>

            <div class="grid gap-2 md:grid-cols-2">
                @foreach (\App\Modules\Accounts\Services\FixedAssetService::FUNDING_WAYS as $way)
                    <label class="flex items-start gap-2 rounded-(--radius-field) border border-(--color-border) px-3 py-2">
                        <input type="radio" name="funded_by" value="{{ $way }}" x-model="funded"
                               class="mt-1 size-4 shrink-0">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ __('accounts::asset.funded_'.$way) }}</span>
                            <span class="block text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.funded_'.$way.'_note') }}</span>
                        </span>
                    </label>
                @endforeach
            </div>

            <div class="mt-2 grid gap-3 md:grid-cols-2">
                <label class="flex flex-col gap-1" x-show="funded === 'capital'">
                    <span class="text-sm font-medium">{{ __('accounts::asset.funding_person') }}</span>
                    <select name="funding_person_id"
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2">
                        <option value="">—</option>
                        {{-- ⓘ তালিকাটা এখন কোর থেকে আসে (`[id => নাম]`), মডিউলের
                             মডেল থেকে নয় — সীমারেখার কারণ কন্ট্রোলারে লেখা। --}}
                        @foreach ($people as $id => $name)
                            <option value="{{ $id }}" @selected(old('funding_person_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1" x-show="funded === 'money'" x-cloak>
                    <span class="text-sm font-medium">{{ __('accounts::asset.funding_account') }}</span>
                    <select name="funding_account_id"
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2">
                        <option value="">—</option>
                        @foreach ($moneyAccounts as $account)
                            <option value="{{ $account->id }}" @selected(old('funding_account_id') == $account->id)>{{ $account->label() }}</option>
                        @endforeach
                    </select>
                </label>

                {{-- ⭐ পাকা ক্রয় বিলের সারি — মালটা মজুদ থেকে সম্পদে সরে, কেনাটা আবার বসে না (স্থায়ী সম্পদ ধাপ ১) --}}
                <label class="flex flex-col gap-1 md:col-span-2" x-show="funded === 'bill'" x-cloak>
                    <span class="text-sm font-medium">{{ __('accounts::asset.bill_line') }}</span>
                    <select name="purchase_bill_line_id"
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2">
                        <option value="">—</option>
                        @foreach ($billLines as $line)
                            <option value="{{ $line['id'] }}" @selected(old('purchase_bill_line_id', $pickedLine) == $line['id'])>
                                {{ $line['bill_no'] }} · {{ $line['date'] }} · {{ $line['supplier'] }} · {{ $line['product'] }}
                                ({{ \App\Core\Support\Money::quantity($line['qty']) }} × {{ \App\Core\Support\Money::format($line['unit_cost']) }})
                            </option>
                        @endforeach
                    </select>
                    <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::asset.bill_line_note') }}</span>
                </label>

                <label class="flex flex-col gap-1" x-show="funded === 'bill'" x-cloak>
                    <span class="text-sm font-medium">{{ __('accounts::asset.capitalised_qty') }}</span>
                    <input type="number" step="0.0001" min="0" name="capitalised_qty" value="{{ old('capitalised_qty', 1) }}"
                           class="num h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                  bg-(--color-surface-app) px-2 text-end">
                </label>

                <label class="flex flex-col gap-1" x-show="funded === 'credit'" x-cloak>
                    <span class="text-sm font-medium">{{ __('accounts::asset.funding_supplier') }}</span>
                    <select name="funding_supplier_id"
                            class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border)
                                   bg-(--color-surface-app) px-2">
                        <option value="">—</option>
                        @foreach ($suppliers as $id => $name)
                            <option value="{{ $id }}" @selected(old('funding_supplier_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </fieldset>

        <div class="flex flex-wrap items-end gap-2 md:col-span-2 lg:col-span-4">
            <x-ui.button type="submit" tone="primary">
                {{ __('accounts::asset.register_action') }}
            </x-ui.button>

            <x-ui.button tone="secondary" :href="route('accounts.asset.index')">
                {{ __('core.action.cancel') }}
            </x-ui.button>
        </div>
    </form>
</x-layouts.app>
