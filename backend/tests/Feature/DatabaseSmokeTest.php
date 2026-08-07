<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_schema_est_migre(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
    }
}
