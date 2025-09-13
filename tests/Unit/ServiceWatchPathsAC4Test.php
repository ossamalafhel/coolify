<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Service;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Project;
use App\Models\Team;
use App\Models\StandaloneDocker;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ServiceWatchPathsAC4Test extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create necessary related models
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
        
        // Create service
        $this->service = Service::create([
            'name' => 'test-service',
            'uuid' => 'test-service-uuid',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => null,
        ]);
    }

    /**
     * Test AC4: Service with null watch_paths triggers deployment normally
     * 
     * Given: Service with null watch_paths
     * When: Any file changes in webhook
     * Then: Deployment triggers normally (returns true)
     */
    public function test_null_watch_paths_triggers_deployment_normally()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: Any file changes
        $changed_files = [
            'src/app.js',
            'config/database.json',
            'README.md',
            'tests/unit/service.test.js'
        ];
        
        // Then: Deployment should trigger (returns true)
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Service with null watch_paths should trigger deployment on any file change');
        
        // Test with single file
        $this->assertTrue($this->service->isWatchPathsTriggered('any/file.txt'));
        
        // Test with different file paths
        $this->assertTrue($this->service->isWatchPathsTriggered(['package.json']));
        $this->assertTrue($this->service->isWatchPathsTriggered(['deeply/nested/folder/file.php']));
    }

    /**
     * Test AC4: Service with empty array watch_paths triggers deployment normally
     * 
     * Given: Service with empty array watch_paths
     * When: Any file changes in webhook
     * Then: Deployment triggers normally (returns true)
     */
    public function test_empty_array_watch_paths_triggers_deployment_normally()
    {
        // Given: Service with empty array watch_paths
        $this->service->watch_paths = [];
        $this->service->save();
        
        // When: Any file changes
        $changed_files = [
            'src/app.js',
            'config/database.json',
            'README.md',
            'tests/unit/service.test.js'
        ];
        
        // Then: Deployment should trigger (returns true)
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Service with empty watch_paths array should trigger deployment on any file change');
        
        // Test with single file
        $this->assertTrue($this->service->isWatchPathsTriggered('any/file.txt'));
        
        // Test with different file paths
        $this->assertTrue($this->service->isWatchPathsTriggered(['package.json']));
        $this->assertTrue($this->service->isWatchPathsTriggered(['deeply/nested/folder/file.php']));
    }

    /**
     * Test that backward compatibility is maintained
     * Services without watch_paths configuration should deploy on any change
     */
    public function test_backward_compatibility_for_existing_services()
    {
        // Simulate an existing service that never had watch_paths configured
        $existingService = Service::create([
            'name' => 'legacy-service',
            'uuid' => 'legacy-service-uuid',
            'environment_id' => $this->service->environment_id,
            'server_id' => $this->service->server_id,
            'destination_id' => $this->service->destination_id,
            'destination_type' => $this->service->destination_type,
            'docker_compose' => 'version: "3"',
            // watch_paths not set at all (null by default)
        ]);
        
        // Any file change should trigger deployment
        $this->assertTrue($existingService->isWatchPathsTriggered(['any-file.js']));
        $this->assertTrue($existingService->isWatchPathsTriggered(['src/index.php', 'config/app.yml']));
        $this->assertTrue($existingService->isWatchPathsTriggered('single-file.txt'));
    }

    /**
     * Test that services WITH configured watch_paths still work correctly
     * This ensures our change doesn't break existing functionality
     */
    public function test_configured_watch_paths_still_filters_correctly()
    {
        // Given: Service with configured watch_paths
        $this->service->watch_paths = ['src/**/*.js', 'config/*.json'];
        $this->service->save();
        
        // Files matching patterns should trigger
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/components/header.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/database.json'));
        
        // Files NOT matching patterns should NOT trigger
        $this->assertFalse($this->service->isWatchPathsTriggered('README.md'));
        $this->assertFalse($this->service->isWatchPathsTriggered('tests/unit.test.js'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/app.yml'));
    }

    /**
     * Test webhook deployment logic simulation
     * This simulates how webhooks would use the isWatchPathsTriggered method
     */
    public function test_webhook_deployment_decision_with_null_watch_paths()
    {
        // Simulate webhook logic similar to what's in Github.php
        $webhook_changed_files = ['src/app.js', 'package.json', 'README.md'];
        
        // Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // Webhook logic: should deploy if isWatchPathsTriggered returns true OR watch_paths is null
        // With our implementation, isWatchPathsTriggered returns true for null watch_paths
        $should_deploy = $this->service->isWatchPathsTriggered($webhook_changed_files);
        
        $this->assertTrue($should_deploy, 'Webhook should trigger deployment for service with null watch_paths');
    }

    /**
     * Test webhook deployment logic with empty array watch_paths
     */
    public function test_webhook_deployment_decision_with_empty_watch_paths()
    {
        // Simulate webhook logic
        $webhook_changed_files = ['src/app.js', 'package.json', 'README.md'];
        
        // Service with empty array watch_paths
        $this->service->watch_paths = [];
        $this->service->save();
        
        // With our implementation, isWatchPathsTriggered returns true for empty watch_paths
        $should_deploy = $this->service->isWatchPathsTriggered($webhook_changed_files);
        
        $this->assertTrue($should_deploy, 'Webhook should trigger deployment for service with empty watch_paths array');
    }

    /**
     * Test that the change is reflected when retrieving from database
     */
    public function test_database_persistence_with_null_watch_paths()
    {
        // Save service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // Retrieve from database
        $retrievedService = Service::find($this->service->id);
        
        // Should still trigger deployment on any file
        $this->assertTrue($retrievedService->isWatchPathsTriggered(['any-file.txt']));
        $this->assertNull($retrievedService->watch_paths);
    }

    /**
     * Test edge case: watch_paths set to various falsy values
     */
    public function test_various_empty_watch_paths_values()
    {
        // Test with null
        $this->service->watch_paths = null;
        $this->assertTrue($this->service->isWatchPathsTriggered(['file.txt']), 'null watch_paths should trigger');
        
        // Test with empty array
        $this->service->watch_paths = [];
        $this->assertTrue($this->service->isWatchPathsTriggered(['file.txt']), 'empty array watch_paths should trigger');
        
        // Test with array containing empty strings (edge case)
        $this->service->watch_paths = ['', ''];
        $this->assertTrue($this->service->isWatchPathsTriggered(['file.txt']), 'array with empty strings should trigger');
    }

    /**
     * Test comprehensive scenario: mix of services with and without watch_paths
     */
    public function test_mixed_services_deployment_behavior()
    {
        $services = [];
        
        // Service 1: No watch_paths (null)
        $services[] = Service::create([
            'name' => 'service-null',
            'uuid' => 'service-null-uuid',
            'environment_id' => $this->service->environment_id,
            'server_id' => $this->service->server_id,
            'destination_id' => $this->service->destination_id,
            'destination_type' => $this->service->destination_type,
            'docker_compose' => 'version: "3"',
            'watch_paths' => null,
        ]);
        
        // Service 2: Empty watch_paths
        $services[] = Service::create([
            'name' => 'service-empty',
            'uuid' => 'service-empty-uuid',
            'environment_id' => $this->service->environment_id,
            'server_id' => $this->service->server_id,
            'destination_id' => $this->service->destination_id,
            'destination_type' => $this->service->destination_type,
            'docker_compose' => 'version: "3"',
            'watch_paths' => [],
        ]);
        
        // Service 3: With specific watch_paths
        $services[] = Service::create([
            'name' => 'service-configured',
            'uuid' => 'service-configured-uuid',
            'environment_id' => $this->service->environment_id,
            'server_id' => $this->service->server_id,
            'destination_id' => $this->service->destination_id,
            'destination_type' => $this->service->destination_type,
            'docker_compose' => 'version: "3"',
            'watch_paths' => ['src/**/*.js'],
        ]);
        
        // File change that doesn't match the configured pattern
        $changed_files = ['README.md'];
        
        // Services without watch_paths should deploy
        $this->assertTrue($services[0]->isWatchPathsTriggered($changed_files), 'Service with null watch_paths should deploy');
        $this->assertTrue($services[1]->isWatchPathsTriggered($changed_files), 'Service with empty watch_paths should deploy');
        
        // Service with configured watch_paths should NOT deploy (file doesn't match pattern)
        $this->assertFalse($services[2]->isWatchPathsTriggered($changed_files), 'Service with configured watch_paths should not deploy for non-matching files');
        
        // File change that matches the configured pattern
        $matching_files = ['src/app.js'];
        
        // All should deploy for matching files
        $this->assertTrue($services[0]->isWatchPathsTriggered($matching_files));
        $this->assertTrue($services[1]->isWatchPathsTriggered($matching_files));
        $this->assertTrue($services[2]->isWatchPathsTriggered($matching_files));
    }
}