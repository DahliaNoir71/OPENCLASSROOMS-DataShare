<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the email before validation, exactly as the registration does:
     * the account is stored lowercased, so the lookup has to run on the same
     * canonical value or a user who capitalizes their address at sign-in would
     * be told their credentials are wrong.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => "L'email est requis.",
            'email.string' => "L'email doit être une chaîne de caractères.",
            'email.email' => "L'email doit être une adresse email valide.",
            'email.max' => "L'email ne doit pas dépasser 255 caractères.",
            'password.required' => 'Le mot de passe est requis.',
            'password.string' => 'Le mot de passe doit être une chaîne de caractères.',
        ];
    }
}
