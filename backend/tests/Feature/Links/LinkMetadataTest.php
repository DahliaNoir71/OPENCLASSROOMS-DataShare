<?php

namespace Tests\Feature\Links;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LinkMetadataTest extends TestCase
{
    use RefreshDatabase;

    private const BYTES = 'contenu du fichier partage';

    private const INVALID = 'Ce lien de téléchargement est invalide.';

    private const EXPIRED = "Ce lien a expiré : le fichier n'est plus disponible.";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    /**
     * Dépose les octets à l'emplacement que la ligne déclare. Les tests qui
     * appellent délibérément sans passer par là vérifient le cas inverse : une
     * ligne vivante dont le contenu physique a disparu.
     */
    private function withBytes(File $file): File
    {
        Storage::disk('uploads')->put($file->stored_path, self::BYTES);

        return $file;
    }

    /**
     * `startOfSecond()` parce que SQLite comme PostgreSQL relisent la colonne
     * au format de date du modèle : sans lui, les microsecondes de l'instance
     * en mémoire ne survivraient pas à l'aller-retour et la comparaison de
     * `expires_at` porterait sur deux valeurs différentes.
     */
    private function activeFile(array $attributes = []): File
    {
        return $this->withBytes(File::factory()->create($attributes + [
            'size' => strlen(self::BYTES),
            'expires_at' => now()->addDays(3)->startOfSecond(),
        ]));
    }

    public function test_metadata_of_a_valid_link_returns_the_five_contract_fields(): void
    {
        $file = $this->activeFile([
            'original_name' => 'rapport-annuel.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->getJson("/api/links/{$file->token}");

        $response->assertOk();
        $response->assertExactJson([
            'original_name' => 'rapport-annuel.pdf',
            'size' => strlen(self::BYTES),
            'mime_type' => 'application/pdf',
            'protected' => false,
            'expires_at' => $file->expires_at->toJSON(),
        ]);
    }

    /**
     * Le contrat décrit LinkMetadata à plat, à la différence d'UploadResponse :
     * c'est le `$wrap = null` de la ressource qui le garantit.
     */
    public function test_metadata_is_not_wrapped_in_a_data_key(): void
    {
        $file = $this->activeFile(['original_name' => 'notes.txt']);

        $response = $this->getJson("/api/links/{$file->token}");

        $response->assertOk();
        $response->assertJsonMissingPath('data');
        $response->assertJsonPath('original_name', 'notes.txt');
    }

    public function test_metadata_of_a_protected_link_reports_protected_true(): void
    {
        $file = $this->withBytes(File::factory()->protected()->create());

        $response = $this->getJson("/api/links/{$file->token}");

        $response->assertOk();
        $response->assertJsonPath('protected', true);
    }

    public function test_metadata_never_exposes_the_id_the_token_the_stored_path_or_the_owner(): void
    {
        $file = $this->activeFile();

        $response = $this->getJson("/api/links/{$file->token}");

        $response->assertOk();
        $this->assertSame(
            ['original_name', 'size', 'mime_type', 'protected', 'expires_at'],
            array_keys($response->json()),
        );

        $body = $response->getContent();
        $this->assertStringNotContainsString($file->token, $body);
        $this->assertStringNotContainsString($file->stored_path, $body);
        $this->assertStringNotContainsString($file->user->email, $body);
    }

    public function test_metadata_of_an_expired_link_returns_410_with_the_expiry_message(): void
    {
        $file = $this->withBytes(File::factory()->expired()->create());

        $response = $this->getJson("/api/links/{$file->token}");

        $response->assertGone();
        $response->assertExactJson(['message' => self::EXPIRED]);
    }

    public function test_metadata_of_an_unknown_token_returns_404_with_the_invalid_message(): void
    {
        $response = $this->getJson('/api/links/'.Str::random(22));

        $response->assertNotFound();
        $response->assertExactJson(['message' => self::INVALID]);
    }

    /**
     * Deux sondes du même état, parce qu'aucune contrainte de route ne filtre
     * le paramètre : un token hors du jeu base62, et un token plus long que la
     * colonne — la comparaison SQL ne doit pas lever pour autant. Les deux
     * doivent ressortir avec notre 404, pas avec le 404 au corps vide du
     * routeur.
     */
    public function test_metadata_of_a_malformed_token_returns_404(): void
    {
        $this->getJson('/api/links/jeton_invalide-42')
            ->assertNotFound()
            ->assertExactJson(['message' => self::INVALID]);

        $this->getJson('/api/links/'.str_repeat('a', 200))
            ->assertNotFound()
            ->assertExactJson(['message' => self::INVALID]);
    }

    public function test_metadata_requires_no_authentication(): void
    {
        $file = $this->activeFile();

        $this->getJson("/api/links/{$file->token}")->assertOk();
    }

    /**
     * Verrouille l'arbitrage : cet endpoint ne consulte pas le disque. Une
     * entrée-sortie de plus par affichage — une requête de métadonnées sur un
     * driver distant — pour un état que le téléchargement revérifie.
     */
    public function test_metadata_does_not_hit_the_disk_when_the_file_is_missing(): void
    {
        $file = File::factory()->create();

        $this->getJson("/api/links/{$file->token}")->assertOk();

        $this->assertEmpty(Storage::disk('uploads')->allFiles());
    }

    public function test_metadata_response_is_not_storable(): void
    {
        $file = $this->activeFile();

        $this->getJson("/api/links/{$file->token}")
            ->assertHeader('Cache-Control', 'no-store, private');

        // Les erreurs aussi : elles remontent par le chemin de retour du
        // middleware, préfixé au groupe api.
        $this->getJson('/api/links/'.Str::random(22))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_metadata_is_capped_by_the_general_api_limiter(): void
    {
        $file = $this->activeFile();

        foreach (range(1, 60) as $ignored) {
            $this->getJson("/api/links/{$file->token}")->assertOk();
        }

        $response = $this->getJson("/api/links/{$file->token}");

        $response->assertTooManyRequests();
        $response->assertHeader('Retry-After');
    }
}
