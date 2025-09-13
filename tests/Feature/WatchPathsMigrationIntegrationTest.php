<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use App\Models\Service;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Project;
use App\Models\Team;
use App\Models\StandaloneDocker;
use App\Models\User;

class WatchPathsMigrationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private $migrationPath = '2025_01_13_000000_add_watch_paths_to_services';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a user for authentication if needed
        $this->user = User::factory()->create();
    }

    /**
     * Test complete migration workflow with real database
     */
    public function test_complete_migration_workflow()
    {
        // Setup initial data
        $team = Team::create([
            'name' => 'Integration Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Integration Test Project',
            'team_id' => $team->id,
            'uuid' => 'integration-test-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'integration-test-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Integration Test Server',
            'uuid' => 'integration-test-server-uuid',
            'ip' => '192.168.1.100',
            'user' => 'deploy',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Integration Test Docker',
            'uuid' => 'integration-test-docker-uuid',
            'server_id' => $server->id,
            'network' => 'integration-network',
        ]);

        // Verify column exists (from migration)
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));

        // Create service with watch_paths
        $service = Service::create([
            'name' => 'integration-test-service',
            'uuid' => 'integration-test-service-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"\nservices:\n  app:\n    image: nginx:latest',
            'watch_paths' => json_encode([
                'src/**/*.js',
                'config/**/*.json',
                'tests/**/*.test.js'
            ]),
        ]);

        // Verify service was created
        $this->assertNotNull($service->id);
        $this->assertNotNull($service->watch_paths);

        // Retrieve and verify
        $savedService = Service::find($service->id);
        $this->assertEquals($service->watch_paths, $savedService->watch_paths);

        // Update watch_paths
        $newPaths = json_encode([
            'src/**/*.ts',
            'lib/**/*.js',
            'package.json'
        ]);
        $savedService->watch_paths = $newPaths;
        $savedService->save();

        // Verify update
        $updatedService = Service::find($service->id);
        $this->assertEquals($newPaths, $updatedService->watch_paths);

        // Test null value
        $updatedService->watch_paths = null;
        $updatedService->save();

        $nullService = Service::find($service->id);
        $this->assertNull($nullService->watch_paths);
    }

    /**
     * Test migration with database transactions
     */
    public function test_migration_with_database_transactions()
    {
        DB::beginTransaction();

        try {
            $team = Team::create([
                'name' => 'Transaction Test Team',
                'personal_team' => false,
            ]);
            
            $project = Project::create([
                'name' => 'Transaction Test Project',
                'team_id' => $team->id,
                'uuid' => 'transaction-project-uuid',
            ]);
            
            $environment = Environment::create([
                'name' => 'staging',
                'project_id' => $project->id,
                'uuid' => 'transaction-env-uuid',
            ]);
            
            $server = Server::create([
                'name' => 'Transaction Test Server',
                'uuid' => 'transaction-server-uuid',
                'ip' => '10.0.0.1',
                'user' => 'root',
                'port' => 22,
                'team_id' => $team->id,
            ]);
            
            $docker = StandaloneDocker::create([
                'name' => 'Transaction Test Docker',
                'uuid' => 'transaction-docker-uuid',
                'server_id' => $server->id,
                'network' => 'transaction-network',
            ]);

            $service = Service::create([
                'name' => 'transaction-test-service',
                'uuid' => 'transaction-test-service-uuid',
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'destination_id' => $docker->id,
                'destination_type' => StandaloneDocker::class,
                'docker_compose' => 'version: "3"',
                'watch_paths' => json_encode(['**/*.js']),
            ]);

            $this->assertNotNull($service->id);
            $this->assertNotNull($service->watch_paths);

            DB::commit();

            // Verify data persisted after commit
            $persistedService = Service::find($service->id);
            $this->assertNotNull($persistedService);
            $this->assertEquals(json_encode(['**/*.js']), $persistedService->watch_paths);

        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }
    }

    /**
     * Test migration with multiple services
     */
    public function test_migration_with_multiple_services()
    {
        $team = Team::create([
            'name' => 'Multi Service Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Multi Service Project',
            'team_id' => $team->id,
            'uuid' => 'multi-service-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'development',
            'project_id' => $project->id,
            'uuid' => 'multi-service-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Multi Service Server',
            'uuid' => 'multi-service-server-uuid',
            'ip' => '172.16.0.1',
            'user' => 'ubuntu',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Multi Service Docker',
            'uuid' => 'multi-service-docker-uuid',
            'server_id' => $server->id,
            'network' => 'multi-service-network',
        ]);

        $services = [];
        $watchPathsData = [
            ['src/**/*.js', 'tests/**/*.js'],
            ['app/**/*.py', 'tests/**/*.py'],
            ['src/**/*.go', 'pkg/**/*.go', 'cmd/**/*.go'],
            null, // Service without watch_paths
            ['**/*.rs', 'Cargo.toml'],
        ];

        // Create multiple services with different watch_paths
        foreach ($watchPathsData as $index => $paths) {
            $services[] = Service::create([
                'name' => "multi-service-{$index}",
                'uuid' => "multi-service-uuid-{$index}",
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'destination_id' => $docker->id,
                'destination_type' => StandaloneDocker::class,
                'docker_compose' => 'version: "3"',
                'watch_paths' => $paths ? json_encode($paths) : null,
            ]);
        }

        // Verify all services
        foreach ($services as $index => $service) {
            $savedService = Service::find($service->id);
            $this->assertNotNull($savedService);
            
            if ($watchPathsData[$index] !== null) {
                $this->assertEquals(
                    json_encode($watchPathsData[$index]),
                    $savedService->watch_paths
                );
            } else {
                $this->assertNull($savedService->watch_paths);
            }
        }

        // Test bulk update
        $bulkPaths = json_encode(['updated/**/*.txt']);
        Service::whereIn('id', array_column($services, 'id'))
            ->update(['watch_paths' => $bulkPaths]);

        // Verify bulk update
        foreach ($services as $service) {
            $updatedService = Service::find($service->id);
            $this->assertEquals($bulkPaths, $updatedService->watch_paths);
        }
    }

    /**
     * Test migration performance with large dataset
     */
    public function test_migration_performance_with_large_dataset()
    {
        $startTime = microtime(true);

        $team = Team::create([
            'name' => 'Performance Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Performance Test Project',
            'team_id' => $team->id,
            'uuid' => 'performance-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'performance',
            'project_id' => $project->id,
            'uuid' => 'performance-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Performance Test Server',
            'uuid' => 'performance-server-uuid',
            'ip' => '192.168.100.1',
            'user' => 'deploy',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Performance Test Docker',
            'uuid' => 'performance-docker-uuid',
            'server_id' => $server->id,
            'network' => 'performance-network',
        ]);

        // Create large watch_paths data
        $largePaths = [];
        for ($i = 0; $i < 500; $i++) {
            $largePaths[] = "src/module{$i}/**/*.js";
        }
        $largePathsJson = json_encode($largePaths);

        // Insert multiple services with large watch_paths
        $serviceData = [];
        for ($i = 0; $i < 100; $i++) {
            $serviceData[] = [
                'name' => "perf-service-{$i}",
                'uuid' => "perf-service-uuid-{$i}",
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'destination_id' => $docker->id,
                'destination_type' => StandaloneDocker::class,
                'docker_compose' => 'version: "3"',
                'watch_paths' => $i % 2 === 0 ? $largePathsJson : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Bulk insert
        DB::table('services')->insert($serviceData);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Performance should be reasonable (under 5 seconds for 100 services)
        $this->assertLessThan(5, $executionTime, 'Bulk insert should complete within 5 seconds');

        // Verify data integrity
        $services = Service::where('name', 'like', 'perf-service-%')->get();
        $this->assertCount(100, $services);

        // Verify watch_paths integrity
        $servicesWithPaths = $services->filter(function ($service) {
            return $service->watch_paths !== null;
        });
        $this->assertCount(50, $servicesWithPaths); // Half should have watch_paths

        // Test query performance
        $queryStart = microtime(true);
        $result = Service::whereNotNull('watch_paths')
            ->where('name', 'like', 'perf-service-%')
            ->get();
        $queryEnd = microtime(true);
        $queryTime = $queryEnd - $queryStart;

        $this->assertLessThan(1, $queryTime, 'Query should complete within 1 second');
        $this->assertCount(50, $result);
    }

    /**
     * Test migration with edge cases
     */
    public function test_migration_edge_cases()
    {
        $team = Team::create([
            'name' => 'Edge Case Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Edge Case Project',
            'team_id' => $team->id,
            'uuid' => 'edge-case-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'edge',
            'project_id' => $project->id,
            'uuid' => 'edge-case-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Edge Case Server',
            'uuid' => 'edge-case-server-uuid',
            'ip' => '10.10.10.10',
            'user' => 'edge',
            'port' => 2222,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Edge Case Docker',
            'uuid' => 'edge-case-docker-uuid',
            'server_id' => $server->id,
            'network' => 'edge-network',
        ]);

        // Test empty array
        $service1 = Service::create([
            'name' => 'edge-service-empty-array',
            'uuid' => 'edge-service-empty-array-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => json_encode([]),
        ]);
        $this->assertEquals('[]', $service1->watch_paths);

        // Test empty string
        $service2 = Service::create([
            'name' => 'edge-service-empty-string',
            'uuid' => 'edge-service-empty-string-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => '',
        ]);
        $this->assertEquals('', $service2->watch_paths);

        // Test Unicode characters
        $unicodePaths = [
            '中文路径/**/*.js',
            'русский/**/*.py',
            'العربية/**/*.go',
            '日本語/**/*.rb',
            '한국어/**/*.java',
            'emoji-😀-path/**/*.ts',
        ];
        $service3 = Service::create([
            'name' => 'edge-service-unicode',
            'uuid' => 'edge-service-unicode-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => json_encode($unicodePaths),
        ]);
        $saved = Service::find($service3->id);
        $this->assertEquals(json_encode($unicodePaths), $saved->watch_paths);

        // Test very long single path
        $longPath = str_repeat('a', 10000) . '/**/*.js';
        $service4 = Service::create([
            'name' => 'edge-service-long-path',
            'uuid' => 'edge-service-long-path-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => json_encode([$longPath]),
        ]);
        $saved4 = Service::find($service4->id);
        $this->assertEquals(json_encode([$longPath]), $saved4->watch_paths);

        // Test special regex characters
        $regexPaths = [
            '**/*.[jt]s?(x)',
            'src/+(foo|bar)/**/*.js',
            '!(node_modules|dist)/**',
            'test/**/*@(spec|test).js',
            'src/**/?(*.){js,jsx,ts,tsx}',
        ];
        $service5 = Service::create([
            'name' => 'edge-service-regex',
            'uuid' => 'edge-service-regex-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => json_encode($regexPaths),
        ]);
        $saved5 = Service::find($service5->id);
        $this->assertEquals(json_encode($regexPaths), $saved5->watch_paths);
    }

    /**
     * Test migration rollback and re-run
     */
    public function test_migration_rollback_and_rerun()
    {
        // Create test data
        $team = Team::create([
            'name' => 'Rollback Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Rollback Test Project',
            'team_id' => $team->id,
            'uuid' => 'rollback-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'rollback',
            'project_id' => $project->id,
            'uuid' => 'rollback-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Rollback Test Server',
            'uuid' => 'rollback-server-uuid',
            'ip' => '10.20.30.40',
            'user' => 'rollback',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Rollback Test Docker',
            'uuid' => 'rollback-docker-uuid',
            'server_id' => $server->id,
            'network' => 'rollback-network',
        ]);

        // Create service with watch_paths
        $service = Service::create([
            'name' => 'rollback-test-service',
            'uuid' => 'rollback-test-service-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => json_encode(['src/**/*.js']),
        ]);

        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
        $this->assertNotNull($service->watch_paths);

        // Rollback migration
        $migration = require base_path('database/migrations/2025_01_13_000000_add_watch_paths_to_services.php');
        $migration->down();

        // Column should be gone
        $this->assertFalse(Schema::hasColumn('services', 'watch_paths'));

        // Re-run migration
        $migration->up();

        // Column should be back
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));

        // Service should still exist but watch_paths will be null
        $serviceAfter = Service::find($service->id);
        $this->assertNotNull($serviceAfter);
        $this->assertNull($serviceAfter->watch_paths);

        // Should be able to set watch_paths again
        $serviceAfter->watch_paths = json_encode(['new/**/*.js']);
        $serviceAfter->save();

        $updated = Service::find($service->id);
        $this->assertEquals(json_encode(['new/**/*.js']), $updated->watch_paths);
    }
}