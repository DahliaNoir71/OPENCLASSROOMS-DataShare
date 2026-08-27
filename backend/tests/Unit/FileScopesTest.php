<?php

namespace Tests\Unit;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_active_and_expired_scopes_partition_the_rows(): void
    {
        $active = File::factory()->count(2)->create();
        $expired = File::factory()->expired()->count(3)->create();

        $activeIds = File::active()->pluck('id')->sort()->values();
        $expiredIds = File::expired()->pluck('id')->sort()->values();

        $this->assertSame($active->pluck('id')->sort()->values()->all(), $activeIds->all());
        $this->assertSame($expired->pluck('id')->sort()->values()->all(), $expiredIds->all());
        $this->assertCount(0, $activeIds->intersect($expiredIds));
        $this->assertCount(5, $activeIds->merge($expiredIds));
    }

    /**
     * `startOfSecond()` : SQLite comme PostgreSQL relisent la colonne au
     * format de date du modèle, sans les microsecondes de l'instance en
     * mémoire — sans lui la comparaison porterait sur deux valeurs distinctes
     * après l'aller-retour en base (même précaution que LinkMetadataTest).
     *
     * Le temps est figé pour la création ET pour l'assertion : le scope SQL
     * et `isExpired()` doivent tous deux résoudre `now()` au même instant
     * pour que leur accord à la frontière soit prouvé, et non un artefact du
     * temps qui s'écoule entre les deux évaluations.
     */
    public function test_the_active_scope_and_is_expired_agree_at_the_exact_expiry_instant(): void
    {
        $instant = now()->startOfSecond();

        $file = $this->travelTo(
            $instant,
            fn () => File::factory()->create(['expires_at' => $instant]),
        );

        $this->travelTo($instant, function () use ($file): void {
            $this->assertFalse($file->fresh()->isExpired());
            $this->assertTrue(File::active()->whereKey($file->id)->exists());
            $this->assertFalse(File::expired()->whereKey($file->id)->exists());
        });
    }
}
