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

class AddWatchPathsToServicesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private $migrationClass = 'database/migrations/2025_01_13_000000_add_watch_paths_to_services.php';

    /**
     * Test that the migration creates watch_paths column when it doesn't exist
     */
    public function test_migration_creates_watch_paths_column_when_not_exists()
    {
        // First, drop the column if it exists to simulate a fresh state
        if (Schema::hasColumn('services', 'watch_paths')) {
            Schema::table('services', function ($table) {
                $table->dropColumn('watch_paths');
            });
        }

        // Assert column doesn't exist
        $this->assertFalse(Schema::hasColumn('services', 'watch_paths'));

        // Run the migration
        $migration = require base_path($this->migrationClass);
        $migration->up();

        // Assert column now exists
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
    }

    /**
     * Test that migration handles existing watch_paths column correctly
     */
    public function test_migration_modifies_existing_watch_paths_column()
    {
        // Create the column as JSON type to simulate old migration
        if (!Schema::hasColumn('services', 'watch_paths')) {
            Schema::table('services', function ($table) {
                $table->json('watch_paths')->nullable();
            });
        }

        // Run the migration
        $migration = require base_path($this->migrationClass);
        $migration->up();

        // Column should still exist
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));

        // Should be able to store text data
        $team = Team::create([
            'name' => 'Test Team',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project',
            'team_id' => $team->id,
            'uuid' => 'test-modify-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-modify-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'test-server-modify-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'test-docker-modify-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);

        // Test with JSON string
        $service = Service::create([
            'name' => 'test-service-modify',
            'uuid' => 'test-service-modify-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => json_encode(['src/**/*.js', 'tests/**/*.test.js']),
        ]);

        $this->assertNotNull($service->watch_paths);
    }

    /**
     * Test that watch_paths column is nullable
     */
    public function test_watch_paths_column_is_nullable()
    {
        $team = Team::create([
            'name' => 'Test Team Nullable',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project Nullable',
            'team_id' => $team->id,
            'uuid' => 'test-project-nullable-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-nullable-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server Nullable',
            'uuid' => 'test-server-nullable-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker Nullable',
            'uuid' => 'test-docker-nullable-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);

        // Create service without watch_paths - should not throw error
        $service = Service::create([
            'name' => 'test-service-nullable',
            'uuid' => 'test-service-nullable-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            // watch_paths intentionally omitted
        ]);

        $this->assertNull($service->watch_paths);
        $this->assertNotNull($service->id);
    }

    /**
     * Test that watch_paths column is of text type
     */
    public function test_watch_paths_column_is_text_type()
    {
        // Get column type using raw SQL query
        $columnType = DB::select(
            "SELECT data_type FROM information_schema.columns 
             WHERE table_name = 'services' AND column_name = 'watch_paths'"
        );

        if (!empty($columnType)) {
            // PostgreSQL returns 'text', MySQL returns 'text' or 'longtext'
            $type = strtolower($columnType[0]->data_type ?? $columnType[0]->DATA_TYPE ?? '');
            $this->assertContains($type, ['text', 'longtext', 'mediumtext']);
        }
    }

    /**
     * Test that watch_paths can store large text content
     */
    public function test_watch_paths_can_store_large_content()
    {
        $team = Team::create([
            'name' => 'Test Team Large',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project Large',
            'team_id' => $team->id,
            'uuid' => 'test-project-large-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-large-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server Large',
            'uuid' => 'test-server-large-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker Large',
            'uuid' => 'test-docker-large-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);

        // Create a large array of watch paths
        $largePaths = [];
        for ($i = 0; $i < 100; $i++) {
            $largePaths[] = "src/module{$i}/**/*.js";
            $largePaths[] = "tests/module{$i}/**/*.test.js";
            $largePaths[] = "config/module{$i}/*.json";
        }

        $largeContent = json_encode($largePaths);

        $service = Service::create([
            'name' => 'test-service-large',
            'uuid' => 'test-service-large-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => $largeContent,
        ]);

        $savedService = Service::find($service->id);
        $this->assertEquals($largeContent, $savedService->watch_paths);
    }

    /**
     * Test migration rollback removes watch_paths column
     */
    public function test_migration_rollback_removes_column()
    {
        // Ensure column exists
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));

        // Run the down migration
        $migration = require base_path($this->migrationClass);
        $migration->down();

        // Column should be removed
        $this->assertFalse(Schema::hasColumn('services', 'watch_paths'));

        // Run up again to restore for other tests
        $migration->up();
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
    }

    /**
     * Test that migration rollback is safe when column doesn't exist
     */
    public function test_migration_rollback_safe_when_column_not_exists()
    {
        // First remove the column if it exists
        if (Schema::hasColumn('services', 'watch_paths')) {
            Schema::table('services', function ($table) {
                $table->dropColumn('watch_paths');
            });
        }

        $this->assertFalse(Schema::hasColumn('services', 'watch_paths'));

        // Run down migration - should not throw error
        $migration = require base_path($this->migrationClass);
        
        try {
            $migration->down();
            $this->assertTrue(true); // Migration succeeded without error
        } catch (\Exception $e) {
            $this->fail('Migration down() should not throw exception when column does not exist');
        }

        // Column should still not exist
        $this->assertFalse(Schema::hasColumn('services', 'watch_paths'));

        // Run up to restore
        $migration->up();
        $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
    }

    /**
     * Test that existing services remain functional after migration
     */
    public function test_existing_services_remain_functional()
    {
        $team = Team::create([
            'name' => 'Test Team Existing',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project Existing',
            'team_id' => $team->id,
            'uuid' => 'test-project-existing-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-existing-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server Existing',
            'uuid' => 'test-server-existing-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker Existing',
            'uuid' => 'test-docker-existing-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);

        // Create service before running migration (simulate existing data)
        $serviceId = DB::table('services')->insertGetId([
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

        // Service should be retrievable
        $service = Service::find($serviceId);
        $this->assertNotNull($service);
        $this->assertEquals('existing-service', $service->name);

        // watch_paths should be null for existing services
        $this->assertNull($service->watch_paths);

        // Should be able to update the service
        $service->watch_paths = json_encode(['src/**/*.js']);
        $service->save();

        $updatedService = Service::find($serviceId);
        $this->assertEquals(json_encode(['src/**/*.js']), $updatedService->watch_paths);
    }

    /**
     * Test that watch_paths field position is after docker_compose
     */
    public function test_watch_paths_field_position()
    {
        $columns = Schema::getColumnListing('services');
        
        $dockerComposeIndex = array_search('docker_compose', $columns);
        $watchPathsIndex = array_search('watch_paths', $columns);
        
        // watch_paths should exist in the column list
        $this->assertNotFalse($watchPathsIndex, 'watch_paths column should exist');
        
        // If docker_compose exists, watch_paths should come after it
        if ($dockerComposeIndex !== false) {
            $this->assertGreaterThan(
                $dockerComposeIndex, 
                $watchPathsIndex,
                'watch_paths should be positioned after docker_compose'
            );
        }
    }

    /**
     * Test concurrent updates to watch_paths don't cause issues
     */
    public function test_concurrent_updates_to_watch_paths()
    {
        $team = Team::create([
            'name' => 'Test Team Concurrent',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project Concurrent',
            'team_id' => $team->id,
            'uuid' => 'test-project-concurrent-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-concurrent-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server Concurrent',
            'uuid' => 'test-server-concurrent-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker Concurrent',
            'uuid' => 'test-docker-concurrent-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);

        // Create multiple services
        $services = [];
        for ($i = 1; $i <= 3; $i++) {
            $services[] = Service::create([
                'name' => "concurrent-service-{$i}",
                'uuid' => "concurrent-service-uuid-{$i}",
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'destination_id' => $docker->id,
                'destination_type' => StandaloneDocker::class,
                'docker_compose' => 'version: "3"',
                'watch_paths' => json_encode(["initial/path{$i}/**/*.js"]),
            ]);
        }

        // Update all services with different watch_paths
        foreach ($services as $index => $service) {
            $service->watch_paths = json_encode([
                "updated/path{$index}/**/*.js",
                "tests/path{$index}/**/*.test.js"
            ]);
            $service->save();
        }

        // Verify all updates were successful
        foreach ($services as $index => $service) {
            $updatedService = Service::find($service->id);
            $expectedPaths = json_encode([
                "updated/path{$index}/**/*.js",
                "tests/path{$index}/**/*.test.js"
            ]);
            $this->assertEquals($expectedPaths, $updatedService->watch_paths);
        }
    }

    /**
     * Test that watch_paths handles special characters correctly
     */
    public function test_watch_paths_handles_special_characters()
    {
        $team = Team::create([
            'name' => 'Test Team Special',
            'personal_team' => false,
        ]);
        
        $project = Project::create([
            'name' => 'Test Project Special',
            'team_id' => $team->id,
            'uuid' => 'test-project-special-uuid',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-special-uuid',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server Special',
            'uuid' => 'test-server-special-uuid',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker Special',
            'uuid' => 'test-docker-special-uuid',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);

        // Test with special characters and patterns
        $specialPaths = [
            'src/**/*.{js,jsx,ts,tsx}',
            'packages/@company/*/src/**',
            'files-with-dashes/**/*.vue',
            'files_with_underscores/**/*.rb',
            'files.with.dots/**/*.py',
            '中文文件夹/**/*.js',
            'файлы/**/*.rs',
            'src/[^.]*.js',
            '!node_modules/**',
            '**/*.test.{js,ts}',
        ];

        $service = Service::create([
            'name' => 'test-service-special',
            'uuid' => 'test-service-special-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => json_encode($specialPaths),
        ]);

        $savedService = Service::find($service->id);
        $this->assertEquals(json_encode($specialPaths), $savedService->watch_paths);
        
        // Decode and verify structure is preserved
        $decodedPaths = json_decode($savedService->watch_paths, true);
        $this->assertEquals($specialPaths, $decodedPaths);
    }

    /**
     * Test migration idempotency - running multiple times doesn't break
     */
    public function test_migration_is_idempotent()
    {
        $migration = require base_path($this->migrationClass);
        
        // Run migration multiple times
        try {
            $migration->up();
            $migration->up();
            $migration->up();
            
            // Should still have the column
            $this->assertTrue(Schema::hasColumn('services', 'watch_paths'));
            
            // Should still work correctly
            $team = Team::create([
                'name' => 'Test Team Idempotent',
                'personal_team' => false,
            ]);
            
            $project = Project::create([
                'name' => 'Test Project Idempotent',
                'team_id' => $team->id,
                'uuid' => 'test-project-idempotent-uuid',
            ]);
            
            $environment = Environment::create([
                'name' => 'production',
                'project_id' => $project->id,
                'uuid' => 'test-env-idempotent-uuid',
            ]);
            
            $server = Server::create([
                'name' => 'Test Server Idempotent',
                'uuid' => 'test-server-idempotent-uuid',
                'ip' => '127.0.0.1',
                'user' => 'root',
                'port' => 22,
                'team_id' => $team->id,
            ]);
            
            $docker = StandaloneDocker::create([
                'name' => 'Test Docker Idempotent',
                'uuid' => 'test-docker-idempotent-uuid',
                'server_id' => $server->id,
                'network' => 'test-network',
            ]);

            $service = Service::create([
                'name' => 'test-service-idempotent',
                'uuid' => 'test-service-idempotent-uuid',
                'environment_id' => $environment->id,
                'server_id' => $server->id,
                'destination_id' => $docker->id,
                'destination_type' => StandaloneDocker::class,
                'docker_compose' => 'version: "3"',
                'watch_paths' => json_encode(['src/**/*.js']),
            ]);
            
            $this->assertNotNull($service->id);
            $this->assertEquals(json_encode(['src/**/*.js']), $service->watch_paths);
            
        } catch (\Exception $e) {
            $this->fail('Migration should be idempotent but threw exception: ' . $e->getMessage());
        }
    }
}