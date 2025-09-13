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

class ServiceWatchPathsTestAC2 extends TestCase
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
            'uuid' => 'test-project-uuid-ac2',
        ]);
        
        $environment = Environment::create([
            'name' => 'production',
            'project_id' => $project->id,
            'uuid' => 'test-env-uuid-ac2',
        ]);
        
        $server = Server::create([
            'name' => 'Test Server',
            'uuid' => 'test-server-uuid-ac2',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
            'team_id' => $team->id,
        ]);
        
        $docker = StandaloneDocker::create([
            'name' => 'Test Docker',
            'uuid' => 'test-docker-uuid-ac2',
            'server_id' => $server->id,
            'network' => 'test-network',
        ]);
        
        // Create service with watch_paths
        $this->service = Service::create([
            'name' => 'test-service-ac2',
            'uuid' => 'test-service-uuid-ac2',
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'destination_id' => $docker->id,
            'destination_type' => StandaloneDocker::class,
            'docker_compose' => 'version: "3"',
            'watch_paths' => null,
        ]);
    }

    /**
     * Test AC2: Service with watch_paths "services/api/**" and modified files collection
     * 
     * Given: Service has watch_paths "services/api/**" and modified files collection
     * When: isWatchPathsTriggered() is called with files ["services/api/index.js", "frontend/app.js"]
     * Then: Returns true only for matching pattern (services/api/index.js)
     */
    public function test_ac2_scenario_services_api_watch_path()
    {
        // Given: Service has watch_paths "services/api/**"
        $this->service->watch_paths = ['services/api/**'];
        $this->service->save();
        
        // When: isWatchPathsTriggered() is called with individual files
        // Then: Returns true only for matching pattern
        
        // Test 1: services/api/index.js should match
        $this->assertTrue(
            $this->service->isWatchPathsTriggered('services/api/index.js'),
            'services/api/index.js should match the pattern services/api/**'
        );
        
        // Test 2: frontend/app.js should NOT match
        $this->assertFalse(
            $this->service->isWatchPathsTriggered('frontend/app.js'),
            'frontend/app.js should NOT match the pattern services/api/**'
        );
        
        // Test 3: Array with both files should return true (because services/api/index.js matches)
        $files = ['services/api/index.js', 'frontend/app.js'];
        $this->assertTrue(
            $this->service->isWatchPathsTriggered($files),
            'Should return true when array contains at least one matching file'
        );
        
        // Test 4: Array with only non-matching files should return false
        $nonMatchingFiles = ['frontend/app.js', 'backend/server.js', 'config/database.json'];
        $this->assertFalse(
            $this->service->isWatchPathsTriggered($nonMatchingFiles),
            'Should return false when no files match the pattern'
        );
        
        // Additional edge cases for services/api/** pattern
        
        // Test 5: Deeply nested files under services/api should match
        $this->assertTrue(
            $this->service->isWatchPathsTriggered('services/api/v1/users/controller.js'),
            'Deeply nested file services/api/v1/users/controller.js should match'
        );
        
        // Test 6: Files directly in services/ but not under api/ should NOT match
        $this->assertFalse(
            $this->service->isWatchPathsTriggered('services/auth/index.js'),
            'services/auth/index.js should NOT match (different service folder)'
        );
        
        // Test 7: File named api at services level should NOT match
        $this->assertFalse(
            $this->service->isWatchPathsTriggered('services/api.js'),
            'services/api.js should NOT match (api.js is not under api/ directory)'
        );
        
        // Test 8: Files in parent directory should NOT match
        $this->assertFalse(
            $this->service->isWatchPathsTriggered('api/index.js'),
            'api/index.js should NOT match (not under services/)'
        );
    }

    /**
     * Test that the method handles edge cases correctly
     */
    public function test_edge_cases_for_watch_paths()
    {
        // Test with empty watch_paths returns false
        $this->service->watch_paths = [];
        $this->service->save();
        
        $result = $this->service->isWatchPathsTriggered(['services/api/index.js']);
        $this->assertFalse($result, 'Empty watch_paths should return false');
        
        // Test with null watch_paths returns false
        $this->service->watch_paths = null;
        $this->service->save();
        
        $result = $this->service->isWatchPathsTriggered(['services/api/index.js']);
        $this->assertFalse($result, 'Null watch_paths should return false');
    }

    /**
     * Test that watch_paths attribute is properly cast to array
     */
    public function test_watch_paths_casting()
    {
        $patterns = ['services/api/**', 'frontend/**/*.js'];
        $this->service->watch_paths = $patterns;
        $this->service->save();
        
        // Reload from database to ensure casting works
        $service = Service::find($this->service->id);
        
        $this->assertIsArray($service->watch_paths);
        $this->assertEquals($patterns, $service->watch_paths);
        
        // Verify the method works with the cast data
        $this->assertTrue($service->isWatchPathsTriggered('services/api/index.js'));
        $this->assertTrue($service->isWatchPathsTriggered('frontend/components/Button.js'));
        $this->assertFalse($service->isWatchPathsTriggered('backend/server.js'));
    }
}