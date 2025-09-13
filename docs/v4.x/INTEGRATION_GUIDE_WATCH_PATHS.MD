# 🎯 Watch Paths for Docker Compose Services - Integration Guide

## 🚀 What's New

**Supercharge your Docker Compose deployments with intelligent file-based triggers!** 

We've just shipped an amazing new feature that gives you **surgical precision** over when your services redeploy. No more unnecessary deployments when you update documentation or tests - now you can configure exactly which file changes should trigger a deployment for each service!

### ✨ The Magic

- **🎯 Targeted Deployments**: Only deploy when files that matter to your service change
- **⚡ Resource Efficiency**: Save compute resources by skipping irrelevant deployments  
- **🔧 Flexible Patterns**: Use powerful glob patterns like `src/**/*.js` or `backend/**`
- **🔄 Backward Compatible**: Existing services work exactly as before - no breaking changes!
- **🌐 Multi-Provider Support**: Works with GitHub, GitLab, and Gitea webhooks

## ⚡ Quick Start

**Get up and running in 30 seconds!**

### For Services Without Watch Paths (Default Behavior)
```yaml
# Your service deploys on ANY file change (backward compatible)
services:
  api:
    image: node:18
    # No watch_paths configured = deploys on any change ✅
```

### For Services With Watch Paths  
```php
// In your Service model or configuration
$service->watch_paths = [
    'backend/**',           // Any change in backend/ folder
    'package*.json',        // package.json or package-lock.json
    'docker-compose.yml'    // The compose file itself
];
$service->save();
```

## 🎨 For End Users

### Step 1: Understanding Watch Paths

Watch Paths let you control which file changes trigger deployments. Think of them as **deployment filters**!

| Pattern | What It Matches | Example Files |
|---------|----------------|---------------|
| `src/**` | Everything in src/ | `src/app.js`, `src/components/Button.jsx` |
| `*.json` | JSON files in root | `package.json`, `config.json` |
| `backend/**/*.js` | All JS files in backend/ | `backend/api/users.js` |
| `services/api/**` | Everything in services/api/ | `services/api/v1/routes.js` |

### Step 2: Configure Your Service

When setting up your Docker Compose service in Coolify, you can now add watch paths:

1. Navigate to your service configuration
2. Look for the **Watch Paths** field
3. Add your patterns (one per line):
   ```
   backend/**
   shared/models/**
   package.json
   docker-compose.yml
   ```

### Step 3: How Deployments Work Now

**Without Watch Paths (Default):**
- ✅ Any file change = Deployment triggers
- Perfect for simple services or monorepos

**With Watch Paths:**
- ✅ Matching file changes = Deployment triggers  
- ❌ Non-matching changes = Deployment skipped
- Perfect for microservices and complex projects

### Real-World Example

Imagine you have a monorepo with frontend and backend:

```
project/
├── frontend/
│   ├── src/
│   └── package.json
├── backend/
│   ├── api/
│   └── package.json
├── docs/
└── README.md
```

**Backend Service Watch Paths:**
```
backend/**
shared/**
```

**Result:**
- ✅ Edit `backend/api/users.js` → Backend deploys
- ❌ Edit `frontend/src/App.jsx` → Backend skips (saves resources!)
- ❌ Edit `README.md` → Backend skips

## 🚀 For Developers

### Using the Service Model

```php
use App\Models\Service;

// Create a service with watch paths
$service = Service::create([
    'name' => 'api-service',
    'docker_compose' => $dockerComposeYaml,
    'watch_paths' => [
        'services/api/**',
        'shared/**/*.js',
        'package*.json'
    ],
    // ... other fields
]);

// Check if deployment should trigger
$changedFiles = ['services/api/index.js', 'README.md'];
if ($service->isWatchPathsTriggered($changedFiles)) {
    // Deploy the service
    deployService($service);
}
```

### The `isWatchPathsTriggered` Method

```php
// Method signature
public function isWatchPathsTriggered($filePaths): bool

// Examples
$service->isWatchPathsTriggered('backend/api.js');        // Single file
$service->isWatchPathsTriggered(['file1.js', 'file2.js']); // Multiple files

// Backward compatibility built-in!
// Returns true when watch_paths is null or empty (deploys on any change)
```

### Webhook Integration Example

```php
// In your webhook handler
$payload = $request->json();
$changedFiles = extractChangedFiles($payload);

// Service automatically handles the logic
if ($service->isWatchPathsTriggered($changedFiles)) {
    dispatch(new DeployServiceJob($service));
    return response()->json([
        'status' => 'success',
        'message' => 'Deployment queued.'
    ]);
} else {
    return response()->json([
        'status' => 'skipped',
        'message' => 'Changed files do not match watch paths.',
        'details' => [
            'changed_files' => $changedFiles,
            'watch_paths' => $service->watch_paths
        ]
    ]);
}
```

## ⚙️ Configuration

### Database Migration

The feature adds a `watch_paths` JSON column to the services table:

```php
Schema::table('services', function (Blueprint $table) {
    $table->json('watch_paths')->nullable()->after('docker_compose');
});
```

### Pattern Syntax

Watch Paths use **glob patterns** with these wildcards:

| Pattern | Description | Example |
|---------|------------|---------|
| `*` | Matches any characters (except `/`) | `*.js` matches `app.js` but not `src/app.js` |
| `**` | Matches any directories recursively | `src/**` matches everything under `src/` |
| `?` | Matches single character | `app.?s` matches `app.js`, `app.ts` |
| `[abc]` | Character class | `file[123].js` matches `file1.js`, `file2.js`, `file3.js` |

