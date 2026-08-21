<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Http\Requests\Files\UploadFileRequest;
use App\Http\Resources\FileResource;
use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class FileController extends Controller
{
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
