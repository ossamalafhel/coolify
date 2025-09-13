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

class ServiceWatchPathsTest extends TestCase
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
        
        // Create service with watch_paths
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
     * Test that watch_paths field is properly cast to array
     */
    public function test_watch_paths_is_cast_to_array()
    {
        $patterns = ['src/**/*.js', 'config/*.json'];
        $this->service->watch_paths = $patterns;
        $this->service->save();
        
        $service = Service::find($this->service->id);
        
        $this->assertIsArray($service->watch_paths);
        $this->assertEquals($patterns, $service->watch_paths);
    }

    /**
     * Test that null watch_paths returns false for isWatchPathsTriggered
     */
    public function test_null_watch_paths_returns_false()
    {
        $this->service->watch_paths = null;
        $this->service->save();
        
        $result = $this->service->isWatchPathsTriggered(['src/app.js']);
        
        $this->assertFalse($result);
    }

    /**
     * Test that empty watch_paths array returns false for isWatchPathsTriggered
     */
    public function test_empty_watch_paths_returns_false()
    {
        $this->service->watch_paths = [];
        $this->service->save();
        
        $result = $this->service->isWatchPathsTriggered(['src/app.js']);
        
        $this->assertFalse($result);
    }

    /**
     * Test exact file path matching
     */
    public function test_exact_file_path_matching()
    {
        $this->service->watch_paths = ['src/app.js', 'config/database.json'];
        $this->service->save();
        
        // Should match exact paths
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/database.json'));
        
        // Should not match different paths
        $this->assertFalse($this->service->isWatchPathsTriggered('src/other.js'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/app.json'));
    }

    /**
     * Test wildcard pattern matching with single asterisk
     */
    public function test_single_asterisk_wildcard_matching()
    {
        $this->service->watch_paths = ['src/*.js', 'config/*.json'];
        $this->service->save();
        
        // Should match files in directory
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/index.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/database.json'));
        
        // Should NOT match files in subdirectories (single * doesn't match /)
        $this->assertFalse($this->service->isWatchPathsTriggered('src/components/header.js'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/env/production.json'));
        
        // Should not match wrong extensions
        $this->assertFalse($this->service->isWatchPathsTriggered('src/app.ts'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/database.yml'));
    }

    /**
     * Test recursive wildcard pattern matching with double asterisk
     */
    public function test_double_asterisk_recursive_matching()
    {
        $this->service->watch_paths = ['src/**/*.js', 'config/**/*.json'];
        $this->service->save();
        
        // Should match files at any depth
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/components/header.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/components/ui/button.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/database.json'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/env/production.json'));
        
        // Should not match wrong extensions
        $this->assertFalse($this->service->isWatchPathsTriggered('src/app.ts'));
        $this->assertFalse($this->service->isWatchPathsTriggered('src/components/header.tsx'));
    }

    /**
     * Test directory-level pattern matching
     */
    public function test_directory_level_pattern_matching()
    {
        $this->service->watch_paths = ['src/**', 'tests/**'];
        $this->service->save();
        
        // Should match any file in the directories
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/styles/main.css'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/components/deep/nested/file.jsx'));
        $this->assertTrue($this->service->isWatchPathsTriggered('tests/unit/service.test.js'));
        
        // Should not match files outside specified directories
        $this->assertFalse($this->service->isWatchPathsTriggered('config/app.js'));
        $this->assertFalse($this->service->isWatchPathsTriggered('public/index.html'));
    }

    /**
     * Test question mark wildcard for single character matching
     */
    public function test_question_mark_wildcard_matching()
    {
        $this->service->watch_paths = ['src/app.?s', 'config/?.json'];
        $this->service->save();
        
        // Should match single character
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.ts'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/a.json'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/b.json'));
        
        // Should not match multiple characters or no character
        $this->assertFalse($this->service->isWatchPathsTriggered('src/app.jsx'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/ab.json'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/.json'));
    }

    /**
     * Test multiple file paths against patterns
     */
    public function test_multiple_file_paths_matching()
    {
        $this->service->watch_paths = ['src/**/*.js', 'tests/**/*.test.js'];
        $this->service->save();
        
        // Test with array of file paths - should return true if ANY match
        $filePaths = [
            'README.md',
            'package.json',
            'src/components/header.js',  // This should match
            'config/app.json'
        ];
        
        $this->assertTrue($this->service->isWatchPathsTriggered($filePaths));
        
        // Test with no matching files
        $nonMatchingPaths = [
            'README.md',
            'package.json',
            'config/app.json'
        ];
        
        $this->assertFalse($this->service->isWatchPathsTriggered($nonMatchingPaths));
    }

    /**
     * Test complex pattern combinations
     */
    public function test_complex_pattern_combinations()
    {
        $this->service->watch_paths = [
            'src/**/*.{js,jsx,ts,tsx}',  // This pattern won't work with fnmatch directly
            'src/**/*.js',
            'src/**/*.jsx',
            'src/**/*.ts',
            'src/**/*.tsx',
            '!src/**/*.test.js',  // Negation pattern (won't work with simple fnmatch)
            'config/*.json',
            'package.json',
            'docker-compose.yml'
        ];
        $this->service->save();
        
        // Test various file matches
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/components/Button.jsx'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/types/index.ts'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/components/Modal.tsx'));
        $this->assertTrue($this->service->isWatchPathsTriggered('config/database.json'));
        $this->assertTrue($this->service->isWatchPathsTriggered('package.json'));
        $this->assertTrue($this->service->isWatchPathsTriggered('docker-compose.yml'));
        
        // Note: Negation patterns and brace expansion won't work with basic fnmatch
        // but the positive patterns will still match
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.test.js'));
    }

    /**
     * Test case sensitivity in pattern matching
     */
    public function test_case_sensitivity_in_patterns()
    {
        $this->service->watch_paths = ['src/*.JS', 'CONFIG/*.json'];
        $this->service->save();
        
        // fnmatch is case-sensitive by default on most systems
        $this->assertFalse($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/database.json'));
        
        // These should match
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.JS'));
        $this->assertTrue($this->service->isWatchPathsTriggered('CONFIG/database.json'));
    }

    /**
     * Test edge cases with special characters in paths
     */
    public function test_special_characters_in_paths()
    {
        $this->service->watch_paths = [
            'src/[test]/*.js',
            'src/file-with-dash.js',
            'src/file.with.dots.js',
            'src/file_with_underscore.js'
        ];
        $this->service->save();
        
        // Test special characters
        $this->assertTrue($this->service->isWatchPathsTriggered('src/file-with-dash.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/file.with.dots.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('src/file_with_underscore.js'));
        
        // Brackets in patterns are treated as character classes
        $this->assertTrue($this->service->isWatchPathsTriggered('src/[test]/app.js'));
    }

    /**
     * Test with single string instead of array
     */
    public function test_single_string_file_path()
    {
        $this->service->watch_paths = ['src/**/*.js'];
        $this->service->save();
        
        // Should handle single string properly
        $this->assertTrue($this->service->isWatchPathsTriggered('src/app.js'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/app.json'));
    }

    /**
     * Test root-level file matching
     */
    public function test_root_level_file_matching()
    {
        $this->service->watch_paths = ['*.json', '*.md'];
        $this->service->save();
        
        // Should match root-level files
        $this->assertTrue($this->service->isWatchPathsTriggered('package.json'));
        $this->assertTrue($this->service->isWatchPathsTriggered('README.md'));
        
        // Should not match files in subdirectories
        $this->assertFalse($this->service->isWatchPathsTriggered('config/app.json'));
        $this->assertFalse($this->service->isWatchPathsTriggered('docs/README.md'));
    }

    /**
     * Test performance with large number of patterns
     */
    public function test_performance_with_many_patterns()
    {
        // Create a service with many watch patterns
        $patterns = [];
        for ($i = 0; $i < 100; $i++) {
            $patterns[] = "src/module{$i}/**/*.js";
            $patterns[] = "tests/module{$i}/**/*.test.js";
        }
        
        $this->service->watch_paths = $patterns;
        $this->service->save();
        
        // Test that it still works correctly
        $this->assertTrue($this->service->isWatchPathsTriggered('src/module50/components/Button.js'));
        $this->assertTrue($this->service->isWatchPathsTriggered('tests/module99/unit/service.test.js'));
        $this->assertFalse($this->service->isWatchPathsTriggered('config/app.json'));
    }

    /**
     * Test that watch_paths persists correctly in database
     */
    public function test_watch_paths_persistence()
    {
        $patterns = [
            'src/**/*.js',
            'config/*.json',
            'tests/**/*.test.js'
        ];
        
        $this->service->watch_paths = $patterns;
        $this->service->save();
        
        // Reload from database
        $service = Service::find($this->service->id);
        
        $this->assertEquals($patterns, $service->watch_paths);
        $this->assertTrue($service->isWatchPathsTriggered('src/app.js'));
    }

    /**
     * Test handling of malformed patterns
     */
    public function test_malformed_patterns()
    {
        $this->service->watch_paths = [
            '**/*/*/*.js',  // Deep pattern
            '',              // Empty pattern
            '/',             // Just slash
            'src//',         // Double slash
        ];
        $this->service->save();
        
        // Should not throw errors, just not match
        $this->assertFalse($this->service->isWatchPathsTriggered(''));
        $this->assertFalse($this->service->isWatchPathsTriggered('/'));
        
        // The deep pattern should still work
        $this->assertTrue($this->service->isWatchPathsTriggered('a/b/c.js'));
    }
}