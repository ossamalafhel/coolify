#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Service;

// Bootstrap Laravel
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Test the specific AC2 scenario
$service = new Service();

// Test case from AC2: Service has watch_paths "services/api/**"
$service->watch_paths = ['services/api/**'];
$files = ['services/api/index.js', 'frontend/app.js'];

echo "Testing AC2 scenario:\n";
echo "Watch paths: " . json_encode($service->watch_paths) . "\n";
echo "Files to check: " . json_encode($files) . "\n\n";

// Test individual files
echo "Test 1 - services/api/index.js (should match): ";
$result1 = $service->isWatchPathsTriggered('services/api/index.js');
echo $result1 ? "✓ PASS (returns true)\n" : "✗ FAIL (returns false)\n";

echo "Test 2 - frontend/app.js (should NOT match): ";
$result2 = $service->isWatchPathsTriggered('frontend/app.js');
echo !$result2 ? "✓ PASS (returns false)\n" : "✗ FAIL (returns true)\n";

echo "Test 3 - Multiple files array (should match because of services/api/index.js): ";
$result3 = $service->isWatchPathsTriggered($files);
echo $result3 ? "✓ PASS (returns true)\n" : "✗ FAIL (returns false)\n";

// Additional test cases for edge scenarios
echo "\nAdditional tests:\n";

// Test with deeper nested path
echo "Test 4 - services/api/v1/users/index.js (should match): ";
$result4 = $service->isWatchPathsTriggered('services/api/v1/users/index.js');
echo $result4 ? "✓ PASS\n" : "✗ FAIL\n";

// Test with file at api root
echo "Test 5 - services/api.js (should NOT match - api.js is not under api/): ";
$result5 = $service->isWatchPathsTriggered('services/api.js');
echo !$result5 ? "✓ PASS\n" : "✗ FAIL\n";

// Test with empty watch_paths (should return false)
$service->watch_paths = [];
echo "\nTest 6 - Empty watch_paths (should return false): ";
$result6 = $service->isWatchPathsTriggered($files);
echo !$result6 ? "✓ PASS\n" : "✗ FAIL\n";

// Test with null watch_paths (should return false)
$service->watch_paths = null;
echo "Test 7 - Null watch_paths (should return false): ";
$result7 = $service->isWatchPathsTriggered($files);
echo !$result7 ? "✓ PASS\n" : "✗ FAIL\n";

echo "\n✅ All AC2 tests completed!\n";