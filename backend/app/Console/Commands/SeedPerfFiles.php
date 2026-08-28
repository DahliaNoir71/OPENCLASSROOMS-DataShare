<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\User;
use App\Services\FileStorageService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Outillage de campagne (P12b), hors du chemin applicatif : crée ou détruit
 * les fichiers distincts que la campagne k6 télécharge en boucle. Passe par
 * FileStorageService::store()/delete() plutôt que par des écritures directes,
 * pour que chaque fichier seedé ait de vrais octets sur le disque `uploads`
 * et un vrai token — exactement ce que le chemin de téléchargement va lire.
 */
class SeedPerfFiles extends Command
{
    protected $signature = 'files:seed-perf
        {count=100 : Nombre de fichiers distincts à créer}
        {--size=2 : Taille de chaque fichier, en mégaoctets}
        {--cleanup : Supprime les fichiers de la campagne précédente au lieu d\'en créer}';

    protected $description = 'Seed the distinct files used by the k6 perf campaign, or clean them up with --cleanup';

    private const PERF_USER_EMAIL = 'perf-campaign@datashare.local';

    public function handle(FileStorageService $files): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup($files);
        }

        return $this->seed($files);
    }

    private function seed(FileStorageService $files): int
    {
        $count = (int) $this->argument('count');
        $megabytes = (int) $this->option('size');

        $user = User::firstOrCreate(
            ['email' => self::PERF_USER_EMAIL],
            ['password' => Hash::make(Str::random(40))],
        );

        $tokens = [];

        for ($i = 0; $i < $count; $i++) {
            $upload = $this->randomUpload($megabytes);

            // Contenus distincts (random_bytes par fichier) et non protégés :
            // la campagne ne doit exercer que le chemin sans mot de passe.
            // expires_at à un jour : large devant la durée d'une campagne,
            // court devant l'attente d'une purge de production.
            $file = $files->store($user, $upload, null, expiresInDays: 1);

            $tokens[] = $file->token;

            @unlink($upload->getRealPath());
        }

        $this->writeTokens($tokens);

        $this->info(sprintf(
            '%d fichiers de %d Mo créés. Tokens écrits dans %s.',
            $count,
            $megabytes,
            $this->tokensPath(),
        ));

        return self::SUCCESS;
    }

    private function cleanup(FileStorageService $files): int
    {
        $user = User::where('email', self::PERF_USER_EMAIL)->first();

        if ($user === null) {
            $this->info('Aucun utilisateur de campagne trouvé, rien à nettoyer.');

            return self::SUCCESS;
        }

        $deleted = 0;

        // lazyById plutôt qu'un chunk par offset : même raison que
        // files:purge-expired, la suppression rétrécit la table sous le
        // curseur pendant le parcours.
        foreach (File::where('user_id', $user->id)->lazyById() as $file) {
            $files->delete($file);
            $deleted++;
        }

        $user->delete();

        if (is_file($this->tokensPath())) {
            unlink($this->tokensPath());
        }

        $this->info("{$deleted} fichiers de campagne supprimés.");

        return self::SUCCESS;
    }

    private function randomUpload(int $megabytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'perf-');
        file_put_contents($path, random_bytes(max(1, $megabytes) * 1024 * 1024));

        // `test: true` : seul moyen de faire accepter à UploadedFile un
        // chemin qui n'est pas passé par un upload HTTP réel.
        return new UploadedFile($path, Str::uuid().'.bin', 'application/octet-stream', null, true);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function writeTokens(array $tokens): void
    {
        $directory = dirname($this->tokensPath());

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($this->tokensPath(), json_encode($tokens, JSON_PRETTY_PRINT));
    }

    private function tokensPath(): string
    {
        return base_path('perf/tokens.json');
    }
}
