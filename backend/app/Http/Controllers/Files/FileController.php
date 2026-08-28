<?php

namespace App\Http\Controllers\Files;

use App\Exceptions\FileNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Files\ListFilesRequest;
use App\Http\Requests\Files\UploadFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class FileController extends Controller
{
    /**
     * Historique des fichiers de l'utilisateur connecté (US05).
     *
     * `$request->user()->files()` porte le `WHERE user_id` par construction —
     * jamais un `where` recopié à la main. Le départage par `id` après
     * `created_at` n'est pas cosmétique : deux dépôts dans la même seconde
     * partageraient sinon le même horodatage, avec un ordre non déterministe
     * qui rendrait la pagination instable (une ligne pourrait réapparaître
     * d'une page à l'autre). `withQueryString()` conserve `status` et
     * `per_page` dans les liens `meta`/`links` — sans lui, suivre `next`
     * perdrait silencieusement les deux et reviendrait aux valeurs par défaut.
     */
    public function index(ListFilesRequest $request): AnonymousResourceCollection
    {
        $status = $request->validated('status') ?? 'all';
        $perPage = $request->validated('per_page') ?? (int) config('datashare.history.per_page');

        $files = $request->user()->files()
            ->when($status !== 'all', fn ($query) => $query->{$status}())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return FileResource::collection($files);
    }

    public function store(UploadFileRequest $request, FileStorageService $files): JsonResponse
    {
        $start = hrtime(true);

        // $request->integer() ne retombe sur son défaut que si la clé est
        // ABSENTE ; une clé présente mais vide ("" -> null par
        // ConvertEmptyStringsToNull, ou null explicite en JSON) donne
        // (int) null === 0 ci-dessous, d'où la lecture explicite. min() borne
        // le défaut effectif par le plafond : rien ne garantit sinon
        // default_expiry_days <= max_expiry_days en configuration.
        $defaultExpiryDays = min(
            (int) config('datashare.uploads.default_expiry_days'),
            (int) config('datashare.uploads.max_expiry_days'),
        );

        $file = $files->store(
            $request->user(),
            $request->file('file'),
            $request->input('password'),
            (int) ($request->input('expires_in_days') ?? $defaultExpiryDays),
        );

        // Piste d'audit (docs/architecture.md) : identifiants numériques
        // uniquement — ni le nom d'origine, ni le token, ni le mot de passe.
        // duration_ms et route (A8) : mesure du traitement métier du
        // contrôleur pour une campagne de charge, jamais le chemin résolu.
        Log::info('File uploaded', [
            'user_id' => $request->user()->id,
            'file_id' => $file->id,
            'size' => $file->size,
            'protected' => $file->isProtected(),
            'duration_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
            'route' => $request->route()?->uri(),
        ]);

        return FileResource::make($file)->response()->setStatusCode(201);
    }

    /**
     * Résolution en deux temps (docs/architecture.md, arbitrage B1) plutôt
     * que le model binding de route : `File::find($id)` distingue
     * délibérément « ligne absente » de « ligne d'un autre compte » en
     * interne, pour journaliser le second cas, mais les deux ressortent par
     * le même `FileNotFoundException` — un identifiant non numérique suit le
     * même chemin. Le model binding produirait une `ModelNotFoundException`
     * au message anglais nommant le modèle, que ce contrôleur veut
     * précisément éviter.
     *
     * `ctype_digit` avant l'appel à `find()` : la colonne `id` est un
     * `bigint`, et PostgreSQL refuse de comparer un `bigint` à une chaîne
     * qui n'en est pas un — contrairement à SQLite, dont le typage dynamique
     * ne trouve simplement aucune ligne. Sans ce garde-fou, un identifiant
     * comme `abc` levait une `QueryException` (500) au lieu du `404` que ce
     * contrôleur garantit pour tout identifiant qui ne désigne aucune ligne.
     *
     * @throws FileNotFoundException
     */
    public function destroy(Request $request, int|string $id, FileStorageService $files): Response
    {
        $file = ctype_digit((string) $id) ? File::find($id) : null;

        if ($file === null) {
            throw new FileNotFoundException;
        }

        if ((int) $file->user_id !== $request->user()->id) {
            Log::warning('File deletion refused', [
                'user_id' => $request->user()->id,
                'file_id' => $file->id,
            ]);

            throw new FileNotFoundException;
        }

        $files->delete($file);

        Log::info('File deleted', [
            'user_id' => $request->user()->id,
            'file_id' => $file->id,
        ]);

        return response()->noContent();
    }
}
