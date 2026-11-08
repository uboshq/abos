{{--
    বেতনের চালানের "অ্যাকশন ▾" (মালিক, ৮ নভেম্বর ২০২৬) — পাতার একই শর্ত: `hr.payroll.view`-এ পে-স্লিপ, তার সাথে
    `hr.identity.view`-এ ব্যাংকের ফাইল; `hr.payroll.manage`-এ খসড়া নিশ্চিত (পাতায়) আর বাতিল। ⓘ বেতনের অঙ্ক মেনুতে নেই।
--}}
@php($can = auth()->user())
@php($draft = $run->isDraft())
<x-ui.paper-row-menu
    :paper="$run"
    :show="route('hr.payroll.show', $run)"
    :state="__('core.status.'.$run->status)"
    module="hr"
    :confirmable="$draft && (bool) $can?->can('hr.payroll.manage')"
    :cancellable="(bool) $can?->can('hr.payroll.manage')"
    :work="array_values(array_filter([
        $can?->can('hr.payroll.view') ? ['label' => __('core.row_menu.payslips'), 'icon' => 'documents', 'url' => route('hr.payroll.payslips', $run->id), 'peek' => false] : null,
        $can?->can('hr.payroll.view') && $can?->can('hr.identity.view') ? ['label' => __('core.row_menu.bank_file'), 'icon' => 'download', 'url' => route('hr.payroll.bank_file', $run->id), 'peek' => false] : null,
    ]))" />
