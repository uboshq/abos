{{--
    কর্মীর ফর্ম — চার ভাগে।

    পরিচয় · কাজ · বেতন পাঠানোর পথ · সিস্টেমের ব্যবহারকারী। এক লম্বা
    কলামে সব ঘর রাখলে ব্যাংকের ঘরগুলো পিতার নামের ঠিক নিচে পড়ত, আর
    দুইটা আলাদা কাজ একটাই কাজ মনে হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $employee->exists ? $employee->name() : __('hr::action.new_employee') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header
            :title="$employee->exists ? $employee->name() : __('hr::action.new_employee')"
            :subtitle="$employee->exists ? $employee->code : null" />
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

    <form method="POST"
          action="{{ $employee->exists ? route('hr.employee.update', $employee) : route('hr.employee.store') }}"
          class="space-y-4">
        @csrf
        @if ($employee->exists) @method('PUT') @endif

        {{-- পরিচয় --}}
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 md:grid-cols-3">
                {{-- খালি রাখলে কোডটা নিজে বসে (মালিকের নির্দেশ)। ঘরটা
                     থাকে, কারণ পুরনো খাতার কর্মী নম্বর ধরে রাখতে হতে পারে। --}}
                <x-ui.field name="code" :label="__('hr::field.code')"
                            :value="old('code', $employee->code)"
                            :placeholder="__('core.create.code_auto')"
                            :hint="$employee->exists ? null : __('core.create.code_auto_hint')" />
                <x-ui.field name="name_en" :label="__('hr::field.name_en')"
                            :value="old('name_en', $employee->name_en)" required />
                <x-ui.field name="name_bn" :label="__('hr::field.name_bn')"
                            :value="old('name_bn', $employee->name_bn)" />
                <x-ui.field name="father_name" :label="__('hr::field.father_name')"
                            :value="old('father_name', $employee->father_name)" />
                <x-ui.field name="mobile" :label="__('hr::field.mobile')"
                            :value="old('mobile', $employee->mobile)" />
                <x-ui.field name="email" type="email" :label="__('hr::field.email')"
                            :value="old('email', $employee->email)" />
                <x-ui.field name="national_id" :label="__('hr::field.national_id')"
                            :value="old('national_id', $employee->national_id)" />
            </div>
        </section>

        {{-- কাজ --}}
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <x-ui.select name="branch_id" :label="__('hr::field.branch')"
                             :options="$branches->mapWithKeys(fn ($b) => [$b->id => $b->name()])"
                             :selected="old('branch_id', $employee->branch_id)" placeholder="-" />
                <x-ui.select name="department_id" :label="__('hr::field.department')"
                             :options="$departments->mapWithKeys(fn ($d) => [$d->id => $d->name()])"
                             :selected="old('department_id', $employee->department_id)" placeholder="-" />
                <x-ui.select name="designation_id" :label="__('hr::field.designation')"
                             :options="$designations->mapWithKeys(fn ($d) => [$d->id => $d->name()])"
                             :selected="old('designation_id', $employee->designation_id)" placeholder="-" />
                <x-ui.select name="employment_type_id" :label="__('hr::field.employment_type')"
                             :options="$employmentTypes->mapWithKeys(fn ($t) => [$t->id => $t->name()])"
                             :selected="old('employment_type_id', $employee->employment_type_id)" placeholder="-" />
                <x-ui.field name="joining_date" type="date" :label="__('hr::field.joining_date')"
                            :value="old('joining_date', $employee->joining_date?->toDateString())" required />
                <x-ui.field name="leaving_date" type="date" :label="__('hr::field.leaving_date')"
                            :value="old('leaving_date', $employee->leaving_date?->toDateString())" />
            </div>
        </section>

        {{-- বেতন পাঠানোর পথ।

             ব্যাংকের ঘরগুলো সবসময় দেখানো হয়, নগদ বেছে নিলেও: একজন
             কর্মীর পথ বছরে একবার বদলায়, আর ঘরগুলো লুকিয়ে রাখলে পথ
             বদলানোর দিনে সেগুলো আবার ভরতে হত। --}}
        <section class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <x-ui.select name="payment_method" :label="__('hr::field.payment_method')"
                             :options="collect($paymentMethods)->mapWithKeys(fn ($m) => [$m => __('hr::kind.' . $m)])"
                             :selected="old('payment_method', $employee->payment_method)" required />
                <x-ui.field name="bank_name" :label="__('hr::field.bank_name')"
                            :value="old('bank_name', $employee->bank_name)" />
                <x-ui.field name="bank_branch" :label="__('hr::field.bank_branch')"
                            :value="old('bank_branch', $employee->bank_branch)" />
                <x-ui.field name="bank_account_name" :label="__('hr::field.bank_account_name')"
                            :value="old('bank_account_name', $employee->bank_account_name)" />
                <x-ui.field name="bank_account_no" :label="__('hr::field.bank_account_no')"
                            :value="old('bank_account_no', $employee->bank_account_no)" />
                <x-ui.field name="bank_routing_no" :label="__('hr::field.bank_routing_no')"
                            :value="old('bank_routing_no', $employee->bank_routing_no)" />
                <x-ui.field name="mfs_number" :label="__('hr::field.mfs_number')"
                            :value="old('mfs_number', $employee->mfs_number)" />
            </div>
        </section>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('hr.employee.index')">{{ __('core.action.cancel') }}</x-ui.button>
            <x-ui.button type="submit" tone="primary">{{ __('hr::action.save') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
