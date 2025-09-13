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

class ServiceWatchPathsAC4EdgeCasesTest extends TestCase
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
     * Test AC4: Performance test with large number of files
     */
    public function test_null_watch_paths_with_large_file_list()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: Large number of files change
        $changed_files = [];
        for ($i = 0; $i < 1000; $i++) {
            $changed_files[] = "src/file{$i}.js";
        }
        
        // Then: Should return true immediately without processing all files
        $start = microtime(true);
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $duration = microtime(true) - $start;
        
        $this->assertTrue($result, 'Should trigger deployment with null watch_paths');
        $this->assertLessThan(0.1, $duration, 'Should return quickly for null watch_paths');
    }

    /**
     * Test AC4: Empty array watch_paths with large file list
     */
    public function test_empty_watch_paths_with_large_file_list()
    {
        // Given: Service with empty watch_paths
        $this->service->watch_paths = [];
        $this->service->save();
        
        // When: Large number of files change
        $changed_files = [];
        for ($i = 0; $i < 1000; $i++) {
            $changed_files[] = "src/file{$i}.js";
        }
        
        // Then: Should return true immediately
        $start = microtime(true);
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $duration = microtime(true) - $start;
        
        $this->assertTrue($result, 'Should trigger deployment with empty watch_paths');
        $this->assertLessThan(0.1, $duration, 'Should return quickly for empty watch_paths');
    }

    /**
     * Test AC4: Null watch_paths with empty file list
     */
    public function test_null_watch_paths_with_empty_file_list()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: No files changed (empty array)
        $changed_files = [];
        
        // Then: Should still return true (backward compatibility)
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Should trigger deployment even with no files when watch_paths is null');
    }

    /**
     * Test AC4: Watch paths set to false (PHP falsy value)
     */
    public function test_watch_paths_set_to_false()
    {
        // Given: Service with watch_paths set to false (edge case)
        $this->service->watch_paths = false;
        $this->service->save();
        
        // When: Files change
        $changed_files = ['src/app.js'];
        
        // Then: Should return true (treated as empty)
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'False watch_paths should trigger deployment');
    }

    /**
     * Test AC4: Watch paths set to zero (PHP falsy value)
     */
    public function test_watch_paths_set_to_zero()
    {
        // Given: Service with watch_paths set to 0 (edge case)
        $this->service->watch_paths = 0;
        $this->service->save();
        
        // When: Files change
        $changed_files = ['src/app.js'];
        
        // Then: Should return true (treated as empty)
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Zero watch_paths should trigger deployment');
    }

    /**
     * Test AC4: Watch paths set to empty string
     */
    public function test_watch_paths_set_to_empty_string()
    {
        // Given: Service with watch_paths set to empty string
        $this->service->watch_paths = '';
        $this->service->save();
        
        // When: Files change
        $changed_files = ['src/app.js'];
        
        // Then: Should return true (treated as empty)
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Empty string watch_paths should trigger deployment');
    }

    /**
     * Test AC4: Null watch_paths with null file input
     */
    public function test_null_watch_paths_with_null_file_input()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: Null is passed as file input (edge case)
        // The method should handle this gracefully
        $result = $this->service->isWatchPathsTriggered(null);
        
        // Then: Should return true and not throw error
        $this->assertTrue($result, 'Should handle null file input gracefully');
    }

    /**
     * Test AC4: Type coercion scenarios
     */
    public function test_various_type_coercion_scenarios()
    {
        // Test with watch_paths as single empty string in array
        $this->service->watch_paths = [''];
        $result = $this->service->isWatchPathsTriggered(['file.txt']);
        $this->assertTrue($result, 'Single empty string in array should trigger');
        
        // Test with watch_paths as array with null value
        $this->service->watch_paths = [null];
        $result = $this->service->isWatchPathsTriggered(['file.txt']);
        $this->assertTrue($result, 'Array with null should trigger');
        
        // Test with watch_paths as array with false
        $this->service->watch_paths = [false];
        $result = $this->service->isWatchPathsTriggered(['file.txt']);
        $this->assertTrue($result, 'Array with false should trigger');
        
        // Test with watch_paths as array with 0
        $this->service->watch_paths = [0];
        $result = $this->service->isWatchPathsTriggered(['file.txt']);
        $this->assertTrue($result, 'Array with 0 should trigger');
        
        // Test with watch_paths as array with mix of empty values
        $this->service->watch_paths = ['', null, false, 0];
        $result = $this->service->isWatchPathsTriggered(['file.txt']);
        $this->assertTrue($result, 'Array with mixed empty values should trigger');
    }

    /**
     * Test AC4: Unicode and special characters in file paths
     */
    public function test_null_watch_paths_with_unicode_file_paths()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: File paths with unicode and special characters
        $changed_files = [
            'src/文件.js',
            'data/файл.json',
            'config/αρχείο.yml',
            'test/file with spaces.txt',
            'src/file-with-dashes.js',
            'src/file_with_underscores.js',
            'src/file.with.dots.js',
            'src/@special/!file#.js',
        ];
        
        // Then: Should trigger deployment for all
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Should trigger deployment for unicode and special character files');
    }

    /**
     * Test AC4: Very long file paths
     */
    public function test_null_watch_paths_with_very_long_file_paths()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: Very long file path (edge case for path length limits)
        $longPath = str_repeat('very/long/path/', 100) . 'file.js';
        $changed_files = [$longPath];
        
        // Then: Should trigger deployment
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Should trigger deployment for very long file paths');
    }

    /**
     * Test AC4: File paths with directory traversal attempts
     */
    public function test_null_watch_paths_with_directory_traversal_paths()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: File paths with directory traversal patterns
        $changed_files = [
            '../../../etc/passwd',
            './src/../config/../../file.js',
            'src/./././file.js',
            '../../../../file.js',
        ];
        
        // Then: Should still trigger deployment (security is handled elsewhere)
        $result = $this->service->isWatchPathsTriggered($changed_files);
        $this->assertTrue($result, 'Should trigger deployment even with traversal patterns');
    }

    /**
     * Test AC4: Consistency across multiple calls
     */
    public function test_consistency_of_null_watch_paths_behavior()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: Multiple calls with different inputs
        $test_cases = [
            ['single-file.js'],
            ['file1.js', 'file2.js'],
            [],
            ['deeply/nested/file.js'],
            ['file.js', 'another.js', 'third.js'],
        ];
        
        // Then: All should return true
        foreach ($test_cases as $files) {
            $result = $this->service->isWatchPathsTriggered($files);
            $this->assertTrue($result, 'Should consistently return true for null watch_paths');
        }
    }

    /**
     * Test AC4: Database casting behavior
     */
    public function test_database_casting_behavior_with_null_watch_paths()
    {
        // Test that the database properly handles null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // Retrieve fresh from database
        $freshService = Service::find($this->service->id);
        
        // Verify null is preserved
        $this->assertNull($freshService->watch_paths);
        
        // Verify behavior is correct
        $this->assertTrue($freshService->isWatchPathsTriggered(['any-file.txt']));
        
        // Now test with empty array
        $this->service->watch_paths = [];
        $this->service->save();
        
        $freshService = Service::find($this->service->id);
        $this->assertIsArray($freshService->watch_paths);
        $this->assertEmpty($freshService->watch_paths);
        $this->assertTrue($freshService->isWatchPathsTriggered(['any-file.txt']));
    }

    /**
     * Test AC4: Method chaining and fluent interface
     */
    public function test_method_behavior_in_fluent_context()
    {
        // Test that the method works correctly when used in conditionals
        $this->service->watch_paths = null;
        
        // Should work in if statements
        if ($this->service->isWatchPathsTriggered(['file.txt'])) {
            $this->assertTrue(true, 'Method should work in conditionals');
        } else {
            $this->fail('Should have returned true for null watch_paths');
        }
        
        // Should work with ternary operators
        $result = $this->service->isWatchPathsTriggered(['file.txt']) ? 'deploy' : 'skip';
        $this->assertEquals('deploy', $result);
        
        // Should work with null coalescing
        $deploy = $this->service->isWatchPathsTriggered(['file.txt']) ?? false;
        $this->assertTrue($deploy);
    }

    /**
     * Test AC4: Memory efficiency with null watch_paths
     */
    public function test_memory_efficiency_with_null_watch_paths()
    {
        // Given: Service with null watch_paths
        $this->service->watch_paths = null;
        $this->service->save();
        
        // When: Called multiple times with large datasets
        $memory_before = memory_get_usage();
        
        for ($i = 0; $i < 100; $i++) {
            $files = array_map(fn($j) => "file{$j}.txt", range(1, 100));
            $this->service->isWatchPathsTriggered($files);
        }
        
        $memory_after = memory_get_usage();
        $memory_increase = $memory_after - $memory_before;
        
        // Then: Memory increase should be minimal (no pattern matching overhead)
        $this->assertLessThan(1024 * 1024, $memory_increase, 'Memory usage should be minimal for null watch_paths');
    }

    /**
     * Test that transition from configured to null watch_paths works
     */
    public function test_transition_from_configured_to_null_watch_paths()
    {
        // Start with configured watch_paths
        $this->service->watch_paths = ['src/**/*.js'];
        $this->service->save();
        
        // Should not trigger for non-matching files
        $this->assertFalse($this->service->isWatchPathsTriggered(['README.md']));
        
        // Transition to null watch_paths (backward compatibility mode)
        $this->service->watch_paths = null;
        $this->service->save();
        
        // Now should trigger for any file
        $this->assertTrue($this->service->isWatchPathsTriggered(['README.md']));
        $this->assertTrue($this->service->isWatchPathsTriggered(['any-file.txt']));
        
        // Transition to empty array
        $this->service->watch_paths = [];
        $this->service->save();
        
        // Should still trigger for any file
        $this->assertTrue($this->service->isWatchPathsTriggered(['README.md']));
        $this->assertTrue($this->service->isWatchPathsTriggered(['any-file.txt']));
    }

    /**
     * Test AC4 with mock webhook scenario
     */
    public function test_simulated_webhook_scenario_with_null_watch_paths()
    {
        // Simulate real webhook scenario
        $this->service->watch_paths = null;
        $this->service->save();
        
        // Simulate GitHub webhook payload processing
        $commits = [
            ['added' => ['new-file.js'], 'modified' => [], 'removed' => []],
            ['added' => [], 'modified' => ['existing-file.js'], 'removed' => []],
            ['added' => [], 'modified' => [], 'removed' => ['old-file.js']],
        ];
        
        $allChangedFiles = [];
        foreach ($commits as $commit) {
            $allChangedFiles = array_merge(
                $allChangedFiles,
                $commit['added'],
                $commit['modified'],
                $commit['removed']
            );
        }
        
        // Should trigger deployment
        $shouldDeploy = $this->service->isWatchPathsTriggered($allChangedFiles);
        $this->assertTrue($shouldDeploy, 'Webhook should trigger deployment with null watch_paths');
    }
}