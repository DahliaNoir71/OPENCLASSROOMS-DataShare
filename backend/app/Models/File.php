<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'token', 'original_name', 'stored_path', 'mime_type', 'size', 'password', 'expires_at'])]
#[Hidden(['password', 'token', 'stored_path'])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'password' => 'hashed',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculée plutôt que stockée : une seule source de vérité, revérifiée à
     * chaque accès plutôt que figée par une colonne d'état que la purge (US10)
     * devrait tenir à jour.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isProtected(): bool
    {
        return $this->password !== null;
    }

    /**
     * Le complément exact d'`isExpired()` (US05) : l'un et l'autre doivent
     * s'accorder à l'instant précis de l'échéance, faute de quoi une ligne
     * pile à `expires_at` se retrouverait dans les deux ensembles, ou dans
     * aucun.
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('expires_at', '>=', now());
    }

    #[Scope]
    protected function expired(Builder $query): void
    {
        $query->where('expires_at', '<', now());
    }
}
