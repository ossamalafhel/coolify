<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Service;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Project;
use App\Models\Team;
use App\Models\StandaloneDocker;

class ServiceWatchPathsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the migration creates the watch_paths column
     */
    public function test_migration_creates_watch_paths_column()
    {
        // The migration should have run during RefreshDatabase
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
    }

    /**
     * Test that the watch_paths column has correct properties
     */
    public function test_watch_paths_column_properties()
    {
        // Get column information
        $columns = Schema::getColumnListing('services');
        $this->assertContains('watch_paths', $columns);
        
        // Check that it's positioned after docker_compose (this is harder to test directly)
        $dockerComposeIndex = array_search('docker_compose', $columns);
        $watchPathsIndex = array_search('watch_paths', $columns);
        
        // watch_paths should come after docker_compose
        $this->assertGreaterThan($dockerComposeIndex, $watchPathsIndex);
    }

    /**
     * Test that watch_paths column accepts null values
     */
    public function test_watch_paths_accepts_null()
    {
        $team = Team::create([
            'name' => 'Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project',
            'team_id' => $team->id,
            'uuid' => 'test-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'test-server-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'test-docker-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);
        
        // Create service without watch_paths
        $service = Service::create([
            'name' => 'test-service',
            'uuid' => 'test-service-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            // watch_paths not specified, should be null
        ]);
        
        $this->assertNull($service->watch_paths);
    }

    /**
     * Test that watch_paths column stores JSON data correctly
     */
    public function test_watch_paths_stores_json_data()
    {
        $team = Team::create([
            'name' => 'Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project',
            'team_id' => $team->id,
            'uuid' => 'test-project-uuid-2',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-uuid-2',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'test-server-uuid-2',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'test-docker-uuid-2',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);
        
        $watchPaths = ['src/**/*.js', 'config/*.json', 'tests/**/*.test.js'];
        
        // Create service with watch_paths
        $service = Service::create([
            'name' => 'test-service-2',
            'uuid' => 'test-service-uuid-2',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => $watchPaths,
        ]);
        
        // Retrieve from database
        $savedService = Service::find($service->id);
        
        $this->assertIsArray($savedService->watch_paths);
        $this->assertEquals($watchPaths, $savedService->watch_paths);
    }

    /**
     * Test migration rollback removes watch_paths column
     */
    public function test_migration_rollback()
    {
        // First ensure the column exists
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
        
        // Get the migration class name
        $migrationFile = '2025_09_12_235830_add_watch_paths_to_services_table';
        $migrationClass = 'AddWatchPathsToServicesTable';
        
        // Include the migration file
        require_once database_path('migrations/' . $migrationFile . '.php');
        
        // Create an instance of the migration
        $migration = new \AddWatchPathsToServicesTable();
        
        // Run the down method
        $migration->down();
        
        // Check that the column no longer exists
        $this->assertFalse(Schema::hasColumn('services', 'watch_paths'));
        
        // Run the up method again to restore for other tests
        $migration->up();
        
        // Verify it's back
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
    }

    /**
     * Test that existing services work after migration
     */
    public function test_existing_services_work_after_migration()
    {
        // Create team and related models
        $team = Team::create([
            'name' => 'Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project',
            'team_id' => $team->id,
            'uuid' => 'existing-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'existing-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'existing-server-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'existing-docker-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);
        
        // Simulate an existing service (created before migration)
        // by inserting directly without watch_paths
        DB::table('services')->insert([
            'name' => 'existing-service',
            'uuid' => 'existing-service-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Retrieve the service
        $service = Service::where('uuid', 'existing-service-uuid')->first();
        
        // Should work fine with null watch_paths
        $this->assertNotNull($service);
        $this->assertNull($service->watch_paths);
        
        // Should be able to update watch_paths
        $service->watch_paths = ['src/**/*.js'];
        $service->save();
        
        $updatedService = Service::find($service->id);
        $this->assertEquals(['src/**/*.js'], $updatedService->watch_paths);
    }

    /**
     * Test bulk update of watch_paths
     */
    public function test_bulk_update_watch_paths()
    {
        $team = Team::create([
            'name' => 'Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project',
            'team_id' => $team->id,
            'uuid' => 'bulk-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'bulk-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'bulk-server-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'bulk-docker-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);
        
        // Create multiple services
        $serviceIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $service = Service::create([
                'name' => "bulk-service-$i",
                'uuid' => "bulk-service-uuid-$i",
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'destination_id' => $docker->id,
                'destination_type' => StandaloneDocker::class,
                'docker_compose' => 'version: "3"',
            ]);
            $serviceIds[] = $service->id;
        }
        
        // Bulk update watch_paths
        $watchPaths = json_encode(['src/**/*.js', 'tests/**/*.test.js']);
        DB::table('services')
            ->whereIn('id', $serviceIds)
            ->update(['watch_paths' => $watchPaths]);
        
        // Verify all services have the watch_paths
        foreach ($serviceIds as $id) {
            $service = Service::find($id);
            $this->assertEquals(['src/**/*.js', 'tests/**/*.test.js'], $service->watch_paths);
        }
    }

    /**
     * Test complex JSON structures in watch_paths
     */
    public function test_complex_json_structures()
    {
        $team = Team::create([
            'name' => 'Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project',
            'team_id' => $team->id,
            'uuid' => 'complex-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'complex-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'complex-server-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'complex-docker-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);
        
        // Test with various complex patterns
        $complexPatterns = [
            'src/**/*.{js,jsx,ts,tsx}',
            'packages/*/src/**/*.js',
            '!**/*.test.js',
            '!**/*.spec.js',
            'config/*.{json,yml,yaml}',
            'scripts/**/*',
            '.github/workflows/*.yml',
            '**/node_modules/**',
        ];
        
        $service = Service::create([
            'name' => 'complex-service',
            'uuid' => 'complex-service-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => $complexPatterns,
        ]);
        
        // Retrieve and verify
        $savedService = Service::find($service->id);
        $this->assertEquals($complexPatterns, $savedService->watch_paths);
        
        // Verify individual patterns are preserved
        foreach ($complexPatterns as $pattern) {
            $this->assertContains($pattern, $savedService->watch_paths);
        }
    }

    /**
     * Test that watch_paths integrates with Service model methods
     */
    public function test_watch_paths_integration_with_service_model()
    {
        $team = Team::create([
            'name' => 'Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project',
            'team_id' => $team->id,
            'uuid' => 'integration-project-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'integration-env-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'integration-server-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'integration-docker-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);
        
        $service = Service::create([
            'name' => 'integration-service',
            'uuid' => 'integration-service-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => ['src/**/*.js'],
        ]);
        
        // Test that the service can be retrieved with watch_paths
        $this->assertNotNull($service->watch_paths);
        
        // Test that isWatchPathsTriggered method exists and works
        $this->assertTrue(method_exists($service, 'isWatchPathsTriggered'));
        $this->assertTrue($service->isWatchPathsTriggered('src/app.js'));
        $this->assertFalse($service->isWatchPathsTriggered('config/app.json'));
        
        // Test that other Service model functionality still works
        $this->assertEquals('integration-service', $service->name);
        $this->assertEquals($environment->id, $service->environment_id);
        $this->assertEquals($server->id, $service->server_id);
    }
}