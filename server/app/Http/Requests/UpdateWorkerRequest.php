<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Worker;
use App\Rules\IsraeliId;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWorkerRequest extends FormRequest
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
            'israeli_id' => [
                'required',
                'string',
                'size:9',
                new IsraeliId,
                Rule::unique('workers', 'israeli_id')->ignore($this->workerId()),
            ],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'is_active' => ['required', 'boolean'],

            'contract' => ['required', 'array'],
            'contract.hourly_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'contract.min_monthly_hours' => ['required', 'integer', 'min:0', 'max:744'],
            'contract.max_monthly_hours' => ['required', 'integer', 'min:0', 'max:744', 'gte:contract.min_monthly_hours'],

            'availability' => ['required', 'array'],
            'availability.days' => ['required', 'array', 'min:1', 'max:7'],
            'availability.days.*' => ['required', 'integer', 'between:0,6', 'distinct'],
            'availability.shifts' => ['required', 'array', 'min:1'],
            'availability.shifts.*' => ['required', 'integer', Rule::exists('shifts', 'id'), 'distinct'],
        ];
    }

    private function workerId(): int|string|null
    {
        $worker = $this->route('worker');

        if ($worker instanceof Worker) {
            return $worker->getKey();
        }

        return is_scalar($worker) ? $worker : null;
    }
}
