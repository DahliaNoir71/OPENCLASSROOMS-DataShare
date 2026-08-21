<?php

namespace Tests\Feature\Links;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    private const BYTES = 'contenu du fichier partage';

    private const PASSWORD = 'secret123';

    private const INVALID = 'Ce lien de téléchargement est invalide.';

    private const EXPIRED = "Ce lien a expiré : le fichier n'est plus disponible.";

    private const WRONG_PASSWORD = 'Mot de passe incorrect.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    private function withBytes(File $file): File
    {
        Storage::disk('uploads')->put($file->stored_path, self::BYTES);

        return $file;
    }

    private function activeFile(array $attributes = []): File
    {
        return $this->withBytes(File::factory()->create($attributes + [
            'size' => strlen(self::BYTES),
        ]));
    }

    private function protectedFile(array $attributes = []): File
    {
        return $this->withBytes(File::factory()->protected(self::PASSWORD)->create($attributes + [
            'size' => strlen(self::BYTES),
        ]));
    }

    private function url(File $file): string
    {
        return "/api/links/{$file->token}/download";
    }

    public function test_download_of_an_unprotected_link_returns_the_file_bytes(): void
    {
        $file = $this->activeFile();

        $response = $this->postJson($this->url($file));

        $response->assertOk();
        $this->assertSame(self::BYTES, $response->streamedContent());
    }

    public function test_download_sets_content_disposition_content_type_and_content_length(): void
    {
        $file = $this->activeFile([
            'original_name' => 'rapport-annuel.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->postJson($this->url($file));

        $response->assertOk();
        $response->assertDownload('rapport-annuel.pdf');
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('Content-Length', (string) strlen(self::BYTES));
    }

    /**
     * Le fichier physique est un UUID sans extension et son contenu est du
     * texte : une détection par `finfo` annoncerait autre chose. C'est la
     * colonne qui fait foi, celle-là même qui a alimenté les métadonnées
     * annoncées juste avant au même destinataire.
     */
    public function test_download_advertises_the_stored_mime_type_not_a_detected_one(): void
    {
        $file = $this->activeFile([
            'original_name' => 'capture.png',
            'mime_type' => 'image/png',
        ]);

        $this->postJson($this->url($file))->assertHeader('Content-Type', 'image/png');
    }

    /**
     * Forme double de la RFC 6266 : le repli ASCII pour les agents anciens, et
     * `filename*` pour le nom réel. C'est par cet en-tête, et non par le corps,
     * que le nom d'origine remonte au destinataire.
     */
    public function test_download_preserves_a_utf8_original_name_in_content_disposition(): void
    {
        $file = $this->activeFile(['original_name' => 'rapport-été.pdf']);

        $this->postJson($this->url($file))->assertHeader(
            'Content-Disposition',
            "attachment; filename=rapport-ete.pdf; filename*=utf-8''rapport-%C3%A9t%C3%A9.pdf",
        );
    }

    public function test_download_of_a_protected_link_with_the_right_password_returns_the_bytes(): void
    {
        $file = $this->protectedFile();

        $response = $this->postJson($this->url($file), ['password' => self::PASSWORD]);

        $response->assertOk();
        $this->assertSame(self::BYTES, $response->streamedContent());
    }

    public function test_download_of_a_protected_link_with_a_wrong_password_returns_401(): void
    {
        $file = $this->protectedFile();

        $response = $this->postJson($this->url($file), ['password' => 'mauvais-mot-de-passe']);

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => self::WRONG_PASSWORD]);
    }

    /**
     * Même code et même corps que le mot de passe faux : c'est un unique échec
     * de vérification, et le front connaît `protected` avant d'afficher le
     * champ.
     */
    public function test_download_of_a_protected_link_without_a_password_returns_401(): void
    {
        $file = $this->protectedFile();

        $response = $this->postJson($this->url($file));

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => self::WRONG_PASSWORD]);
    }

    public function test_download_of_an_unprotected_link_ignores_a_supplied_password(): void
    {
        $file = $this->activeFile();

        $response = $this->postJson($this->url($file), ['password' => 'valeur-perimee']);

        $response->assertOk();
        $this->assertSame(self::BYTES, $response->streamedContent());
    }

    public function test_download_of_an_expired_link_returns_410(): void
    {
        $file = $this->withBytes(File::factory()->expired()->create());

        $response = $this->postJson($this->url($file));

        $response->assertGone();
        $response->assertExactJson(['message' => self::EXPIRED]);
    }

    /**
     * L'ordre des contrôles se lit ici : un mot de passe faux sur un lien échu
     * ressort en 410, pas en 401. Inutile de faire calculer un bcrypt à qui
     * n'obtiendra rien.
     */
    public function test_download_of_an_expired_protected_link_returns_410_without_checking_the_password(): void
    {
        $file = $this->withBytes(
            File::factory()->expired()->protected(self::PASSWORD)->create()
        );

        $response = $this->postJson($this->url($file), ['password' => 'mauvais-mot-de-passe']);

        $response->assertGone();
        $response->assertExactJson(['message' => self::EXPIRED]);
    }

    public function test_download_of_an_unknown_token_returns_404(): void
    {
        $response = $this->postJson('/api/links/'.Str::random(22).'/download');

        $response->assertNotFound();
        $response->assertExactJson(['message' => self::INVALID]);
    }

    /**
     * Ligne vivante, octets disparus : purge interrompue ou intervention
     * manuelle. Le contrôle a lieu avant l'ouverture du flux, sans quoi le
     * destinataire recevrait un 200 tronqué.
     */
    public function test_download_when_the_file_is_missing_from_disk_returns_410(): void
    {
        $file = File::factory()->create();

        $response = $this->postJson($this->url($file));

        $response->assertGone();
        $response->assertExactJson(['message' => self::EXPIRED]);
    }

    public function test_download_with_a_non_string_password_returns_422(): void
    {
        $file = $this->protectedFile();

        $response = $this->postJson($this->url($file), ['password' => ['un', 'tableau']]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_download_with_a_password_over_72_characters_returns_422(): void
    {
        $file = $this->protectedFile();

        $response = $this->postJson($this->url($file), ['password' => str_repeat('a', 73)]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_download_requires_no_authentication(): void
    {
        $file = $this->activeFile();

        $this->postJson($this->url($file))->assertOk();
    }

    public function test_download_response_is_not_storable(): void
    {
        $file = $this->activeFile();

        $this->postJson($this->url($file))
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Le contrat n'ouvre qu'une méthode : le mot de passe transite dans le
     * corps, jamais dans l'URL, et il n'existe pas de variante GET pour les
     * fichiers non protégés.
     */
    public function test_download_by_get_is_not_allowed(): void
    {
        $file = $this->activeFile();

        $this->getJson($this->url($file))->assertStatus(405);
    }

    public function test_download_is_throttled_after_ten_attempts_on_the_same_link(): void
    {
        $file = $this->activeFile();

        foreach (range(1, 10) as $ignored) {
            $this->postJson($this->url($file))->assertOk();
        }

        $response = $this->postJson($this->url($file));

        $response->assertTooManyRequests();
        $response->assertHeader('Retry-After');
        // Le corps français prouve que le callback de réponse est bien porté
        // par la limite qui a sauté, et non par le limiteur.
        $response->assertExactJson([
            'message' => 'Trop de requêtes. Réessayez dans quelques instants.',
        ]);
    }

    /**
     * Le compteur du second lien est intact après saturation du premier : la
     * clé de la limite inclut le token.
     */
    public function test_download_attempts_on_two_different_links_are_counted_separately(): void
    {
        $first = $this->activeFile();
        $second = $this->activeFile();

        foreach (range(1, 10) as $ignored) {
            $this->postJson($this->url($first))->assertOk();
        }

        $this->postJson($this->url($first))->assertTooManyRequests();

        $this->postJson($this->url($second))->assertOk();
    }
}
