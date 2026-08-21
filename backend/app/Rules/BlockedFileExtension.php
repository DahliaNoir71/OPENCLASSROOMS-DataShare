<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Une liste noire d'extensions n'est pas un contrôle de sécurité : elle se
 * contourne en renommant le fichier ou en le compressant. Elle réduit
 * seulement le risque qu'un destinataire exécute accidentellement ce qu'il
 * vient de recevoir. Les garde-fous qui comptent sont ailleurs : les octets
 * vivent hors racine web, ne sont jamais exécutés côté serveur, et repartent
 * en Content-Disposition: attachment (US02).
 *
 * Vérifiée sur l'extension déclarée par le client, pas sur le contenu : c'est
 * elle qui déterminera le nom — donc l'extension — que le poste du
 * destinataire écrira sur son disque, quel que soit le type MIME réel.
 */
class BlockedFileExtension implements ValidationRule
{
    /**
     * @param  list<string>  $blockedExtensions
     */
    public function __construct(private readonly array $blockedExtensions) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (in_array($extension, $this->blockedExtensions, true)) {
            $fail("L'extension .{$extension} n'est pas autorisée.");
        }
    }
}
