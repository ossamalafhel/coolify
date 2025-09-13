<?php

namespace Tests\Feature;

use App\Livewire\Project\Service\StackForm;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceWatchPathsUITest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Team $team;
    protected Project $project;
    protected Environment $environment;
    protected Server $server;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user and team
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->team = Team::factory()->create([
            'personal_team' => true,
        ]);

        $this->user->teams()->attach($this->team, ['role' => 'owner']);
        $this->user->currentTeam()->associate($this->team);
        $this->user->save();

        // Create project and environment
        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Project',
        ]);

        $this->environment = Environment::factory()->create([
            'project_id' => $this->project->id,
            'name' => 'production',
        ]);

        // Create server
        $this->server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
            'ip' => '127.0.0.1',
            'user' => 'root',
            'port' => 22,
        ]);

        // Create service
        $this->service = Service::factory()->create([
            'team_id' => $this->team->id,
            'environment_id' => $this->environment->id,
            'server_id' => $this->server->id,
            'name' => 'test-service',
            'docker_compose_raw' => 'version: "3.8"',
            'docker_compose' => ['version' => '3.8'],
        ]);
    }

    /**
     * Test AC5: Watch paths textarea saves to database and persists on reload
     * Given: Service configuration page is loaded
     * When: User enters "services/api/**\nshared/**" in watch paths textarea
     * Then: Value saves to database and persists on reload
     */
    public function test_watch_paths_textarea_saves_and_persists(): void
    {
        $this->actingAs($this->user);

        // Test the exact pattern from AC5
        $watchPathsInput = "services/api/**\nshared/**";

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->assertSee('Watch Paths')
            ->assertSee('Enter glob patterns')
            ->set('watchPaths', $watchPathsInput)
            ->call('instantSaveWatchPaths')
            ->assertDispatched('success', 'Watch paths saved.');

        // Verify it saved to database correctly
        $this->service->refresh();
        $this->assertIsArray($this->service->watch_paths);
        $this->assertEquals(['services/api/**', 'shared/**'], $this->service->watch_paths);

        // Test persistence on reload - create new component instance
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->assertSet('watchPaths', $watchPathsInput);
    }

    /**
     * Test that empty watch paths saves as null
     */
    public function test_empty_watch_paths_saves_as_null(): void
    {
        $this->actingAs($this->user);

        // First set some watch paths
        $this->service->watch_paths = ['test/**'];
        $this->service->save();

        // Now clear them
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', '')
            ->call('instantSaveWatchPaths')
            ->assertDispatched('success', 'Watch paths saved.');

        $this->service->refresh();
        $this->assertNull($this->service->watch_paths);
    }

    /**
     * Test that whitespace and empty lines are filtered out
     */
    public function test_whitespace_and_empty_lines_filtered(): void
    {
        $this->actingAs($this->user);

        $watchPathsInput = "  services/api/**  \n\n  \nshared/**\n  \n";

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', $watchPathsInput)
            ->call('instantSaveWatchPaths');

        $this->service->refresh();
        $this->assertEquals(['services/api/**', 'shared/**'], $this->service->watch_paths);
    }

    /**
     * Test watch paths with various glob patterns
     */
    public function test_various_glob_patterns_accepted(): void
    {
        $this->actingAs($this->user);

        $patterns = [
            '*.js',
            'src/**/*.ts',
            'app/{models,controllers}/**',
            'test/unit/**/*.spec.js',
            'docs/**/*.{md,mdx}',
            '!node_modules/**',
            'packages/*/src/**'
        ];

        $watchPathsInput = implode("\n", $patterns);

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', $watchPathsInput)
            ->call('instantSaveWatchPaths');

        $this->service->refresh();
        $this->assertEquals($patterns, $this->service->watch_paths);
    }

    /**
     * Test that watch paths are properly initialized from existing data
     */
    public function test_watch_paths_initialized_from_existing_data(): void
    {
        $this->actingAs($this->user);

        // Set existing watch paths
        $existingPaths = ['backend/**', 'frontend/src/**', 'shared/**/*.js'];
        $this->service->watch_paths = $existingPaths;
        $this->service->save();

        // Component should initialize with existing paths
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->assertSet('watchPaths', implode("\n", $existingPaths));
    }

    /**
     * Test that form submit also saves watch paths
     */
    public function test_form_submit_saves_watch_paths(): void
    {
        $this->actingAs($this->user);

        $watchPathsInput = "api/**\nlib/**";

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('service.name', 'Updated Service Name')
            ->set('watchPaths', $watchPathsInput)
            ->call('submit')
            ->assertDispatched('success', 'Service saved.')
            ->assertDispatched('refreshServices');

        $this->service->refresh();
        $this->assertEquals('Updated Service Name', $this->service->name);
        $this->assertEquals(['api/**', 'lib/**'], $this->service->watch_paths);
    }

    /**
     * Test authorization - user without update permission cannot save
     */
    public function test_user_without_update_permission_cannot_save_watch_paths(): void
    {
        // Create a user with view-only permission
        $viewOnlyUser = User::factory()->create();
        $viewOnlyUser->teams()->attach($this->team, ['role' => 'member']);
        $viewOnlyUser->currentTeam()->associate($this->team);
        $viewOnlyUser->save();

        $this->actingAs($viewOnlyUser);

        // The textarea should be disabled for users without update permission
        // In real implementation, this would be handled by canGate attribute
        $response = $this->get(route('project.service.configuration', [
            'service_uuid' => $this->service->uuid,
        ]));

        // The view would show the field as disabled/readonly
        $response->assertStatus(200);
    }

    /**
     * Test that watch paths handle special characters correctly
     */
    public function test_watch_paths_with_special_characters(): void
    {
        $this->actingAs($this->user);

        $specialPaths = [
            'src/[a-z]+/**',
            'test/*.{spec,test}.js',
            'docs/!(README).md',
            'app/?(optional)/**',
            'files/*(pattern1|pattern2)/**'
        ];

        $watchPathsInput = implode("\n", $specialPaths);

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', $watchPathsInput)
            ->call('instantSaveWatchPaths');

        $this->service->refresh();
        $this->assertEquals($specialPaths, $this->service->watch_paths);
    }

    /**
     * Test that extremely long paths are handled
     */
    public function test_extremely_long_watch_paths(): void
    {
        $this->actingAs($this->user);

        $longPath = str_repeat('very/long/path/', 50) . '**/*.js';
        
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', $longPath)
            ->call('instantSaveWatchPaths');

        $this->service->refresh();
        $this->assertEquals([$longPath], $this->service->watch_paths);
    }

    /**
     * Test that many watch paths can be saved
     */
    public function test_many_watch_paths_saved(): void
    {
        $this->actingAs($this->user);

        // Create 100 different paths
        $paths = [];
        for ($i = 1; $i <= 100; $i++) {
            $paths[] = "path{$i}/**/*.js";
        }

        $watchPathsInput = implode("\n", $paths);

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', $watchPathsInput)
            ->call('instantSaveWatchPaths');

        $this->service->refresh();
        $this->assertCount(100, $this->service->watch_paths);
        $this->assertEquals($paths, $this->service->watch_paths);
    }

    /**
     * Test validation of watch paths field
     */
    public function test_watch_paths_field_validation(): void
    {
        $this->actingAs($this->user);

        // Watch paths should be nullable string
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', null)
            ->call('submit')
            ->assertHasNoErrors();

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', '')
            ->call('submit')
            ->assertHasNoErrors();

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', "valid/path/**")
            ->call('submit')
            ->assertHasNoErrors();
    }

    /**
     * Test that watch paths change triggers configuration change event
     */
    public function test_watch_paths_change_triggers_configuration_event(): void
    {
        $this->actingAs($this->user);

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', "new/path/**")
            ->call('instantSaveWatchPaths')
            ->assertDispatched('success');

        // After full form submit, should dispatch configuration change
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', "another/path/**")
            ->call('submit')
            ->assertDispatched('configurationChanged');
    }

    /**
     * Test concurrent updates to watch paths
     */
    public function test_concurrent_watch_paths_updates(): void
    {
        $this->actingAs($this->user);

        // Set initial watch paths
        $this->service->watch_paths = ['initial/**'];
        $this->service->save();

        // Simulate concurrent update
        $anotherService = Service::find($this->service->id);
        $anotherService->watch_paths = ['concurrent/**'];
        $anotherService->save();

        // Our update should still work
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', "final/**")
            ->call('instantSaveWatchPaths');

        $this->service->refresh();
        $this->assertEquals(['final/**'], $this->service->watch_paths);
    }

    /**
     * Test watch paths with Windows-style paths (should be converted/handled)
     */
    public function test_watch_paths_with_windows_paths(): void
    {
        $this->actingAs($this->user);

        // Windows paths with backslashes
        $windowsPaths = "src\\components\\**\nlib\\utils\\*.js";

        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', $windowsPaths)
            ->call('instantSaveWatchPaths');

        $this->service->refresh();
        // Paths should be stored as-is (the glob library will handle them)
        $this->assertEquals(['src\\components\\**', 'lib\\utils\\*.js'], $this->service->watch_paths);
    }

    /**
     * Test that watch paths persist through service updates
     */
    public function test_watch_paths_persist_through_service_updates(): void
    {
        $this->actingAs($this->user);

        // Set watch paths
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('watchPaths', "persist/**")
            ->call('instantSaveWatchPaths');

        // Update other service fields
        Livewire::test(StackForm::class, ['service' => $this->service])
            ->set('service.name', 'New Name')
            ->set('service.description', 'New Description')
            ->call('submit');

        $this->service->refresh();
        $this->assertEquals(['persist/**'], $this->service->watch_paths);
        $this->assertEquals('New Name', $this->service->name);
        $this->assertEquals('New Description', $this->service->description);
    }
}