### Advanced Patterns

```php
$service->watch_paths = [
    // Specific file types in any depth
    'src/**/*.{js,jsx,ts,tsx}',
    
    // Root config files
    '*.config.js',
    'package*.json',
    
    // Specific directories
    'backend/**',
    'shared/models/**',
    
    // Exclude patterns (prefix with !)
    '!**/*.test.js',
    '!docs/**'
];
```

## 🧪 Test Drive

### Testing Your Configuration

1. **Set up watch paths** for your service:
   ```php
   $service->watch_paths = ['backend/**', 'package.json'];
   ```

2. **Simulate a webhook** with different file changes:
   ```php
   // Should trigger deployment
   $service->isWatchPathsTriggered(['backend/api/users.js']); // true
   
   // Should skip deployment  
   $service->isWatchPathsTriggered(['frontend/App.jsx']); // false
   
   // Mixed changes (triggers if ANY file matches)
   $service->isWatchPathsTriggered([
       'frontend/App.jsx',    // no match
       'backend/server.js',   // match!
       'README.md'           // no match
   ]); // true (because backend/server.js matches)
   ```

3. **Check webhook responses** in your deployment logs to see skip messages

### Unit Test Example

```php
public function test_service_watch_paths_filtering()
{
    $service = Service::factory()->create([
        'watch_paths' => ['src/**/*.js', 'config/*.json']
    ]);
    
    // Files that should trigger
    $this->assertTrue($service->isWatchPathsTriggered('src/app.js'));
    $this->assertTrue($service->isWatchPathsTriggered('src/components/Button.js'));
    $this->assertTrue($service->isWatchPathsTriggered('config/database.json'));
    
    // Files that should NOT trigger
    $this->assertFalse($service->isWatchPathsTriggered('README.md'));
    $this->assertFalse($service->isWatchPathsTriggered('tests/unit.test.js'));
    $this->assertFalse($service->isWatchPathsTriggered('src/styles.css'));
}
```

## 💡 Pro Tips

### 1. Monorepo Magic
For monorepos, configure each service to watch only its directory:
```php
// API Service
$apiService->watch_paths = ['services/api/**', 'shared/**'];

// Web Service  
$webService->watch_paths = ['services/web/**', 'shared/**'];

// Admin Service
$adminService->watch_paths = ['services/admin/**', 'shared/**'];
```

### 2. Performance Optimization
Be specific with patterns to minimize unnecessary deployments:
```php
// ❌ Too broad
$service->watch_paths = ['**/*.js'];

// ✅ Targeted
$service->watch_paths = ['src/**/*.js', 'lib/**/*.js'];
```

### 3. Config File Changes
Always include config files that affect your service:
```php
$service->watch_paths = [
    'src/**',
    'package*.json',      // Dependencies
    'tsconfig.json',      // TypeScript config
    '.env.example',       // Environment template
    'docker-compose.yml'  // Container definition
];
```

### 4. Backward Compatibility
Services without watch_paths continue to work exactly as before:
```php
// These are equivalent for deployment behavior:
$service->watch_paths = null;  // Deploys on any change
$service->watch_paths = [];    // Deploys on any change
```

### 5. Debugging Deployments
Check webhook responses to understand why deployments were skipped:
```json
{
    "status": "failed",
    "message": "Changed files do not match watch paths.",
    "details": {
        "changed_files": ["docs/README.md", "tests/feature.test.js"],
        "watch_paths": ["src/**", "config/**"]
    }
}
```

## 🆘 Need Help?

### Common Issues & Solutions

**Issue:** My service isn't deploying when I expect it to
- **Solution:** Check your patterns! Use `**` for recursive matching: `src/**` not `src/*`

**Issue:** Service deploys on every change even with watch_paths
- **Solution:** Verify watch_paths is saved correctly. Empty array `[]` or `null` means "deploy on any change"

**Issue:** Pattern isn't matching my files
- **Solution:** Remember that `*` doesn't match `/`. Use `**` for directory recursion

**Issue:** How do I exclude certain files?
- **Solution:** While basic glob patterns don't support negation, you can be specific about what TO include

### Pattern Examples

| Want to Match | Use Pattern | Example Matches |
|--------------|-------------|-----------------|
| All JS files in src | `src/**/*.js` | `src/app.js`, `src/components/Button.js` |
| Config files in root | `*.config.*` | `webpack.config.js`, `jest.config.json` |
| Specific folder tree | `backend/api/**` | `backend/api/v1/users.js` |
| Multiple extensions | `**/*.{js,jsx,ts,tsx}` | Any JS/TS file |
| Direct children only | `src/*.js` | `src/app.js` (NOT `src/components/app.js`) |

### Checking Service Configuration

```php
// Check current watch_paths
$service = Service::find($id);
dd($service->watch_paths);

// Test with specific files
$testFiles = ['src/app.js', 'README.md'];
$wouldDeploy = $service->isWatchPathsTriggered($testFiles);
echo $wouldDeploy ? "Would deploy" : "Would skip";
```

## 🎉 Summary

Watch Paths give you **complete control** over your Docker Compose service deployments:

- ✅ **Save resources** by skipping unnecessary deployments
- ✅ **Improve velocity** by deploying only what changed
- ✅ **Full backward compatibility** - existing services work unchanged
- ✅ **Simple patterns** that are powerful and flexible
- ✅ **Multi-provider support** for GitHub, GitLab, and Gitea

Start using Watch Paths today and watch your deployment efficiency soar! 🚀

---
*Powered by [Kanpredict](https://kanpredict.com) - Premium AI Development Platform*