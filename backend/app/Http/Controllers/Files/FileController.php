<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Http\Requests\Files\ListFilesRequest;
use App\Http\Requests\Files\UploadFileRequest;
use App\Http\Resources\FileResource;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        $file = $files->store(
            $request->user(),
            $request->file('file'),
            $request->input('password'),
            $request->integer('expires_in_days', (int) config('datashare.uploads.default_expiry_days')),
        );

        // Piste d'audit (docs/architecture.md) : identifiants numériques
        // uniquement — ni le nom d'origine, ni le token, ni le mot de passe.
        Log::info('File uploaded', [
            'user_id' => $request->user()->id,
            'file_id' => $file->id,
            'size' => $file->size,
            'protected' => $file->isProtected(),
        ]);

        return FileResource::make($file)->response()->setStatusCode(201);
    }
}
