<?php

namespace App\Services;

use App\Exceptions\LinkContentMissingException;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Le disque dédié, dans les deux sens : l'écriture d'un dépôt et sa relecture
 * en flux.
 *
 * À l'écriture, les octets partent avant la ligne de métadonnées — dans cet
 * ordre, jamais l'inverse : c'est le miroir de la purge (US10), qui efface le
 * contenu physique avant la ligne en base. Un octet sans ligne coûte du
 * disque ; une ligne sans octet donnerait un lien qui ne pointe vers rien —
 * état que la relecture, plus bas, doit précisément savoir reconnaître.
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
     * Relit un dépôt en flux. `download()` construit une StreamedResponse dont
     * le callback enchaîne readStream et fpassthru : les octets ne passent
     * jamais par la mémoire de PHP, ce qui est la condition pour servir 1 Go.
     * Tout reste derrière la façade Storage, donc le driver reste
     * interchangeable (docs/architecture.md).
     *
     * Content-Type et Content-Length sont passés explicitement, ce qui fait
     * sauter les `??=` de FilesystemAdapter et donc les deux relevés de
     * métadonnées sur le disque. Trois raisons : le fichier physique est un
     * UUID sans extension, dont la détection par contenu annoncerait autre
     * chose que ce que le déposant a envoyé ; la base est la source de vérité,
     * et c'est déjà elle qui alimente les métadonnées annoncées juste avant au
     * même destinataire ; et sur un driver distant, ce sont deux requêtes de
     * moins par téléchargement.
     *
     * Content-Disposition, lui, est laissé à la façade : elle produit la forme
     * double de la RFC 6266 — repli ASCII plus `filename*=utf-8''` — dont un
     * nom d'origine hors ASCII a besoin. Le nom n'a pas à être réassaini ici,
     * sanitizeOriginalName() l'a fait à l'écriture.
     *
     * @throws LinkContentMissingException
     */
    public function stream(File $file): StreamedResponse
    {
        $disk = Storage::disk((string) config('datashare.uploads.disk'));

        // Contrôlé avant d'ouvrir le flux, et non pendant. Le disque est
        // configuré 'throw' => true : une lecture manquante lèverait depuis le
        // callback de la réponse, une fois les en-têtes de succès déjà partis,
        // et le destinataire recevrait un 200 tronqué.
        if (! $disk->fileExists($file->stored_path)) {
            // Niveau error, au-dessus du warning des incidents ordinaires :
            // une ligne vivante sans octets ne se répare pas toute seule. Le
            // chemin n'est pas journalisé, l'identifiant numérique suffit.
            Log::error('Link content missing', ['file_id' => $file->id]);

            throw new LinkContentMissingException;
        }

        return $disk->download($file->stored_path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'Content-Length' => (string) $file->size,
        ]);
    }

    /**
     * Ordre invariant : le physique d'abord, la ligne ensuite — le même sens
     * que la purge (US10), pour la même raison. Aucune transaction : une
     * transaction SQL n'annule pas une suppression de fichier déjà exécutée,
     * et inverser l'ordre laisserait des octets orphelins invisibles à la
     * purge en cas d'échec entre les deux étapes.
     */
    public function delete(File $file): void
    {
        $disk = Storage::disk((string) config('datashare.uploads.disk'));

        if (! $disk->fileExists($file->stored_path)) {
            Log::warning('File content already missing', ['file_id' => $file->id]);
        } else {
            $disk->delete($file->stored_path);
        }

        $file->delete();
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
