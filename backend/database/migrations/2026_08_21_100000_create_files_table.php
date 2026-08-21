<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Identifiant candidat exposé publiquement dans les liens de
            // partage : jamais la clé auto-incrémentée (docs/mcd.md). 32
            // caractères de marge pour un token généré à 22 (config/datashare.php).
            $table->string('token', 32)->unique();

            $table->string('original_name');

            // Chemin relatif complet sur le disque dédié, pas seulement un nom
            // de fichier : l'arborescence de stockage partitionne par date
            // (AAAA/MM/JJ/uuid), donc la seule façon auto-portante de retrouver
            // un fichier est de conserver le chemin qui a servi à l'écrire.
            $table->string('stored_path');

            $table->string('mime_type', 191);

            // bigint plutôt que int : la limite de 1 Go tient dans un entier
            // signé 32 bits, mais bigint évite une migration si le plafond
            // métier évolue un jour.
            $table->unsignedBigInteger('size');

            // Nullable : la protection par mot de passe est optionnelle
            // (US09). Hachée par le cast 'hashed' du modèle, jamais en clair.
            $table->string('password')->nullable();

            $table->timestamp('expires_at');

            $table->timestamps();

            // Balayage quotidien des fichiers expirés (US10).
            $table->index('expires_at');

            // user_id en préfixe pour couvrir à la fois la clé étrangère —
            // PostgreSQL, contrairement à MySQL, n'indexe pas automatiquement
            // une colonne de FK — et le listing « mes fichiers », trié par
            // date de dépôt (US05).
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
