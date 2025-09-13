<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookWatchPathsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private Server $server;
    private Project $project;
    private Environment $environment;
    private StandaloneDocker $destination;
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        
        Queue::fake();
        
        // Set up basic infrastructure
        $this->team = Team::factory()->create();
        $this->server = Server::factory()->create([
            'team_id' => $this->team->id,
            'is_functional' => true,
        ]);
        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
        ]);
        $this->environment = Environment::factory()->create([
            'project_id' => $this->project->id,
        ]);
        $this->destination = StandaloneDocker::factory()->create([
            'server_id' => $this->server->id,
        ]);
    }

    /**
     * Test AC3: GitHub webhook with watch_paths - deployment skipped when files don't match
     */
    public function test_github_webhook_skips_deployment_when_files_dont_match_watch_paths()
    {
        // Create an application with watch_paths configured for backend/**
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "backend/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Prepare GitHub webhook payload with changes only to frontend/index.js
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'abc123',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['frontend/index.js'],
                    'removed' => [],
                    'modified' => [],
                ],
            ],
        ];

        // Calculate signature
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'test-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert response contains the skip message
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        
        $firstPayload = $responseData[0];
        $this->assertEquals('failed', $firstPayload['status']);
        $this->assertEquals('Changed files do not match watch paths. Ignoring deployment.', $firstPayload['message']);
        $this->assertEquals($this->application->uuid, $firstPayload['application_uuid']);
        $this->assertEquals($this->application->name, $firstPayload['application_name']);
        
        // Verify details contain the changed files and watch paths
        $this->assertArrayHasKey('details', $firstPayload);
        $this->assertArrayHasKey('changed_files', $firstPayload['details']);
        $this->assertArrayHasKey('watch_paths', $firstPayload['details']);
        $this->assertContains('frontend/index.js', $firstPayload['details']['changed_files']);
        $this->assertContains('backend/**', $firstPayload['details']['watch_paths']);
        
        // Verify no deployment job was queued
        Queue::assertNothingPushed();
    }

    /**
     * Test GitHub webhook with watch_paths - deployment triggered when files match
     */
    public function test_github_webhook_triggers_deployment_when_files_match_watch_paths()
    {
        // Create an application with watch_paths configured for backend/**
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "backend/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Prepare GitHub webhook payload with changes to backend/api.js
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'abc123',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['backend/api.js', 'backend/models/user.js'],
                ],
            ],
        ];

        // Calculate signature
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'test-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert response contains success message
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        
        $firstPayload = $responseData[0];
        $this->assertEquals('success', $firstPayload['status']);
        $this->assertEquals('Deployment queued.', $firstPayload['message']);
        $this->assertEquals($this->application->uuid, $firstPayload['application_uuid']);
        $this->assertEquals($this->application->name, $firstPayload['application_name']);
        $this->assertArrayHasKey('deployment_uuid', $firstPayload);
    }

    /**
     * Test GitLab webhook with watch_paths - deployment skipped when files don't match
     */
    public function test_gitlab_webhook_skips_deployment_when_files_dont_match_watch_paths()
    {
        // Create an application with watch_paths configured for src/**
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://gitlab.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\n",
            'manual_webhook_secret_gitlab' => 'gitlab-token',
            'is_auto_deploy_enabled' => true,
        ]);

        // Prepare GitLab webhook payload with changes only to docs/README.md
        $payload = [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'after' => 'def456',
            'project' => [
                'path_with_namespace' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['docs/README.md'],
                ],
            ],
        ];

        // Send webhook request
        $response = $this->withHeaders([
            'X-Gitlab-Token' => 'gitlab-token',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/gitlab/events/manual', $payload);

        $response->assertOk();
        
        // Assert response contains the skip message
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        
        $firstPayload = $responseData[0];
        $this->assertEquals('failed', $firstPayload['status']);
        $this->assertEquals('Changed files do not match watch paths. Ignoring deployment.', $firstPayload['message']);
        $this->assertEquals($this->application->uuid, $firstPayload['application_uuid']);
        $this->assertEquals($this->application->name, $firstPayload['application_name']);
        
        // Verify details contain the changed files and watch paths
        $this->assertArrayHasKey('details', $firstPayload);
        $this->assertArrayHasKey('changed_files', $firstPayload['details']);
        $this->assertArrayHasKey('watch_paths', $firstPayload['details']);
        $this->assertContains('docs/README.md', $firstPayload['details']['changed_files']);
        $this->assertContains('src/**', $firstPayload['details']['watch_paths']);
    }

    /**
     * Test Gitea webhook with watch_paths - deployment skipped when files don't match
     */
    public function test_gitea_webhook_skips_deployment_when_files_dont_match_watch_paths()
    {
        // Create an application with watch_paths configured for app/**
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://gitea.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "app/**\nlib/**\n",
            'manual_webhook_secret_gitea' => 'gitea-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Prepare Gitea webhook payload with changes only to test/spec.js
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'ghi789',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['test/spec.js'],
                    'removed' => [],
                    'modified' => ['test/helper.js'],
                ],
            ],
        ];

        // Calculate signature (Gitea uses same format as GitHub)
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'gitea-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-Gitea-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-Gitea-Delivery' => 'gitea-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/gitea/events/manual', $payload);

        $response->assertOk();
        
        // Assert response contains the skip message
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        
        $firstPayload = $responseData[0];
        $this->assertEquals('failed', $firstPayload['status']);
        $this->assertEquals('Changed files do not match watch paths. Ignoring deployment.', $firstPayload['message']);
        $this->assertEquals($this->application->uuid, $firstPayload['application_uuid']);
        $this->assertEquals($this->application->name, $firstPayload['application_name']);
        
        // Verify details
        $this->assertArrayHasKey('details', $firstPayload);
        $this->assertContains('test/spec.js', $firstPayload['details']['changed_files']);
        $this->assertContains('test/helper.js', $firstPayload['details']['changed_files']);
        $this->assertContains('app/**', $firstPayload['details']['watch_paths']);
        $this->assertContains('lib/**', $firstPayload['details']['watch_paths']);
    }

    /**
     * Test webhook with null watch_paths always triggers deployment
     */
    public function test_webhook_with_null_watch_paths_always_triggers_deployment()
    {
        // Create an application without watch_paths configured
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => null,
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Prepare GitHub webhook payload with any changes
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'xyz987',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['random/file.txt'],
                    'removed' => [],
                    'modified' => [],
                ],
            ],
        ];

        // Calculate signature
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'test-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert deployment was triggered
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        
        $firstPayload = $responseData[0];
        $this->assertEquals('success', $firstPayload['status']);
        $this->assertEquals('Deployment queued.', $firstPayload['message']);
    }

    /**
     * Test complex watch_paths patterns with multiple commits
     */
    public function test_webhook_with_complex_watch_paths_and_multiple_commits()
    {
        // Create an application with complex watch_paths
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\npackage.json\n*.config.js\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Test case 1: Changes match watch_paths
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'commit1',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['src/components/Button.js'],
                    'removed' => [],
                    'modified' => [],
                ],
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['webpack.config.js', 'README.md'],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'test-delivery-1',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Should trigger deployment because webpack.config.js matches *.config.js
        $responseData = $response->json();
        $this->assertEquals('success', $responseData[0]['status']);
        $this->assertEquals('Deployment queued.', $responseData[0]['message']);

        // Test case 2: Changes don't match watch_paths
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'commit2',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['docs/guide.md'],
                    'removed' => ['tests/old-test.js'],
                    'modified' => ['README.md', '.gitignore'],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'test-delivery-2',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Should skip deployment because no files match watch_paths
        $responseData = $response->json();
        $this->assertEquals('failed', $responseData[0]['status']);
        $this->assertEquals('Changed files do not match watch paths. Ignoring deployment.', $responseData[0]['message']);
    }

    /**
     * Test that pull request webhooks are not affected by watch_paths
     */
    public function test_pull_request_webhook_ignores_watch_paths()
    {
        // Create an application with watch_paths
        $this->application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "backend/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_pr_deploy_enabled' => true,
        ]);

        // Prepare GitHub pull request webhook payload
        $payload = [
            'action' => 'opened',
            'number' => 123,
            'pull_request' => [
                'head' => [
                    'ref' => 'feature-branch',
                    'sha' => 'pr-commit-sha',
                ],
                'base' => [
                    'ref' => 'main',
                ],
                'html_url' => 'https://github.com/test/repo/pull/123',
            ],
            'repository' => [
                'full_name' => 'test/repo',
            ],
        ];

        // Calculate signature
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-GitHub-Event' => 'pull_request',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'pr-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // PR deployments should work regardless of watch_paths
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        $this->assertNotEmpty($responseData);
        
        $firstPayload = $responseData[0];
        $this->assertEquals('success', $firstPayload['status']);
        $this->assertEquals('Preview deployment queued.', $firstPayload['message']);
    }
}