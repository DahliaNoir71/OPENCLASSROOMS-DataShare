<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Files\FileController;
use App\Http\Controllers\Links\LinkController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok']));

// Open to everyone, hence the strict limiter: these two routes are the ones a
// credential stuffing run would hammer.
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Behind a token, so the general api limiter (60/min, keyed by user id) is the
// right ceiling: 5/min per IP would break a normal session, and several users
// behind one NAT would throttle each other.
Route::prefix('auth')->middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Derrière un jeton comme /auth/me et /auth/logout. L'historique (US05) est un
// appel JSON ordinaire et relève donc du seul plafond général ; le dépôt garde
// son propre plafond, posé sur la route et non plus sur le groupe : chaque
// appel peut transporter jusqu'à 1 Go, un ceiling qui n'a rien à voir avec
// celui d'un simple GET (US01).
Route::prefix('files')->middleware('auth:api')->group(function () {
    Route::get('/', [FileController::class, 'index']);
    Route::post('/', [FileController::class, 'store'])->middleware('throttle:uploads');
});

// Ouvertes à tous comme register et login, mais la menace n'est pas la même :
// ici le secret est dans l'URL. Le GET reste au plafond général — pour un
// appelant anonyme, `api` compte déjà 60 requêtes par minute et par IP, et 22
// caractères base62 rendent le balayage vain de toute façon. Le POST, lui, a
// son propre limiteur : c'est le seul point du service où un mot de passe de
// partage se devine (US02, US09).
//
// Aucune contrainte de route sur {token} : un token mal formé doit ressortir
// avec notre 404 et son message français, pas avec le 404 au corps vide du
// routeur.
Route::prefix('links')->group(function () {
    Route::get('/{token}', [LinkController::class, 'show']);
    Route::post('/{token}/download', [LinkController::class, 'download'])
        ->middleware('throttle:downloads');
});
