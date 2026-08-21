<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin File
 */
class FileResource extends JsonResource
{
    /**
     * Exactement les champs du contrat (docs/openapi.yaml, FileResource) :
     * jamais le token en tant que tel, jamais le hash du mot de passe, jamais
     * le chemin physique. Le token n'apparaît que noyé dans `link`.
     *
     * `protected` et `expired` sont calculés côté serveur — un navigateur à
     * l'horloge fausse ne doit pas pouvoir se croire dans les temps.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'protected' => $this->isProtected(),
            'expires_at' => $this->expires_at->toJSON(),
            'expired' => $this->isExpired(),
            'link' => rtrim((string) config('datashare.frontend_url'), '/').'/l/'.$this->token,
            'created_at' => $this->created_at->toJSON(),
        ];
    }
}
