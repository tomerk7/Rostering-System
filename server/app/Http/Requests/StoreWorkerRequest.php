<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\IsraeliId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'israeli_id' => ['required', 'string', new IsraeliId, Rule::unique('workers', 'israeli_id')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'is_active' => ['required', 'boolean'],

            'contract' => ['required', 'array'],
            'contract.hourly_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'contract.min_monthly_hours' => ['required', 'integer', 'min:0', 'max:744'],
            'contract.max_monthly_hours' => ['required', 'integer', 'min:0', 'max:744', 'gte:contract.min_monthly_hours'],

            'availability' => ['required', 'array', 'min:1'],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')],
        ];
    }
}
