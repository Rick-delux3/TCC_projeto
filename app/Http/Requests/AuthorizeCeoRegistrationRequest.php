<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizeCeoRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $configuredSecret = config('admin.ceo_registration_secret');
        $submittedSecret = $this->request->get('key');

        return is_string($configuredSecret)
            && $configuredSecret !== ''
            && is_string($submittedSecret)
            && hash_equals($configuredSecret, $submittedSecret);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [
            'key' => $this->request->get('key'),
        ];
    }
}
