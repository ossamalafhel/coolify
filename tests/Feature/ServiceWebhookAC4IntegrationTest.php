<?php

namespace Tests\Feature;

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServiceWebhookAC4IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;
    private Server $server;
    private Project $project;
    private Environment $environment;
    private StandaloneDocker $destination;

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
     * Test AC4: Service with null watch_paths triggers deployment normally via GitHub webhook
     * 
     * Given: Service with null watch_paths
     * When: Any file changes in webhook
     * Then: Deployment triggers normally
     */
    public function test_service_with_null_watch_paths_triggers_deployment_on_any_file_change()
    {
        // Create a service with null watch_paths (backward compatibility scenario)
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/legacy-service',
            'git_branch' => 'main',
            'watch_paths' => null, // Explicitly null for backward compatibility
            'manual_webhook_secret_github' => 'legacy-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Create a service application for deployment
        $serviceApp = ServiceApplication::factory()->create([
            'service_id' => $service->id,
            'name' => 'legacy-app',
        ]);

        // Prepare GitHub webhook payload with various file changes
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'commit123legacy',
            'repository' => [
                'full_name' => 'test/legacy-service',
            ],
            'commits' => [
                [
                    'added' => ['README.md'],
                    'removed' => ['old-config.yml'],
                    'modified' => ['frontend/styles.css', 'backend/server.js'],
                ],
            ],
        ];

        // Calculate signature
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'legacy-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'legacy-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert deployment was triggered (not skipped)
        // With null watch_paths, any file change should trigger deployment
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceApp) {
            return $job->applicationId === $serviceApp->id;
        });
    }

    /**
     * Test AC4: Service with empty array watch_paths triggers deployment normally
     * 
     * Given: Service with empty array watch_paths
     * When: Any file changes in webhook
     * Then: Deployment triggers normally
     */
    public function test_service_with_empty_array_watch_paths_triggers_deployment_on_any_file_change()
    {
        // Create a service with empty array watch_paths
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/empty-watch-service',
            'git_branch' => 'main',
            'watch_paths' => [], // Empty array for backward compatibility
            'manual_webhook_secret_github' => 'empty-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Create a service application for deployment
        $serviceApp = ServiceApplication::factory()->create([
            'service_id' => $service->id,
            'name' => 'empty-watch-app',
        ]);

        // Prepare GitHub webhook payload with minimal file changes
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'commit456empty',
            'repository' => [
                'full_name' => 'test/empty-watch-service',
            ],
            'commits' => [
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['LICENSE'],
                ],
            ],
        ];

        // Calculate signature
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'empty-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'empty-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert deployment was triggered even for LICENSE file change
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceApp) {
            return $job->applicationId === $serviceApp->id;
        });
    }

    /**
     * Test mixed scenario: Services with and without watch_paths in same webhook
     */
    public function test_mixed_services_with_and_without_watch_paths_in_same_repository()
    {
        // Create multiple services in the same repository
        
        // Service 1: No watch_paths (null) - should always deploy
        $serviceNull = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/mixed-repo',
            'git_branch' => 'main',
            'watch_paths' => null,
            'manual_webhook_secret_github' => 'mixed-secret',
            'is_auto_deploy_enabled' => true,
        ]);
        $serviceAppNull = ServiceApplication::factory()->create([
            'service_id' => $serviceNull->id,
            'name' => 'null-watch-app',
        ]);

        // Service 2: Empty watch_paths - should always deploy
        $serviceEmpty = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/mixed-repo',
            'git_branch' => 'main',
            'watch_paths' => [],
            'manual_webhook_secret_github' => 'mixed-secret',
            'is_auto_deploy_enabled' => true,
        ]);
        $serviceAppEmpty = ServiceApplication::factory()->create([
            'service_id' => $serviceEmpty->id,
            'name' => 'empty-watch-app',
        ]);

        // Service 3: Specific watch_paths - should only deploy on matching files
        $serviceSpecific = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/mixed-repo',
            'git_branch' => 'main',
            'watch_paths' => ['backend/**/*.js', 'api/**'],
            'manual_webhook_secret_github' => 'mixed-secret',
            'is_auto_deploy_enabled' => true,
        ]);
        $serviceAppSpecific = ServiceApplication::factory()->create([
            'service_id' => $serviceSpecific->id,
            'name' => 'specific-watch-app',
        ]);

        // Webhook payload with changes only to frontend files (not matching specific watch_paths)
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'commit789mixed',
            'repository' => [
                'full_name' => 'test/mixed-repo',
            ],
            'commits' => [
                [
                    'added' => ['frontend/components/Button.jsx'],
                    'removed' => [],
                    'modified' => ['frontend/styles.css', 'README.md'],
                ],
            ],
        ];

        // Calculate signature
        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'mixed-secret');

        // Send webhook request
        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'mixed-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert: Services with null/empty watch_paths should deploy
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceAppNull) {
            return $job->applicationId === $serviceAppNull->id;
        });
        
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceAppEmpty) {
            return $job->applicationId === $serviceAppEmpty->id;
        });
        
        // Assert: Service with specific watch_paths should NOT deploy (files don't match)
        Queue::assertNotPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceAppSpecific) {
            return $job->applicationId === $serviceAppSpecific->id;
        });
    }

    /**
     * Test GitLab webhook with null watch_paths
     */
    public function test_service_with_null_watch_paths_gitlab_webhook()
    {
        // Create a service with null watch_paths for GitLab
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://gitlab.com/test/legacy-service',
            'git_branch' => 'main',
            'watch_paths' => null,
            'manual_webhook_secret_gitlab' => 'gitlab-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Create a service application for deployment
        $serviceApp = ServiceApplication::factory()->create([
            'service_id' => $service->id,
            'name' => 'gitlab-app',
        ]);

        // Prepare GitLab webhook payload
        $payload = [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'after' => 'abc123gitlab',
            'project' => [
                'path_with_namespace' => 'test/legacy-service',
            ],
            'commits' => [
                [
                    'added' => ['docs/README.md'],
                    'modified' => ['config.yml'],
                    'removed' => [],
                ],
            ],
        ];

        // Send webhook request with GitLab secret token
        $response = $this->withHeaders([
            'X-Gitlab-Event' => 'Push Hook',
            'X-Gitlab-Token' => 'gitlab-secret',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/gitlab/events/manual', $payload);

        $response->assertOk();
        
        // Assert deployment was triggered with null watch_paths
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceApp) {
            return $job->applicationId === $serviceApp->id;
        });
    }

    /**
     * Test that Services maintain backward compatibility for existing deployments
     */
    public function test_backward_compatibility_for_services_created_before_watch_paths_feature()
    {
        // Simulate a service created before watch_paths feature was added
        // by creating it without the watch_paths field initially
        $oldService = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/old-service',
            'git_branch' => 'main',
            'manual_webhook_secret_github' => 'old-secret',
            'is_auto_deploy_enabled' => true,
        ]);
        
        // Manually update to ensure watch_paths is null (simulating pre-existing service)
        $oldService->watch_paths = null;
        $oldService->save();

        // Create a service application for deployment
        $serviceApp = ServiceApplication::factory()->create([
            'service_id' => $oldService->id,
            'name' => 'old-app',
        ]);

        // Webhook with any file changes
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'oldcommit123',
            'repository' => [
                'full_name' => 'test/old-service',
            ],
            'commits' => [
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['package.json', 'yarn.lock'],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'old-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'old-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert: Old services without watch_paths should continue to deploy normally
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceApp) {
            return $job->applicationId === $serviceApp->id;
        });
    }

    /**
     * Test edge case: Service with watch_paths containing empty strings
     */
    public function test_service_with_empty_string_watch_paths_triggers_deployment()
    {
        // Create a service with watch_paths containing empty strings (edge case)
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/edge-service',
            'git_branch' => 'main',
            'watch_paths' => ['', ''], // Edge case: array with empty strings
            'manual_webhook_secret_github' => 'edge-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Create a service application for deployment
        $serviceApp = ServiceApplication::factory()->create([
            'service_id' => $service->id,
            'name' => 'edge-app',
        ]);

        // Webhook payload
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'edgecommit123',
            'repository' => [
                'full_name' => 'test/edge-service',
            ],
            'commits' => [
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['any-file.txt'],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'edge-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'edge-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert: Should trigger deployment (empty strings in array are treated as empty watch_paths)
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($serviceApp) {
            return $job->applicationId === $serviceApp->id;
        });
    }

    /**
     * Test Application compatibility: Ensure similar behavior for Applications
     */
    public function test_application_with_null_watch_paths_also_triggers_deployment()
    {
        // Test that Applications also respect the same null/empty watch_paths behavior
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/app-repo',
            'git_branch' => 'main',
            'watch_paths' => null, // Null watch_paths for Application
            'manual_webhook_secret_github' => 'app-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Webhook payload
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'appcommit123',
            'repository' => [
                'full_name' => 'test/app-repo',
            ],
            'commits' => [
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['.gitignore'],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'app-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'app-delivery-id',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Assert: Application with null watch_paths should also deploy
        Queue::assertPushed(ApplicationDeploymentJob::class, function ($job) use ($application) {
            return $job->applicationId === $application->id;
        });
    }
}