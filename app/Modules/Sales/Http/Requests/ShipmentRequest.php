<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Core\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** ট্রিপের ইনপুট — গাড়ি, লোক, আর যে চালানগুলো উঠছে। */
class ShipmentRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = CompanyContext::id();

        return [
            'trx_date' => ['required', 'date'],
            'warehouse_id' => ['required', 'integer',
                Rule::exists('inv_warehouses', 'id')->where('company_id', $companyId)],

            // চালানের মতোই — বহরের গাড়ি হলে id, ভাড়ার হলে শুধু নম্বর
            'vehicle_id' => ['nullable', 'integer',
                Rule::exists('mdm_vehicles', 'id')->where('company_id', $companyId)],
            'vehicle_no' => ['nullable', 'string', 'max:64'],

            'driver_employee_id' => ['nullable', 'integer',
                Rule::exists('hr_employees', 'id')->where('company_id', $companyId)],
            'driver_name' => ['nullable', 'string', 'max:191'],
            'helper_name' => ['nullable', 'string', 'max:191'],

            'route_location_id' => ['nullable', 'integer',
                Rule::exists('mdm_locations', 'id')->where('company_id', $companyId)],

            'opening_km' => ['nullable', 'numeric', 'min:0'],
            'narration' => ['nullable', 'string', 'max:500'],

            /*
             * চালান ছাড়া ট্রিপ লেখা যায় — কিন্তু বেরোতে পারে না।
             *
             * সকালে কাগজটা খোলা হয় আর চালান একটা একটা করে ওঠে; খালি
             * খসড়া না লিখতে দিলে পুরো তালিকা হাতে গুছিয়ে তবেই সেভ
             * করতে হত। বেরোনোর সময় সেবা আটকায় (`dispatch`)।
             */
            'challans' => ['nullable', 'array'],
            'challans.*' => ['integer',
                Rule::exists('sal_challans', 'id')->where('company_id', $companyId)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function documentData(): array
    {
        return $this->safe()->only([
            'trx_date', 'warehouse_id', 'vehicle_id', 'vehicle_no',
            'driver_employee_id', 'driver_name', 'helper_name',
            'route_location_id', 'opening_km', 'narration',
        ]);
    }

    /**
     * @return list<int>
     */
    public function challanIds(): array
    {
        return array_values(array_map('intval', $this->safe()->array('challans')));
    }
}
