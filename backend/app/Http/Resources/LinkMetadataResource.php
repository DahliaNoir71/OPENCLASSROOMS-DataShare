<?php

namespace App\Http\Resources;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin File
 */
class LinkMetadataResource extends JsonResource
{
    /**
     * Pas d'enveloppe `data` : le contrat décrit LinkMetadata à plat, à la
     * différence d'UploadResponse. La redéclaration du statique reste locale à
     * la classe — ResourceResponse lit `get_class($resource)::$wrap` — donc
     * FileResource conserve la sienne.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Le pendant public de FileResource : cinq champs, et tout le reste tenu
     * dehors (docs/openapi.yaml, LinkMetadata).
     *
     * Ce qui n'y figure pas, et pourquoi : `id` révélerait le volume de dépôts
     * et son rythme à un anonyme ; `created_at` la date du dépôt, alors que
     * c'est pour ne pas la divulguer que Str::ulid() a été écarté du token
     * (docs/mcd.md) ; `token` est déjà connu de l'appelant, le renvoyer
     * n'élargirait que la surface de fuite ; `stored_path` et le propriétaire
     * ne le concernent pas. `expired` n'a pas lieu d'être ici : un 200 signifie
     * non expiré, l'expiration est un 410.
     *
     * `protected` est calculé côté serveur, comme dans FileResource.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'original_name' => $this->original_name,
            'size' => $this->size,
            'mime_type' => $this->mime_type,
            'protected' => $this->isProtected(),
            'expires_at' => $this->expires_at->toJSON(),
        ];
    }
}
