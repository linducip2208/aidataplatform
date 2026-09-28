<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Shared test bootstrap.
 *
 * `phpunit.xml` intentionally leaves `DB_CONNECTION` / `DB_DATABASE` unset, so
 * the test environment is pinned here instead of in the XML file: the suite
 * always runs against an in-memory sqlite database and never touches
 * `database/database.sqlite`. The AI engine client is also pointed at a fake
 * base URL with a service key so `AiEngineClient::isConfigured()` is true by
 * default; tests that need the "not configured" path override it locally.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * `@vite` throws when `public/build/manifest.json` is absent, which is the
     * normal state of a checkout that has not run `npm run build`. Stub the
     * manifest so view tests assert markup, not the asset pipeline.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->configureTestEnvironment($app);

        return $app;
    }

    protected function configureTestEnvironment(Application $app): void
    {
        $config = $app->make('config');

        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite.driver', 'sqlite');
        $config->set('database.connections.sqlite.database', ':memory:');
        $config->set('database.connections.sqlite.prefix', '');
        $config->set('database.connections.sqlite.foreign_key_constraints', true);

        $config->set('ai_engine.base_url', 'http://fastapi.test');
        $config->set('ai_engine.service_key', 'test-service-key');
        $config->set('ai_engine.service_key_header', 'X-Service-Key');

        $config->set('filesystems.default', 'local');
    }
}
