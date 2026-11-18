<?php

namespace App\Http\Requests;

use App\Enums\ProjectType;
use App\Enums\Timeline;
use App\Support\LeadNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Accepts messy website-form / email-parser / call-centre payloads (German or
 * English field names), normalises them into lead fields and validates.
 */
class IngestLeadRequest extends FormRequest
{
    /** Honeypot: real users never fill this hidden field. */
    public const HONEYPOT = '_hp';

    /** Field aliases → canonical name (first non-empty wins). */
    private const ALIASES = [
        'contact_name' => ['contact_name', 'name', 'full_name', 'fullname', 'from_name', 'caller_name'],
        'company' => ['company', 'firma', 'organisation', 'organization', 'unternehmen'],
        'email' => ['email', 'e_mail', 'e-mail', 'mail', 'from_email', 'from'],
        'phone' => ['phone', 'telephone', 'tel', 'telefon', 'mobile', 'handy', 'caller_id'],
        'project_type' => ['project_type', 'type', 'projekt', 'projektart', 'service', 'leistung'],
        'description' => ['description', 'message', 'nachricht', 'body', 'notes', 'notiz', 'beschreibung'],
        'budget' => ['budget', 'budget_range', 'budget_text', 'kosten'],
        'timeline' => ['timeline', 'zeitraum', 'start', 'when', 'wann', 'zeitpunkt'],
        'street' => ['street', 'strasse', 'straße', 'address', 'adresse'],
        'postcode' => ['postcode', 'plz', 'zip', 'postal_code', 'postleitzahl'],
        'city' => ['city', 'ort', 'stadt'],
        'area_sqm' => ['area_sqm', 'area', 'flaeche', 'fläche', 'sqm', 'wohnflaeche', 'wohnfläche'],
        'consent' => ['consent', 'gdpr_consent', 'privacy', 'datenschutz', 'dsgvo', 'privacy_consent'],
    ];

    /** Original payload as received (stored on the lead as raw_payload). */
    public array $rawPayload = [];

    public function authorize(): bool
    {
        return true; // shared-secret check happens in VerifyIngestToken
    }

    protected function prepareForValidation(): void
    {
        if (filled($this->input(self::HONEYPOT))) {
            // Pretend success so bots learn nothing.
            throw new HttpResponseException(response()->json(['status' => 'received'], 202));
        }

        $this->rawPayload = collect($this->all())->except(self::HONEYPOT)->all();
        $in = array_change_key_case($this->all(), CASE_LOWER);
        $pick = function (string $field) use ($in) {
            foreach (self::ALIASES[$field] as $alias) {
                $value = $in[$alias] ?? null;
                if (is_string($value)) {
                    $value = trim($value);
                }
                if (filled($value)) {
                    return $value;
                }
            }

            return null;
        };

        $name = $pick('contact_name')
            ?? trim(($in['first_name'] ?? $in['vorname'] ?? '').' '.($in['last_name'] ?? $in['nachname'] ?? ''));

        // Email parser payloads: put the subject in front of the body.
        $description = $pick('description');
        if (filled($in['subject'] ?? null)) {
            $description = trim($in['subject'].($description ? "\n\n".$description : ''));
        }

        [$min, $max] = filled($in['budget_min'] ?? null) || filled($in['budget_max'] ?? null)
            ? [LeadNormalizer::budget($in['budget_min'] ?? null)[0], LeadNormalizer::budget($in['budget_max'] ?? null)[1]]
            : LeadNormalizer::budget($pick('budget'));
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        $rawType = $pick('project_type');
        $rawTimeline = $pick('timeline');
        $area = $pick('area_sqm');
        $email = $pick('email');
        $postcode = $pick('postcode');

        $this->replace([
            'contact_name' => $name !== '' ? $name : null,
            'company' => $pick('company'),
            'email' => is_string($email) ? mb_strtolower($email) : $email,
            'phone' => $pick('phone'),
            'project_type' => LeadNormalizer::projectType(is_string($rawType) ? $rawType : null),
            'project_type_raw' => $rawType,
            'description' => $description,
            'budget_min' => $min,
            'budget_max' => $max,
            'timeline' => LeadNormalizer::timeline(is_string($rawTimeline) ? $rawTimeline : null),
            'street' => $pick('street'),
            'postcode' => is_string($postcode) ? preg_replace('/\s+/', '', $postcode) : $postcode,
            'city' => $pick('city'),
            'area_sqm' => is_numeric($area) ? (int) round((float) $area)
                : (is_string($area) && preg_match('/\d+/', $area, $m) ? (int) $m[0] : null),
            'consent' => $pick('consent'),
        ]);
    }

    public function rules(): array
    {
        return [
            'contact_name' => ['required', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:40', 'regex:/^[\d\s+\/().-]*$/', 'regex:/^(?:\D*\d){6,}\D*$/'],
            'project_type' => ['nullable', Rule::enum(ProjectType::class)],
            'project_type_raw' => ['nullable'],
            'description' => ['nullable', 'string', 'max:5000'],
            'budget_min' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'budget_max' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'timeline' => ['nullable', Rule::enum(Timeline::class)],
            'street' => ['nullable', 'string', 'max:190'],
            'postcode' => ['nullable', 'regex:/^\d{5}$/'],
            'city' => ['nullable', 'string', 'max:120'],
            'area_sqm' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'consent' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.accepted' => 'GDPR consent is required to process this enquiry.',
            'email.required_without' => 'Please provide an email address or phone number.',
            'phone.required_without' => 'Please provide a phone number or email address.',
            'phone.regex' => 'Please provide a valid phone number.',
            'postcode.regex' => 'Please provide a valid 5-digit German postcode.',
        ];
    }

    /** Lead attributes (consent handled by the service). */
    public function leadData(): array
    {
        $data = collect($this->validated())->except(['consent', 'project_type_raw'])->all();

        // Keep an unrecognised project type visible to sales.
        if (! $data['project_type'] && filled($this->input('project_type_raw')) && is_string($this->input('project_type_raw'))) {
            $data['description'] = trim('Project type: '.$this->input('project_type_raw')."\n".($data['description'] ?? ''));
        }

        return $data;
    }
}
