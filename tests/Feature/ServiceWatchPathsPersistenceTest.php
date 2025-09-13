<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ServiceWatchPathsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Team $team;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::factory()->create();
        $this->user->teams()->attach($this->team, ['role' => 'owner']);
        $this->user->currentTeam()->associate($this->team);
        $this->user->save();

        $project = Project::factory()->create(['team_id' => $this->team->id]);
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        $server = Server::factory()->create(['team_id' => $this->team->id]);

        $this->service = Service::factory()->create([
            'team_id' => $this->team->id,
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'docker_compose_raw' => 'version: "3.8"',
            'docker_compose' => ['version' => '3.8'],
        ]);
    }

    /**
     * Test that watch paths persist in database as JSON array
     */
    public function test_watch_paths_persist_as_json_array(): void
    {
        $watchPaths = ['services/api/**', 'shared/**'];
        
        $this->service->watch_paths = $watchPaths;
        $this->service->save();

        // Verify raw database storage
        $raw = DB::table('services')
            ->where('id', $this->service->id)
            ->value('watch_paths');

        $this->assertJson($raw);
        $this->assertEquals($watchPaths, json_decode($raw, true));

        // Verify retrieval
        $this->service->refresh();
        $this->assertEquals($watchPaths, $this->service->watch_paths);
    }

    /**
     * Test null watch paths storage and retrieval
     */
    public function test_null_watch_paths_storage(): void
    {
        $this->service->watch_paths = null;
        $this->service->save();

        $raw = DB::table('services')
            ->where('id', $this->service->id)
            ->value('watch_paths');

        $this->assertNull($raw);

        $this->service->refresh();
        $this->assertNull($this->service->watch_paths);
    }

    /**
     * Test empty array watch paths storage
     */
    public function test_empty_array_watch_paths_storage(): void
    {
        $this->service->watch_paths = [];
        $this->service->save();

        // Empty array should be stored as null for backward compatibility
        $this->service->refresh();
        $this->assertNull($this->service->watch_paths);
    }

    /**
     * Test watch paths persistence across multiple saves
     */
    public function test_watch_paths_persistence_across_saves(): void
    {
        // First save
        $this->service->watch_paths = ['first/**'];
        $this->service->save();

        // Update other field
        $this->service->name = 'Updated Name';
        $this->service->save();

        $this->service->refresh();
        $this->assertEquals(['first/**'], $this->service->watch_paths);

        // Update watch paths
        $this->service->watch_paths = ['second/**', 'third/**'];
        $this->service->save();

        $this->service->refresh();
        $this->assertEquals(['second/**', 'third/**'], $this->service->watch_paths);
    }

    /**
     * Test watch paths with special characters persist correctly
     */
    public function test_special_characters_in_watch_paths_persist(): void
    {
        $specialPaths = [
            'path/with spaces/**',
            'path-with-dashes/**',
            'path_with_underscores/**',
            'path.with.dots/**',
            'path/with/!negation/**',
            'path/with/{braces}/**',
            'path/with/[brackets]/**',
            'path/with/(parens)/**',
            'path/with/|pipes|/**'
        ];

        $this->service->watch_paths = $specialPaths;
        $this->service->save();

        $this->service->refresh();
        $this->assertEquals($specialPaths, $this->service->watch_paths);
    }

    /**
     * Test very long watch paths list persistence
     */
    public function test_large_watch_paths_list_persistence(): void
    {
        $largePaths = [];
        for ($i = 0; $i < 500; $i++) {
            $largePaths[] = "path/number/{$i}/**/*.js";
        }

        $this->service->watch_paths = $largePaths;
        $this->service->save();

        $this->service->refresh();
        $this->assertCount(500, $this->service->watch_paths);
        $this->assertEquals($largePaths, $this->service->watch_paths);
    }

    /**
     * Test watch paths with unicode characters
     */
    public function test_unicode_watch_paths_persistence(): void
    {
        $unicodePaths = [
            'src/components/日本語/**',
            'src/файлы/**',
            'src/中文目录/**',
            'src/مجلد/**',
            'src/emoji/😀/**'
        ];

        $this->service->watch_paths = $unicodePaths;
        $this->service->save();

        $this->service->refresh();
        $this->assertEquals($unicodePaths, $this->service->watch_paths);
    }

    /**
     * Test watch paths transaction rollback
     */
    public function test_watch_paths_transaction_rollback(): void
    {
        $originalPaths = ['original/**'];
        $this->service->watch_paths = $originalPaths;
        $this->service->save();

        try {
            DB::transaction(function () {
                $this->service->watch_paths = ['new/**'];
                $this->service->save();
                throw new \Exception('Rollback test');
            });
        } catch (\Exception $e) {
            // Expected exception
        }

        $this->service->refresh();
        $this->assertEquals($originalPaths, $this->service->watch_paths);
    }

    /**
     * Test watch paths mass assignment protection
     */
    public function test_watch_paths_mass_assignment(): void
    {
        $service = Service::create([
            'team_id' => $this->team->id,
            'environment_id' => $this->service->environment_id,
            'server_id' => $this->service->server_id,
            'name' => 'Mass Assignment Test',
            'docker_compose_raw' => 'version: "3.8"',
            'docker_compose' => ['version' => '3.8'],
            'watch_paths' => ['mass/**', 'assigned/**']
        ]);

        $this->assertEquals(['mass/**', 'assigned/**'], $service->watch_paths);
    }

    /**
     * Test watch paths update via update method
     */
    public function test_watch_paths_update_method(): void
    {
        $this->service->update([
            'watch_paths' => ['updated/**', 'paths/**']
        ]);

        $this->service->refresh();
        $this->assertEquals(['updated/**', 'paths/**'], $this->service->watch_paths);
    }

    /**
     * Test watch paths with database connection issues
     */
    public function test_watch_paths_retrieval_after_reconnect(): void
    {
        $this->service->watch_paths = ['test/**'];
        $this->service->save();

        // Simulate connection reset
        DB::reconnect();

        $service = Service::find($this->service->id);
        $this->assertEquals(['test/**'], $service->watch_paths);
    }

    /**
     * Test watch paths caching behavior
     */
    public function test_watch_paths_not_cached_incorrectly(): void
    {
        $this->service->watch_paths = ['initial/**'];
        $this->service->save();

        // Direct database update to simulate external change
        DB::table('services')
            ->where('id', $this->service->id)
            ->update(['watch_paths' => json_encode(['external/**'])]);

        // Fresh retrieval should get updated value
        $freshService = Service::find($this->service->id);
        $this->assertEquals(['external/**'], $freshService->watch_paths);
    }

    /**
     * Test watch paths with different JSON encoding options
     */
    public function test_watch_paths_json_encoding_options(): void
    {
        $paths = [
            'path/with/slash/',
            'path\\with\\backslash',
            'path"with"quotes',
            "path'with'quotes"
        ];

        $this->service->watch_paths = $paths;
        $this->service->save();

        $raw = DB::table('services')
            ->where('id', $this->service->id)
            ->value('watch_paths');

        // Verify JSON is valid and decodes correctly
        $decoded = json_decode($raw, true);
        $this->assertEquals($paths, $decoded);

        $this->service->refresh();
        $this->assertEquals($paths, $this->service->watch_paths);
    }

    /**
     * Test watch paths field is included in toArray
     */
    public function test_watch_paths_in_array_representation(): void
    {
        $this->service->watch_paths = ['api/**', 'lib/**'];
        $this->service->save();

        $array = $this->service->toArray();
        $this->assertArrayHasKey('watch_paths', $array);
        $this->assertEquals(['api/**', 'lib/**'], $array['watch_paths']);
    }

    /**
     * Test watch paths field in JSON representation
     */
    public function test_watch_paths_in_json_representation(): void
    {
        $this->service->watch_paths = ['api/**', 'lib/**'];
        $this->service->save();

        $json = $this->service->toJson();
        $decoded = json_decode($json, true);
        
        $this->assertArrayHasKey('watch_paths', $decoded);
        $this->assertEquals(['api/**', 'lib/**'], $decoded['watch_paths']);
    }

    /**
     * Test cloning service preserves watch paths
     */
    public function test_cloning_service_preserves_watch_paths(): void
    {
        $this->service->watch_paths = ['original/**'];
        $this->service->save();

        $cloned = $this->service->replicate();
        $cloned->name = 'Cloned Service';
        $cloned->save();

        $this->assertEquals(['original/**'], $cloned->watch_paths);
    }

    /**
     * Test watch paths with query builder
     */
    public function test_watch_paths_with_query_builder(): void
    {
        $this->service->watch_paths = ['queryable/**'];
        $this->service->save();

        // Test whereNotNull
        $services = Service::whereNotNull('watch_paths')->get();
        $this->assertTrue($services->contains($this->service));

        // Test whereNull
        $emptyService = Service::factory()->create([
            'team_id' => $this->team->id,
            'environment_id' => $this->service->environment_id,
            'server_id' => $this->service->server_id,
            'docker_compose_raw' => 'version: "3.8"',
            'docker_compose' => ['version' => '3.8'],
            'watch_paths' => null
        ]);

        $nullServices = Service::whereNull('watch_paths')->get();
        $this->assertTrue($nullServices->contains($emptyService));
        $this->assertFalse($nullServices->contains($this->service));
    }
}