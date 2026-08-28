<?php

namespace Tests\Feature\Logging;

use App\Logging\UseJsonFormatter;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Le tap s'observe uniquement là où un handler écrit réellement : ce test
 * pointe un canal 'single' éphémère vers un fichier temporaire, plutôt que
 * vers storage/logs/laravel.log, pour ne pas polluer les journaux du projet.
 */
class JsonFormatterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/datashare-json-formatter-test-'.uniqid().'.log';

        config(['logging.channels.test_json' => [
            'driver' => 'single',
            'path' => $this->path,
            'level' => 'debug',
            'tap' => [UseJsonFormatter::class],
        ]]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_a_tapped_channel_writes_valid_json_with_message_and_context(): void
    {
        Log::channel('test_json')->info('Test line', ['file_id' => 42]);

        $line = trim(file_get_contents($this->path));
        $decoded = json_decode($line, true);

        $this->assertNotNull($decoded, 'La ligne écrite doit être du JSON valide.');
        $this->assertSame('Test line', $decoded['message']);
        $this->assertSame(['file_id' => 42], $decoded['context']);
    }
}
