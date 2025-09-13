<?php

namespace Tests\Feature;

use App\Models\Service;
use Tests\TestCase;

class ServiceWatchPathsTest extends TestCase
{
    public function test_watch_paths_trigger_correctly()
    {
        $service = new Service();
        
        // Test with no watch paths (should always trigger for backward compatibility)
        $service->watch_paths = null;
        $this->assertTrue($service->isWatchPathsTriggered('any/file.js'));
        
        // Test with empty array (should always trigger for backward compatibility)
        $service->watch_paths = [];
        $this->assertTrue($service->isWatchPathsTriggered('any/file.js'));
        
        // Test with specific patterns
        $service->watch_paths = [
            'services/api/**',
            'shared/**'
        ];
        
        // Should match
        $this->assertTrue($service->isWatchPathsTriggered('services/api/controller.js'));
        $this->assertTrue($service->isWatchPathsTriggered('services/api/models/user.js'));
        $this->assertTrue($service->isWatchPathsTriggered('shared/utils.js'));
        $this->assertTrue($service->isWatchPathsTriggered('shared/components/button.js'));
        
        // Should not match
        $this->assertFalse($service->isWatchPathsTriggered('frontend/app.js'));
        $this->assertFalse($service->isWatchPathsTriggered('backend/server.js'));
        
        // Test with multiple files
        $this->assertTrue($service->isWatchPathsTriggered([
            'frontend/app.js',
            'services/api/test.js'  // This one matches
        ]));
        
        $this->assertFalse($service->isWatchPathsTriggered([
            'frontend/app.js',
            'backend/server.js'
        ]));
        
        // Test with exact multiline pattern from acceptance criteria
        $service->watch_paths = [
            'services/api/**',
            'shared/**'
        ];
        
        // These should match based on the acceptance criteria pattern
        $this->assertTrue($service->isWatchPathsTriggered('services/api/index.js'));
        $this->assertTrue($service->isWatchPathsTriggered('services/api/routes/user.js'));
        $this->assertTrue($service->isWatchPathsTriggered('shared/config.js'));
        $this->assertTrue($service->isWatchPathsTriggered('shared/lib/helper.js'));
    }
}