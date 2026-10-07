{{--
    খাতের ফর্মে "কোন প্রতিষ্ঠান" — অর্থ থেকে আসা ঘর ([[InstitutionFieldOnAccountForm]])।
    ⓘ নাম `ext[...]`-এ: হিসাব ঘরটার মানে জানে না, কেবল বয়ে নেয়।
--}}
<x-ui.select name="ext[institution_id]"
             :label="__('finance::institution.which')"
             :options="$options"
             :selected="old('ext.institution_id', $selected)"
             :hint="__('finance::institution.coa_hint')"
             placeholder="—" />
