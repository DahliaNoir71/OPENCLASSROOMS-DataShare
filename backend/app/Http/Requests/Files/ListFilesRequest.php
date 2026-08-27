<?php

namespace App\Http\Requests\Files;

use Illuminate\Foundation\Http\FormRequest;

class ListFilesRequest extends FormRequest
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
        // La borne haute vient de la config, jamais d'un littéral : c'est ce
        // qui permet à un test de l'abaisser (config(['datashare.history.max_per_page' => ...]))
        // sans générer des dizaines de fichiers pour vérifier le rejet.
        $maxPerPage = (int) config('datashare.history.max_per_page');

        return [
            'status' => ['nullable', 'string', 'in:all,active,expired'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', "max:{$maxPerPage}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Le filtre demandé est invalide.',
            'page.integer' => 'Le numéro de page doit être un nombre entier.',
            'page.min' => 'Le numéro de page doit être supérieur ou égal à 1.',
            'per_page.integer' => "Le nombre d'éléments par page doit être un nombre entier.",
            'per_page.min' => "Le nombre d'éléments par page doit être supérieur ou égal à 1.",
            'per_page.max' => "Le nombre d'éléments par page ne peut pas dépasser :max.",
        ];
    }
}
