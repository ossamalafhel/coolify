<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Project;
use App\Models\Team;
use App\Models\StandaloneDocker;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ServiceWatchPathsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private Project $project;
    private Environment $environment;
    private Server $server;
    private StandaloneDocker $docker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup common test data
        $this->team = Team::create([
            'name' => 'Integration Test Team',
            'personal_team' => false,
        ]);
        
        $this->project = Project::create([
            'name' => 'Integration Test Project',
            'team_id' => $this->team->id,
            'uuid' => 'int-test-project-uuid',
        ]);
        
        $this->environment = Environment::create([
            'name' => 'production',
            'project_id' => $this->project->id,
            'uuid' => 'int-test-env-uuid',
        ]);
        
        $this->server = Server::create([
            'name' => 'Integration Test Server',
            'uuid' => 'int-test-server-uuid',
            'ip' => '192.168.1.100',
            'user' => 'deploy',
            'port' => 22,
            'team_id' => $this->team->id,
        ]);
        
        $this->docker = StandaloneDocker::create([
            'name' => 'Integration Test Docker',
            'uuid' => 'int-test-docker-uuid',
            'server_id' => $this->server->id,
            'network' => 'integration-network',
        ]);
    }

    /**
     * Test complete workflow: Create service, set watch paths, trigger deployment
     */
    public function test_complete_watch_paths_workflow()
    {
        // Step 1: Create a service with Docker Compose configuration
        $dockerCompose = <<<'YAML'
version: '3.8'
services:
  web:
    image: nginx:alpine
    ports:
      - "80:80"
    volumes:
      - ./src:/usr/share/nginx/html
  api:
    image: node:16-alpine
    working_dir: /app
    volumes:
      - ./api:/app
    command: npm start
YAML;

        $service = Service::create([
            'name' => 'full-stack-app',
            'uuid' => 'full-stack-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => $dockerCompose,
            'docker_compose_raw' => $dockerCompose,
        ]);

        // Step 2: Configure watch paths for different components
        $watchPaths = [
            'src/**/*.html',     // Frontend HTML files
            'src/**/*.css',      // Frontend CSS files
            'src/**/*.js',       // Frontend JavaScript
            'api/**/*.js',       // Backend API files
            'api/package.json',  // Backend dependencies
            'docker-compose.yml' // Docker configuration
        ];

        $service->watch_paths = $watchPaths;
        $service->save();

        // Step 3: Simulate file changes and check if deployment should trigger
        
        // Frontend changes should trigger
        $frontendChanges = [
            'src/index.html',
            'src/styles/main.css',
            'src/js/app.js'
        ];
        $this->assertTrue(
            $service->isWatchPathsTriggered($frontendChanges),
            'Frontend changes should trigger deployment'
        );

        // Backend changes should trigger
        $backendChanges = [
            'api/server.js',
            'api/routes/users.js',
            'api/package.json'
        ];
        $this->assertTrue(
            $service->isWatchPathsTriggered($backendChanges),
            'Backend changes should trigger deployment'
        );

        // Docker compose changes should trigger
        $this->assertTrue(
            $service->isWatchPathsTriggered('docker-compose.yml'),
            'Docker compose changes should trigger deployment'
        );

        // Unrelated files should NOT trigger
        $unrelatedChanges = [
            'README.md',
            'docs/documentation.md',
            '.gitignore',
            'tests/unit/test.js'
        ];
        $this->assertFalse(
            $service->isWatchPathsTriggered($unrelatedChanges),
            'Unrelated changes should not trigger deployment'
        );
    }

    /**
     * Test service with multiple applications and their watch paths
     */
    public function test_multi_application_service_watch_paths()
    {
        // Create a service with multiple applications (microservices)
        $service = Service::create([
            'name' => 'microservices-app',
            'uuid' => 'microservices-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
        ]);

        // Create service applications
        $authService = ServiceApplication::create([
            'name' => 'auth-service',
            'uuid' => 'auth-service-uuid',
            'service_id' => $service->id,
            'image' => 'auth-service:latest',
            'fqdn' => 'auth.example.com',
        ]);

        $apiGateway = ServiceApplication::create([
            'name' => 'api-gateway',
            'uuid' => 'api-gateway-uuid',
            'service_id' => $service->id,
            'image' => 'api-gateway:latest',
            'fqdn' => 'api.example.com',
        ]);

        $userService = ServiceApplication::create([
            'name' => 'user-service',
            'uuid' => 'user-service-uuid',
            'service_id' => $service->id,
            'image' => 'user-service:latest',
            'fqdn' => null, // Internal service
        ]);

        // Set watch paths for different microservices
        $service->watch_paths = [
            'services/auth/**/*.js',
            'services/auth/package.json',
            'services/gateway/**/*.js',
            'services/gateway/package.json',
            'services/user/**/*.js',
            'services/user/package.json',
            'shared/**/*.js',
            'docker-compose.yml',
            '.env'
        ];
        $service->save();

        // Test service-specific changes
        $this->assertTrue(
            $service->isWatchPathsTriggered('services/auth/controllers/login.js'),
            'Auth service changes should trigger'
        );

        $this->assertTrue(
            $service->isWatchPathsTriggered('services/gateway/middleware/auth.js'),
            'API Gateway changes should trigger'
        );

        $this->assertTrue(
            $service->isWatchPathsTriggered('services/user/models/User.js'),
            'User service changes should trigger'
        );

        // Shared library changes should trigger
        $this->assertTrue(
            $service->isWatchPathsTriggered('shared/utils/validation.js'),
            'Shared library changes should trigger'
        );

        // Environment changes should trigger
        $this->assertTrue(
            $service->isWatchPathsTriggered('.env'),
            'Environment file changes should trigger'
        );

        // Other service changes should not trigger
        $this->assertFalse(
            $service->isWatchPathsTriggered('services/billing/invoice.js'),
            'Unrelated service changes should not trigger'
        );
    }

    /**
     * Test service with database and watch paths for migrations
     */
    public function test_database_service_watch_paths()
    {
        $service = Service::create([
            'name' => 'app-with-database',
            'uuid' => 'app-db-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
        ]);

        // Create service database
        $database = ServiceDatabase::create([
            'name' => 'postgres-db',
            'uuid' => 'postgres-db-uuid',
            'service_id' => $service->id,
            'image' => 'postgres:14',
        ]);

        // Set watch paths including database migrations
        $service->watch_paths = [
            'app/**/*.php',
            'database/migrations/**/*.sql',
            'database/seeds/**/*.sql',
            'database/schema.sql',
            'config/database.php',
            'docker-compose.yml'
        ];
        $service->save();

        // Database migration changes should trigger
        $this->assertTrue(
            $service->isWatchPathsTriggered('database/migrations/2024_01_01_create_users_table.sql'),
            'Database migrations should trigger deployment'
        );

        $this->assertTrue(
            $service->isWatchPathsTriggered('database/seeds/users_seeder.sql'),
            'Database seeds should trigger deployment'
        );

        $this->assertTrue(
            $service->isWatchPathsTriggered('database/schema.sql'),
            'Schema changes should trigger deployment'
        );

        // Application code changes should trigger
        $this->assertTrue(
            $service->isWatchPathsTriggered('app/models/User.php'),
            'Application code should trigger deployment'
        );

        // Non-database files shouldn't trigger
        $this->assertFalse(
            $service->isWatchPathsTriggered('docs/database-design.md'),
            'Documentation should not trigger deployment'
        );
    }

    /**
     * Test environment-specific watch paths
     */
    public function test_environment_specific_watch_paths()
    {
        // Create production service
        $prodService = Service::create([
            'name' => 'app-production',
            'uuid' => 'app-prod-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => [
                'src/**/*.js',
                'config/production.json',
                'docker-compose.prod.yml'
            ]
        ]);

        // Create staging environment and service
        $stagingEnv = Environment::create([
            'name' => 'staging',
            'project_id' => $this->project->id,
            'uuid' => 'staging-env-uuid',
        ]);

        $stagingService = Service::create([
            'name' => 'app-staging',
            'uuid' => 'app-staging-uuid',
            'environment_id' => $stagingEnv->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => [
                'src/**/*.js',
                'config/staging.json',
                'docker-compose.staging.yml',
                'tests/**/*.test.js'  // Include tests in staging
            ]
        ]);

        // Production should trigger on production config
        $this->assertTrue(
            $prodService->isWatchPathsTriggered('config/production.json'),
            'Production config should trigger production deployment'
        );

        $this->assertFalse(
            $prodService->isWatchPathsTriggered('config/staging.json'),
            'Staging config should not trigger production deployment'
        );

        // Staging should trigger on staging config and tests
        $this->assertTrue(
            $stagingService->isWatchPathsTriggered('config/staging.json'),
            'Staging config should trigger staging deployment'
        );

        $this->assertTrue(
            $stagingService->isWatchPathsTriggered('tests/unit/auth.test.js'),
            'Tests should trigger staging deployment'
        );

        $this->assertFalse(
            $stagingService->isWatchPathsTriggered('config/production.json'),
            'Production config should not trigger staging deployment'
        );

        // Both should trigger on source code changes
        $this->assertTrue(
            $prodService->isWatchPathsTriggered('src/app.js'),
            'Source code should trigger production deployment'
        );

        $this->assertTrue(
            $stagingService->isWatchPathsTriggered('src/app.js'),
            'Source code should trigger staging deployment'
        );
    }

    /**
     * Test watch paths with environment variables
     */
    public function test_watch_paths_with_environment_variables()
    {
        $service = Service::create([
            'name' => 'app-with-env',
            'uuid' => 'app-env-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => [
                'src/**/*.js',
                '.env',
                '.env.production',
                'config/**/*.json'
            ]
        ]);

        // Create environment variables
        EnvironmentVariable::create([
            'key' => 'DATABASE_URL',
            'value' => 'postgresql://user:pass@localhost/db',
            'is_build_time' => false,
            'resourceable_id' => $service->id,
            'resourceable_type' => Service::class,
            'is_preview' => false,
        ]);

        EnvironmentVariable::create([
            'key' => 'API_KEY',
            'value' => 'secret-api-key',
            'is_build_time' => true,
            'resourceable_id' => $service->id,
            'resourceable_type' => Service::class,
            'is_preview' => false,
        ]);

        // Environment file changes should trigger
        $this->assertTrue(
            $service->isWatchPathsTriggered('.env'),
            'Main env file should trigger deployment'
        );

        $this->assertTrue(
            $service->isWatchPathsTriggered('.env.production'),
            'Production env file should trigger deployment'
        );

        // Config changes should trigger
        $this->assertTrue(
            $service->isWatchPathsTriggered('config/database.json'),
            'Config files should trigger deployment'
        );

        // Other env files not in watch list shouldn't trigger
        $this->assertFalse(
            $service->isWatchPathsTriggered('.env.development'),
            'Development env file should not trigger deployment'
        );
    }

    /**
     * Test performance with real-world scenario
     */
    public function test_performance_with_realistic_file_changes()
    {
        $service = Service::create([
            'name' => 'large-monorepo',
            'uuid' => 'monorepo-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => [
                'packages/web/**/*.{js,jsx,ts,tsx}',
                'packages/web/**/*.css',
                'packages/api/**/*.{js,ts}',
                'packages/shared/**/*.{js,ts}',
                'packages/admin/**/*.{js,jsx,ts,tsx}',
                'infrastructure/**/*.tf',
                'infrastructure/**/*.yml',
                '.github/workflows/*.yml',
                'docker/**/*',
                'scripts/**/*.sh',
                'package.json',
                'yarn.lock',
                'lerna.json'
            ]
        ]);

        // Simulate a typical commit with multiple file changes
        $commitChanges = [
            'packages/web/src/components/Header.jsx',
            'packages/web/src/components/Footer.jsx',
            'packages/web/src/styles/components.css',
            'packages/api/src/controllers/UserController.js',
            'packages/api/src/models/User.js',
            'packages/shared/utils/validation.js',
            'packages/web/src/tests/Header.test.jsx',
            'README.md',
            'docs/API.md'
        ];

        $startTime = microtime(true);
        $shouldTrigger = $service->isWatchPathsTriggered($commitChanges);
        $executionTime = microtime(true) - $startTime;

        // Should trigger because of the code changes
        $this->assertTrue($shouldTrigger, 'Monorepo changes should trigger deployment');

        // Performance check - should complete quickly even with complex patterns
        $this->assertLessThan(
            0.1, // 100ms threshold
            $executionTime,
            "Pattern matching took {$executionTime} seconds, which is too slow"
        );

        // Test with only non-matching files
        $nonMatchingChanges = [
            'README.md',
            'docs/CONTRIBUTING.md',
            'LICENSE',
            '.gitignore',
            'packages/experimental/test.js'  // Not in watch paths
        ];

        $this->assertFalse(
            $service->isWatchPathsTriggered($nonMatchingChanges),
            'Non-matching files should not trigger deployment'
        );
    }

    /**
     * Test watch paths update and persistence
     */
    public function test_watch_paths_update_and_persistence()
    {
        $service = Service::create([
            'name' => 'evolving-app',
            'uuid' => 'evolving-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => ['src/**/*.js']
        ]);

        // Initial state
        $this->assertTrue($service->isWatchPathsTriggered('src/app.js'));
        $this->assertFalse($service->isWatchPathsTriggered('tests/app.test.js'));

        // Update watch paths to include tests
        $service->watch_paths = array_merge($service->watch_paths, ['tests/**/*.test.js']);
        $service->save();

        // Reload from database
        $updatedService = Service::find($service->id);

        // Verify update persisted
        $this->assertContains('src/**/*.js', $updatedService->watch_paths);
        $this->assertContains('tests/**/*.test.js', $updatedService->watch_paths);

        // Test new behavior
        $this->assertTrue($updatedService->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($updatedService->isWatchPathsTriggered('tests/app.test.js'));

        // Clear watch paths
        $updatedService->watch_paths = [];
        $updatedService->save();

        $clearedService = Service::find($service->id);
        $this->assertEmpty($clearedService->watch_paths);
        $this->assertFalse($clearedService->isWatchPathsTriggered('src/app.js'));
    }

    /**
     * Test transaction rollback behavior
     */
    public function test_transaction_rollback_with_watch_paths()
    {
        $service = Service::create([
            'name' => 'transaction-test',
            'uuid' => 'transaction-uuid',
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $this->docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => ['original/**/*.js']
        ]);

        try {
            DB::beginTransaction();
            
            // Update watch paths within transaction
            $service->watch_paths = ['updated/**/*.js'];
            $service->save();
            
            // Verify change within transaction
            $this->assertEquals(['updated/**/*.js'], $service->watch_paths);
            
            // Force rollback
            DB::rollback();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        // Reload service after rollback
        $rolledBackService = Service::find($service->id);
        
        // Should have original watch paths
        $this->assertEquals(['original/**/*.js'], $rolledBackService->watch_paths);
        $this->assertTrue($rolledBackService->isWatchPathsTriggered('original/app.js'));
        $this->assertFalse($rolledBackService->isWatchPathsTriggered('updated/app.js'));
    }
}