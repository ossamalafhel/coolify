<?php

namespace Tests\Browser;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ServiceWatchPathsUITest extends DuskTestCase
{
    use DatabaseMigrations;

    protected User $user;
    protected Team $team;
    protected Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user and team
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->team = Team::factory()->create([
            'personal_team' => true,
        ]);

        $this->user->teams()->attach($this->team, ['role' => 'owner']);
        $this->user->currentTeam()->associate($this->team);
        $this->user->save();

        // Create project structure
        $project = Project::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Project',
        ]);

        $environment = Environment::factory()->create([
            'project_id' => $project->id,
            'name' => 'production',
        ]);

        $server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
            'ip' => '127.0.0.1',
        ]);

        $this->service = Service::factory()->create([
            'team_id' => $this->team->id,
            'environment_id' => $environment->id,
            'server_id' => $server->id,
            'name' => 'test-service',
            'docker_compose_raw' => 'version: "3.8"',
            'docker_compose' => ['version' => '3.8'],
        ]);
    }

    /**
     * Test AC5: User can enter watch paths in textarea and they persist
     */
    public function test_user_can_enter_watch_paths_and_persist(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->assertSee('Service Stack')
                ->assertSee('Watch Paths')
                ->assertVisible('textarea#watchPaths')
                ->assertAttribute('textarea#watchPaths', 'placeholder', 'services/api/**
shared/**')
                ->type('textarea#watchPaths', "services/api/**\nshared/**")
                ->pause(1000) // Wait for auto-save
                ->assertSee('Watch paths saved.')
                ->refresh()
                ->assertValue('textarea#watchPaths', "services/api/**\nshared/**");
        });

        // Verify database persistence
        $this->service->refresh();
        $this->assertEquals(['services/api/**', 'shared/**'], $this->service->watch_paths);
    }

    /**
     * Test clearing watch paths
     */
    public function test_user_can_clear_watch_paths(): void
    {
        // Set initial watch paths
        $this->service->watch_paths = ['initial/**', 'paths/**'];
        $this->service->save();

        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->assertValue('textarea#watchPaths', "initial/**\npaths/**")
                ->clear('textarea#watchPaths')
                ->pause(1000) // Wait for auto-save
                ->assertSee('Watch paths saved.')
                ->refresh()
                ->assertValue('textarea#watchPaths', '');
        });

        $this->service->refresh();
        $this->assertNull($this->service->watch_paths);
    }

    /**
     * Test adding multiple watch paths patterns
     */
    public function test_multiple_watch_paths_patterns(): void
    {
        $this->browse(function (Browser $browser) {
            $patterns = [
                'services/api/**',
                'shared/**',
                'lib/*.js',
                'src/**/*.ts',
                'test/**/*.spec.js'
            ];
            $input = implode("\n", $patterns);

            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', $input)
                ->pause(1000)
                ->assertSee('Watch paths saved.')
                ->refresh()
                ->assertValue('textarea#watchPaths', $input);
        });
    }

    /**
     * Test that watch paths textarea shows helper text
     */
    public function test_watch_paths_helper_text_displayed(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->assertSee('Enter glob patterns (one per line) to watch for file changes')
                ->assertSee('Use ** for recursive matching');
        });
    }

    /**
     * Test watch paths with save button
     */
    public function test_watch_paths_save_with_form_submit(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', "form/submit/**")
                ->type('input#service\\.name', 'Updated Service')
                ->press('Save')
                ->pause(1000)
                ->assertSee('Service saved.')
                ->refresh()
                ->assertValue('textarea#watchPaths', "form/submit/**")
                ->assertValue('input#service\\.name', 'Updated Service');
        });

        $this->service->refresh();
        $this->assertEquals(['form/submit/**'], $this->service->watch_paths);
        $this->assertEquals('Updated Service', $this->service->name);
    }

    /**
     * Test watch paths textarea is disabled for users without permission
     */
    public function test_watch_paths_disabled_for_readonly_users(): void
    {
        // Create viewer user
        $viewer = User::factory()->create([
            'email' => 'viewer@example.com',
            'password' => bcrypt('password'),
        ]);
        $viewer->teams()->attach($this->team, ['role' => 'viewer']);
        $viewer->currentTeam()->associate($this->team);
        $viewer->save();

        $this->browse(function (Browser $browser) use ($viewer) {
            $browser->loginAs($viewer)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->assertPresent('textarea#watchPaths[disabled]')
                ->assertAttribute('textarea#watchPaths', 'disabled', 'true');
        });
    }

    /**
     * Test watch paths with keyboard shortcuts
     */
    public function test_watch_paths_keyboard_navigation(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->click('textarea#watchPaths')
                ->keys('textarea#watchPaths', 'path1/**')
                ->keys('textarea#watchPaths', '{enter}')
                ->keys('textarea#watchPaths', 'path2/**')
                ->keys('textarea#watchPaths', '{enter}')
                ->keys('textarea#watchPaths', 'path3/**')
                ->pause(1000)
                ->assertValue('textarea#watchPaths', "path1/**\npath2/**\npath3/**");
        });
    }

    /**
     * Test watch paths with copy paste
     */
    public function test_watch_paths_copy_paste(): void
    {
        $this->browse(function (Browser $browser) {
            $pasteContent = "pasted/path1/**\npasted/path2/**\npasted/path3/**";
            
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->click('textarea#watchPaths')
                ->keys('textarea#watchPaths', ['{control}', 'a']) // Select all
                ->type('textarea#watchPaths', $pasteContent)
                ->pause(1000)
                ->assertValue('textarea#watchPaths', $pasteContent);
        });
    }

    /**
     * Test watch paths textarea responsiveness
     */
    public function test_watch_paths_textarea_responsive(): void
    {
        $this->browse(function (Browser $browser) {
            // Desktop view
            $browser->loginAs($this->user)
                ->resize(1920, 1080)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->assertVisible('textarea#watchPaths');

            // Tablet view
            $browser->resize(768, 1024)
                ->assertVisible('textarea#watchPaths');

            // Mobile view
            $browser->resize(375, 667)
                ->assertVisible('textarea#watchPaths');
        });
    }

    /**
     * Test watch paths with very long input
     */
    public function test_watch_paths_with_long_input(): void
    {
        $this->browse(function (Browser $browser) {
            // Generate 50 paths
            $paths = [];
            for ($i = 1; $i <= 50; $i++) {
                $paths[] = "very/long/path/number/{$i}/**/*.js";
            }
            $longInput = implode("\n", $paths);

            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', $longInput)
                ->pause(1500) // Longer pause for large input
                ->assertSee('Watch paths saved.');

            // Verify scrollbar appears for long content
            $browser->assertScript('document.querySelector("textarea#watchPaths").scrollHeight > document.querySelector("textarea#watchPaths").clientHeight');
        });
    }

    /**
     * Test watch paths auto-save on blur
     */
    public function test_watch_paths_auto_save_on_blur(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', "blur/test/**")
                ->click('input#service\\.name') // Click elsewhere to trigger blur
                ->pause(1000)
                ->assertSee('Watch paths saved.')
                ->refresh()
                ->assertValue('textarea#watchPaths', "blur/test/**");
        });
    }

    /**
     * Test watch paths with special characters in UI
     */
    public function test_watch_paths_special_characters_ui(): void
    {
        $this->browse(function (Browser $browser) {
            $specialPaths = "path/with spaces/**\npath-with-dashes/**\npath_with_underscores/**\npath.with.dots/**\npath/with/[brackets]/**";
            
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', $specialPaths)
                ->pause(1000)
                ->assertSee('Watch paths saved.')
                ->refresh()
                ->assertValue('textarea#watchPaths', $specialPaths);
        });
    }

    /**
     * Test watch paths with concurrent edits
     */
    public function test_watch_paths_concurrent_edits_warning(): void
    {
        $this->browse(function (Browser $first, Browser $second) {
            // First user starts editing
            $first->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', "first/user/**");

            // Second user (same account, different session) edits
            $second->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', "second/user/**")
                ->pause(1000);

            // First user saves after second
            $first->pause(1000)
                ->refresh()
                ->assertValue('textarea#watchPaths', "second/user/**");
        });
    }

    /**
     * Test watch paths validation feedback
     */
    public function test_watch_paths_validation_feedback(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->type('textarea#watchPaths', "valid/path/**")
                ->pause(1000)
                ->assertSee('Watch paths saved.')
                ->assertDontSee('error')
                ->assertDontSee('invalid');
        });
    }

    /**
     * Test watch paths with tab navigation
     */
    public function test_watch_paths_tab_navigation(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->loginAs($this->user)
                ->visit("/project/{$this->service->environment->project->uuid}/{$this->service->environment->name}/service/{$this->service->uuid}")
                ->click('input#service\\.name')
                ->keys('input#service\\.name', '{tab}') // Tab to description
                ->keys('', '{tab}') // Tab to next field (might be checkbox)
                ->keys('', '{tab}') // Tab to watch paths
                ->assertFocused('textarea#watchPaths')
                ->type('', "tabbed/input/**")
                ->pause(1000)
                ->assertValue('textarea#watchPaths', "tabbed/input/**");
        });
    }
}