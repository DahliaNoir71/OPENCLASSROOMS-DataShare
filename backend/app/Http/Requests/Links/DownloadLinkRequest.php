<?php

namespace App\Http\Requests\Links;

use Illuminate\Foundation\Http\FormRequest;

class DownloadLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Le seul champ du corps, et une validation volontairement minimale. Elle
     * ne sert qu'à ce qu'une valeur mal typée — `password` tableau ou objet —
     * réponde 422 plutôt que de faire exploser Hash::check.
     *
     * `nullable` et non `required` : « fichier protégé, mot de passe absent »
     * est un 401, décidé par DownloadLinkService après résolution du lien.
     * L'exiger ici inverserait l'ordre des codes, puisque la validation court
     * avant la recherche de la ligne — un token inconnu répondrait 422 au lieu
     * de 404.
     *
     * Pas de `min` : une borne de longueur sur une entrée de vérification
     * divulgue la politique de mot de passe, et ferait diverger un partage
     * déposé avant un changement de règle. `max:72` est en revanche conservé,
     * pour la raison qui vaut au dépôt — au-delà, bcrypt tronque — et pour ne
     * pas faire hacher une chaîne arbitrairement longue.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'max:72'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.string' => 'Le mot de passe doit être une chaîne de caractères.',
            'password.max' => 'Le mot de passe ne doit pas dépasser 72 caractères.',
        ];
    }
}
