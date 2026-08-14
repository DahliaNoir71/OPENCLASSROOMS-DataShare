<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_schema_est_migre(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_deux_emails_ne_differant_que_par_la_casse_sont_refuses_en_base(): void
    {
        DB::table('users')->insert([
            'email' => 'jane.doe@example.com',
            'password' => 'peu-importe',
        ]);

        $this->expectException(QueryException::class);

        // Volontairement via le query builder : le mutateur du modèle User
        // normaliserait l'email et l'index ne serait jamais sollicité.
        DB::table('users')->insert([
            'email' => 'Jane.DOE@Example.com',
            'password' => 'peu-importe',
        ]);
    }
}
