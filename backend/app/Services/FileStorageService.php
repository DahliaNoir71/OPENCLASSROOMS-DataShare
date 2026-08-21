<?php

namespace App\Services;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Écrit les octets déposés sur le disque dédié puis crée la ligne de
 * métadonnées qui les rend accessibles — dans cet ordre, jamais l'inverse :
 * c'est le miroir de la purge (US10), qui efface le contenu physique avant la
 * ligne en base. Un octet sans ligne coûte du disque ; une ligne sans octet
 * donnerait un 500 au destinataire d'un lien qui ne pointerait vers rien.
 */
class FileStorageService
{
    public function store(User $user, UploadedFile $upload, ?string $password, int $expiresInDays): File
    {
        $disk = (string) config('datashare.uploads.disk');
        $directory = now()->format('Y/m/d');
        $storedName = (string) Str::uuid();

        // Nom physique aléatoire, sans extension : anti-collision et
        // anti-traversée de chemin, et rien d'exécutable sur le disque même
        // en cas d'erreur de configuration du serveur. Peut lever (le disque
        // 'uploads' est configuré avec 'throw' => true) — rien n'a encore été
        // persisté en base à cet instant, donc rien à nettoyer.
        $storedPath = $upload->storeAs($directory, $storedName, ['disk' => $disk]);

        if ($storedPath === false) {
            throw new RuntimeException("Échec de l'écriture du fichier déposé.");
        }

        try {
            return File::create([
                'user_id' => $user->id,
                'token' => $this->uniqueToken(),
                'original_name' => $this->sanitizeOriginalName($upload->getClientOriginalName()),
                'stored_path' => $storedPath,
                'mime_type' => $upload->getMimeType() ?: 'application/octet-stream',
                'size' => $upload->getSize(),
                'password' => $password,
                'expires_at' => now()->addDays($expiresInDays),
            ]);
        } catch (Throwable $e) {
            $this->cleanupOrphan($disk, $storedPath, $user->id, (int) $upload->getSize());

            throw $e;
        }
    }

    /**
     * Boucle bornée à 3 tentatives : elle ne sert qu'à ne jamais faire
     * remonter une violation de contrainte d'unicité au client. La garantie
     * réelle reste l'index unique sur la colonne.
     */
    private function uniqueToken(): string
    {
        $length = (int) config('datashare.uploads.token_length');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = Str::random($length);

            if (! File::where('token', $token)->exists()) {
                return $token;
            }
        }

        throw new RuntimeException('Impossible de générer un token de téléchargement unique.');
    }

    /**
     * Le nom d'origine repart un jour en Content-Disposition (US02) : le
     * normaliser à l'écriture évite d'avoir à s'en méfier partout ensuite. Il
     * ne sert jamais à écrire sur le disque, donc aucun risque de traversée de
     * chemin ici — seulement d'affichage.
     */
    private function sanitizeOriginalName(?string $name): string
    {
        $name = basename($name ?? '') ?: 'fichier';
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? $name;

        return mb_substr($name, 0, 255);
    }

    private function cleanupOrphan(string $disk, string $path, int $userId, int $size): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable) {
            // Le chemin n'est volontairement pas journalisé : il contient
            // l'UUID physique, au même titre qu'un token un secret d'accès
            // qui n'a rien à faire dans un journal.
            Log::warning('Orphaned upload', ['user_id' => $userId, 'bytes' => $size]);
        }
    }
}
