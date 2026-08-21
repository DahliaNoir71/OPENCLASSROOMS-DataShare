<?php

namespace App\Http\Requests\Files;

use App\Rules\BlockedFileExtension;
use Illuminate\Foundation\Http\FormRequest;

class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // La borne vient de la config, jamais d'un littéral : c'est ce qui
        // permet à un test de l'abaisser (config(['datashare.uploads.max_bytes' => ...]))
        // sans écrire un fichier de 1 Go pour vérifier le rejet.
        $maxKilobytes = (int) (config('datashare.uploads.max_bytes') / 1024);
        $maxExpiryDays = (int) config('datashare.uploads.max_expiry_days');

        return [
            'file' => [
                'required',
                'file',
                "max:{$maxKilobytes}",
                new BlockedFileExtension(config('datashare.uploads.blocked_extensions')),
            ],
            'expires_in_days' => ['nullable', 'integer', 'min:1', "max:{$maxExpiryDays}"],
            // 72 : au-delà, bcrypt tronque silencieusement le mot de passe —
            // autant refuser explicitement que de valider une valeur dont
            // seuls les 72 premiers octets compteraient réellement.
            'password' => ['nullable', 'string', 'min:6', 'max:72'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Le fichier est requis.',
            'file.file' => 'Le fichier envoyé est invalide.',
            'file.max' => 'Le fichier dépasse la taille maximale autorisée (1 Go).',
            'expires_in_days.integer' => "La durée d'expiration doit être un nombre entier de jours.",
            'expires_in_days.min' => "La durée d'expiration doit être d'au moins 1 jour.",
            'expires_in_days.max' => "La durée d'expiration ne peut pas dépasser :max jours.",
            'password.string' => 'Le mot de passe doit être une chaîne de caractères.',
            'password.min' => 'Le mot de passe doit contenir au moins 6 caractères.',
            'password.max' => 'Le mot de passe ne doit pas dépasser 72 caractères.',
        ];
    }
}
