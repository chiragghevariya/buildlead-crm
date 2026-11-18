<?php

namespace App\Http\Requests;

use App\Enums\LeadSource;
use App\Enums\ProjectType;
use App\Enums\Timeline;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared rules for creating (POST) and updating (PATCH) leads.
 */
class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policies are checked in the controller
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'contact_name' => [$req, 'string', 'max:120'],
            'company' => ['sometimes', 'nullable', 'string', 'max:190'],
            // On create at least one contact channel is required ("sometimes" would skip required_without).
            'email' => [...($creating ? ['required_without:phone'] : ['sometimes']), 'nullable', 'email:rfc', 'max:190'],
            'phone' => [...($creating ? ['required_without:email'] : ['sometimes']), 'nullable', 'string', 'max:40'],
            'source' => [$req, Rule::enum(LeadSource::class)],
            'project_type' => ['sometimes', 'nullable', Rule::enum(ProjectType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'budget_min' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'budget_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'timeline' => ['sometimes', 'nullable', Rule::enum(Timeline::class)],
            'street' => ['sometimes', 'nullable', 'string', 'max:190'],
            'postcode' => ['sometimes', 'nullable', 'regex:/^\d{5}$/'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'area_sqm' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'estimated_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'consent_given' => ['sometimes', 'boolean'],
            // Only admins/managers may choose the owner; sales own what they create.
            'assigned_to' => $this->user()?->isSales()
                ? ['prohibited']
                : ['sometimes', 'nullable', Rule::exists('users', 'id')->where('role', UserRole::Sales->value)->where('is_active', true)],
        ];
    }

    /** Budget sanity, using the stored value for whichever side the PATCH omits. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $lead = $this->route('lead');
            $min = $this->has('budget_min') ? $this->input('budget_min') : $lead?->budget_min;
            $max = $this->has('budget_max') ? $this->input('budget_max') : $lead?->budget_max;

            if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                $validator->errors()->add('budget_max', 'The maximum budget must be at least the minimum budget.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'assigned_to.prohibited' => 'Only managers can assign leads.',
            'assigned_to.exists' => 'Leads can only be assigned to active sales reps.',
            'postcode.regex' => 'Please enter a valid 5-digit postcode.',
        ];
    }
}
