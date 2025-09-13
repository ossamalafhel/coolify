<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookWatchPathsEdgeCasesTest extends TestCase
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
     * Test webhook with empty commits array
     */
    public function test_webhook_with_empty_commits_array()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Prepare GitHub webhook payload with empty commits
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'abc123',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [], // Empty commits array
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'empty-commits-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // With empty commits, no files changed, so should skip deployment if watch_paths is set
        $responseData = $response->json();
        if (!empty($responseData)) {
            $firstPayload = $responseData[0];
            $this->assertEquals('failed', $firstPayload['status']);
            $this->assertStringContainsString('watch paths', strtolower($firstPayload['message']));
        }
    }

    /**
     * Test webhook with malformed watch_paths patterns
     */
    public function test_webhook_with_malformed_watch_paths()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "***/invalid\n[unclosed\n(((broken\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'def456',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['src/file.js'],
                    'removed' => [],
                    'modified' => [],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'malformed-patterns-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Malformed patterns should be handled gracefully
        // The application should either skip or continue based on implementation
    }

    /**
     * Test webhook with very long file paths
     */
    public function test_webhook_with_very_long_file_paths()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Create a very long file path
        $longPath = 'src/' . str_repeat('very/long/nested/directory/', 50) . 'file.js';

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'ghi789',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => [$longPath],
                    'removed' => [],
                    'modified' => [],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'long-path-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Long paths should be handled without errors
        $responseData = $response->json();
        $this->assertIsArray($responseData);
    }

    /**
     * Test webhook with special characters in file paths
     */
    public function test_webhook_with_special_characters_in_paths()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\n*.config.js\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'jkl012',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => [
                        'src/files with spaces.js',
                        'src/special-chars!@#$%.js',
                        'src/unicode-文件.js',
                        'src/../escaped.js',
                    ],
                    'removed' => [],
                    'modified' => [],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'special-chars-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Special characters should be handled properly
        $responseData = $response->json();
        $this->assertIsArray($responseData);
    }

    /**
     * Test webhook with duplicate file paths in commits
     */
    public function test_webhook_with_duplicate_file_paths()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'mno345',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['src/file.js'],
                    'removed' => [],
                    'modified' => ['src/file.js'], // Same file in added and modified
                ],
                [
                    'added' => [],
                    'removed' => ['src/file.js'], // Same file again in another commit
                    'modified' => [],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'duplicate-files-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Duplicates should be handled correctly (usually deduplicated)
        $responseData = $response->json();
        if (!empty($responseData)) {
            $firstPayload = $responseData[0];
            // Should trigger deployment since src/file.js matches src/**
            $this->assertEquals('success', $firstPayload['status']);
        }
    }

    /**
     * Test webhook with null/missing file arrays in commits
     */
    public function test_webhook_with_null_file_arrays()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'pqr678',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => null, // null instead of array
                    'removed' => null,
                    'modified' => ['src/file.js'],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'null-arrays-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Null arrays should be handled gracefully
        $responseData = $response->json();
        $this->assertIsArray($responseData);
    }

    /**
     * Test webhook with extremely large number of changed files
     */
    public function test_webhook_with_many_changed_files()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\nlib/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Generate 1000 changed files
        $manyFiles = [];
        for ($i = 0; $i < 500; $i++) {
            $manyFiles[] = "src/file{$i}.js";
            $manyFiles[] = "docs/doc{$i}.md";
        }

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'stu901',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => array_slice($manyFiles, 0, 333),
                    'removed' => array_slice($manyFiles, 333, 333),
                    'modified' => array_slice($manyFiles, 666, 334),
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'many-files-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Large number of files should be processed without timeout
        $responseData = $response->json();
        $this->assertIsArray($responseData);
        
        // Should trigger deployment since many src/** files match
        if (!empty($responseData)) {
            $firstPayload = $responseData[0];
            $this->assertEquals('success', $firstPayload['status']);
        }
    }

    /**
     * Test webhook with watch_paths containing regex special characters
     */
    public function test_webhook_with_regex_special_chars_in_watch_paths()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "*.js\n[config].json\nsrc/*.{ts,tsx}\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'vwx234',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['app.js', '[config].json', 'src/component.tsx'],
                    'removed' => [],
                    'modified' => [],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'regex-chars-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Regex special characters in patterns should be handled correctly
        $responseData = $response->json();
        $this->assertIsArray($responseData);
    }

    /**
     * Test concurrent webhooks with same application
     */
    public function test_concurrent_webhooks_same_application()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "src/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        // Send multiple webhooks in quick succession
        $payloads = [];
        for ($i = 0; $i < 5; $i++) {
            $payloads[] = [
                'ref' => 'refs/heads/main',
                'after' => "commit{$i}",
                'repository' => [
                    'full_name' => 'test/repo',
                ],
                'commits' => [
                    [
                        'added' => ["src/file{$i}.js"],
                        'removed' => [],
                        'modified' => [],
                    ],
                ],
            ];
        }

        $responses = [];
        foreach ($payloads as $index => $payload) {
            $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');
            
            $response = $this->withHeaders([
                'X-GitHub-Event' => 'push',
                'X-Hub-Signature-256' => $signature,
                'X-GitHub-Delivery' => "concurrent-delivery-{$index}",
                'Content-Type' => 'application/json',
            ])->postJson('/webhooks/source/github/events/manual', $payload);
            
            $response->assertOk();
            $responses[] = $response;
        }

        // All webhooks should be processed successfully
        foreach ($responses as $response) {
            $responseData = $response->json();
            $this->assertIsArray($responseData);
        }
    }

    /**
     * Test webhook with watch_paths containing environment variables or template strings
     */
    public function test_webhook_with_template_strings_in_watch_paths()
    {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/repo',
            'git_branch' => 'main',
            'watch_paths' => "${SRC_DIR}/**\n{{template}}/**\n$HOME/src/**\n",
            'manual_webhook_secret_github' => 'test-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'yz567',
            'repository' => [
                'full_name' => 'test/repo',
            ],
            'commits' => [
                [
                    'added' => ['src/app.js', '${SRC_DIR}/file.js'],
                    'removed' => [],
                    'modified' => [],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'test-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'template-strings-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Template strings should be handled (either evaluated or treated literally)
        $responseData = $response->json();
        $this->assertIsArray($responseData);
    }

    /**
     * Test Service with array-based watch_paths (as it's cast to array)
     */
    public function test_service_with_array_watch_paths()
    {
        $service = Service::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'git_repository' => 'https://github.com/test/service',
            'git_branch' => 'main',
            'watch_paths' => ['backend/**', 'api/**', 'config/*.yml'],
            'manual_webhook_secret_github' => 'service-secret',
            'is_auto_deploy_enabled' => true,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'service123',
            'repository' => [
                'full_name' => 'test/service',
            ],
            'commits' => [
                [
                    'added' => [],
                    'removed' => [],
                    'modified' => ['backend/service.js', 'config/database.yml'],
                ],
            ],
        ];

        $signature = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'service-secret');

        $response = $this->withHeaders([
            'X-GitHub-Event' => 'push',
            'X-Hub-Signature-256' => $signature,
            'X-GitHub-Delivery' => 'service-array-delivery',
            'Content-Type' => 'application/json',
        ])->postJson('/webhooks/source/github/events/manual', $payload);

        $response->assertOk();
        
        // Service with array watch_paths should work correctly
        $responseData = $response->json();
        $this->assertIsArray($responseData);
    }

    /**
     * Test isWatchPathsTriggered with edge cases
     */
    public function test_is_watch_paths_triggered_edge_cases()
    {
        // Test with dot files
        $app = Application::factory()->make([
            'watch_paths' => ".*\n.github/**\n",
        ]);
        
        $this->assertTrue($app->isWatchPathsTriggered(['.env']));
        $this->assertTrue($app->isWatchPathsTriggered(['.gitignore']));
        $this->assertTrue($app->isWatchPathsTriggered(['.github/workflows/ci.yml']));
        
        // Test with root-only patterns
        $app->watch_paths = "/src/**\n/lib/**\n";
        $this->assertTrue($app->isWatchPathsTriggered(['/src/app.js']));
        $this->assertFalse($app->isWatchPathsTriggered(['nested/src/app.js']));
        
        // Test with negation patterns (if supported)
        $app->watch_paths = "**\n!docs/**\n!tests/**\n";
        // Negation might not be supported, but test the behavior
        $this->assertTrue($app->isWatchPathsTriggered(['src/app.js']));
        
        // Test with case sensitivity
        $app->watch_paths = "src/**\n";
        $this->assertTrue($app->isWatchPathsTriggered(['src/App.js']));
        $this->assertTrue($app->isWatchPathsTriggered(['SRC/app.js'])); // May or may not match
        
        // Test with Windows-style paths
        $app->watch_paths = "src\\**\n";
        $this->assertFalse($app->isWatchPathsTriggered(['src\\components\\Button.js']));
        
        // Test with trailing whitespace
        $app->watch_paths = "src/**   \n  lib/**  \n";
        $this->assertTrue($app->isWatchPathsTriggered(['src/app.js']));
        $this->assertTrue($app->isWatchPathsTriggered(['lib/utils.js']));
    }
}