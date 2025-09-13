<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mockery;

class AddWatchPathsToServicesMigrationTest extends TestCase
{
    private $migrationPath = 'database/migrations/2025_01_13_000000_add_watch_paths_to_services.php';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that migration file exists
     */
    public function test_migration_file_exists()
    {
        $fullPath = base_path($this->migrationPath);
        $this->assertFileExists($fullPath, 'Migration file should exist at: ' . $this->migrationPath);
    }

    /**
     * Test migration file returns a valid migration class
     */
    public function test_migration_file_returns_valid_class()
    {
        $migration = require base_path($this->migrationPath);
        
        $this->assertIsObject($migration);
        $this->assertTrue(method_exists($migration, 'up'), 'Migration should have up() method');
        $this->assertTrue(method_exists($migration, 'down'), 'Migration should have down() method');
    }

    /**
     * Test up method checks for existing column
     */
    public function test_up_method_checks_for_existing_column()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        // Expect hasColumn to be called
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->once()
            ->andReturn(false);
        
        // Expect table method to be called for adding column
        $schemaMock->shouldReceive('table')
            ->with('services', Mockery::type('Closure'))
            ->once()
            ->andReturnUsing(function ($table, $callback) {
                $blueprint = Mockery::mock(Blueprint::class);
                $blueprint->shouldReceive('text')
                    ->with('watch_paths')
                    ->once()
                    ->andReturnSelf();
                $blueprint->shouldReceive('nullable')
                    ->once()
                    ->andReturnSelf();
                $blueprint->shouldReceive('after')
                    ->with('docker_compose')
                    ->once()
                    ->andReturnSelf();
                
                $callback($blueprint);
            });
        
        $migration = require base_path($this->migrationPath);
        $migration->up();
        
        // Assertions handled by Mockery expectations
        $this->assertTrue(true);
    }

    /**
     * Test up method handles existing column correctly
     */
    public function test_up_method_handles_existing_column()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        // Expect hasColumn to be called and return true (column exists)
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->twice() // Called in outer check and inner check
            ->andReturn(true);
        
        // Expect table method to be called for modifying column
        $schemaMock->shouldReceive('table')
            ->with('services', Mockery::type('Closure'))
            ->once()
            ->andReturnUsing(function ($table, $callback) {
                $blueprint = Mockery::mock(Blueprint::class);
                $blueprint->shouldReceive('text')
                    ->with('watch_paths')
                    ->once()
                    ->andReturnSelf();
                $blueprint->shouldReceive('nullable')
                    ->once()
                    ->andReturnSelf();
                $blueprint->shouldReceive('change')
                    ->once()
                    ->andReturnSelf();
                
                $callback($blueprint);
            });
        
        $migration = require base_path($this->migrationPath);
        $migration->up();
        
        // Assertions handled by Mockery expectations
        $this->assertTrue(true);
    }

    /**
     * Test down method checks for column existence before dropping
     */
    public function test_down_method_checks_before_dropping()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        // Expect hasColumn to be called
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->once()
            ->andReturn(true);
        
        // Expect table method to be called for dropping column
        $schemaMock->shouldReceive('table')
            ->with('services', Mockery::type('Closure'))
            ->once()
            ->andReturnUsing(function ($table, $callback) {
                $blueprint = Mockery::mock(Blueprint::class);
                $blueprint->shouldReceive('dropColumn')
                    ->with('watch_paths')
                    ->once();
                
                $callback($blueprint);
            });
        
        $migration = require base_path($this->migrationPath);
        $migration->down();
        
        // Assertions handled by Mockery expectations
        $this->assertTrue(true);
    }

    /**
     * Test down method handles non-existent column gracefully
     */
    public function test_down_method_handles_nonexistent_column()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        // Expect hasColumn to be called and return false
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->once()
            ->andReturn(false);
        
        // Should NOT call table method since column doesn't exist
        $schemaMock->shouldNotReceive('table');
        
        $migration = require base_path($this->migrationPath);
        $migration->down();
        
        // Assertions handled by Mockery expectations
        $this->assertTrue(true);
    }

    /**
     * Test migration uses anonymous class
     */
    public function test_migration_uses_anonymous_class()
    {
        $migration = require base_path($this->migrationPath);
        
        // Check that it's an anonymous class (not a named class)
        $reflection = new \ReflectionClass($migration);
        $this->assertTrue($reflection->isAnonymous(), 'Migration should use anonymous class pattern');
    }

    /**
     * Test migration extends Migration base class
     */
    public function test_migration_extends_base_migration_class()
    {
        $migration = require base_path($this->migrationPath);
        
        $this->assertInstanceOf(
            \Illuminate\Database\Migrations\Migration::class,
            $migration,
            'Migration should extend Laravel Migration base class'
        );
    }

    /**
     * Test that text column is used (not json)
     */
    public function test_uses_text_column_type()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->once()
            ->andReturn(false);
        
        $textMethodCalled = false;
        $jsonMethodCalled = false;
        
        $schemaMock->shouldReceive('table')
            ->with('services', Mockery::type('Closure'))
            ->once()
            ->andReturnUsing(function ($table, $callback) use (&$textMethodCalled, &$jsonMethodCalled) {
                $blueprint = Mockery::mock(Blueprint::class);
                
                // Track if text method is called
                $blueprint->shouldReceive('text')
                    ->andReturnUsing(function () use (&$textMethodCalled) {
                        $textMethodCalled = true;
                        return Mockery::self();
                    });
                
                // Track if json method is called (should not be)
                $blueprint->shouldReceive('json')
                    ->andReturnUsing(function () use (&$jsonMethodCalled) {
                        $jsonMethodCalled = true;
                        return Mockery::self();
                    });
                
                $blueprint->shouldReceive('nullable')->andReturnSelf();
                $blueprint->shouldReceive('after')->andReturnSelf();
                
                $callback($blueprint);
            });
        
        $migration = require base_path($this->migrationPath);
        $migration->up();
        
        $this->assertTrue($textMethodCalled, 'Migration should use text() column type');
        $this->assertFalse($jsonMethodCalled, 'Migration should not use json() column type');
    }

    /**
     * Test that column is nullable
     */
    public function test_column_is_nullable()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->once()
            ->andReturn(false);
        
        $nullableCalled = false;
        
        $schemaMock->shouldReceive('table')
            ->with('services', Mockery::type('Closure'))
            ->once()
            ->andReturnUsing(function ($table, $callback) use (&$nullableCalled) {
                $blueprint = Mockery::mock(Blueprint::class);
                
                $blueprint->shouldReceive('text')
                    ->with('watch_paths')
                    ->once()
                    ->andReturnSelf();
                
                // Track if nullable is called
                $blueprint->shouldReceive('nullable')
                    ->once()
                    ->andReturnUsing(function () use (&$nullableCalled) {
                        $nullableCalled = true;
                        return Mockery::self();
                    });
                
                $blueprint->shouldReceive('after')
                    ->with('docker_compose')
                    ->once()
                    ->andReturnSelf();
                
                $callback($blueprint);
            });
        
        $migration = require base_path($this->migrationPath);
        $migration->up();
        
        $this->assertTrue($nullableCalled, 'Column should be nullable');
    }

    /**
     * Test that column is positioned after docker_compose
     */
    public function test_column_positioned_after_docker_compose()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->once()
            ->andReturn(false);
        
        $afterCalledWith = null;
        
        $schemaMock->shouldReceive('table')
            ->with('services', Mockery::type('Closure'))
            ->once()
            ->andReturnUsing(function ($table, $callback) use (&$afterCalledWith) {
                $blueprint = Mockery::mock(Blueprint::class);
                
                $blueprint->shouldReceive('text')
                    ->with('watch_paths')
                    ->once()
                    ->andReturnSelf();
                
                $blueprint->shouldReceive('nullable')
                    ->once()
                    ->andReturnSelf();
                
                // Track what after() is called with
                $blueprint->shouldReceive('after')
                    ->once()
                    ->andReturnUsing(function ($column) use (&$afterCalledWith) {
                        $afterCalledWith = $column;
                        return Mockery::self();
                    });
                
                $callback($blueprint);
            });
        
        $migration = require base_path($this->migrationPath);
        $migration->up();
        
        $this->assertEquals('docker_compose', $afterCalledWith, 'Column should be positioned after docker_compose');
    }

    /**
     * Test migration file naming convention
     */
    public function test_migration_file_naming_convention()
    {
        $filename = basename($this->migrationPath);
        
        // Check format: YYYY_MM_DD_HHMMSS_description.php
        $pattern = '/^\d{4}_\d{2}_\d{2}_\d{6}_add_watch_paths_to_services\.php$/';
        
        $this->assertMatchesRegularExpression(
            $pattern,
            $filename,
            'Migration filename should follow Laravel naming convention'
        );
        
        // Check that the date is 2025_01_13
        $this->assertStringStartsWith('2025_01_13', $filename, 'Migration date should be 2025_01_13');
    }

    /**
     * Test that change() is called when modifying existing column
     */
    public function test_change_called_for_existing_column()
    {
        // Mock Schema facade
        $schemaMock = Mockery::mock('alias:' . Schema::class);
        
        $schemaMock->shouldReceive('hasColumn')
            ->with('services', 'watch_paths')
            ->twice()
            ->andReturn(true);
        
        $changeCalled = false;
        
        $schemaMock->shouldReceive('table')
            ->with('services', Mockery::type('Closure'))
            ->once()
            ->andReturnUsing(function ($table, $callback) use (&$changeCalled) {
                $blueprint = Mockery::mock(Blueprint::class);
                
                $blueprint->shouldReceive('text')
                    ->with('watch_paths')
                    ->once()
                    ->andReturnSelf();
                
                $blueprint->shouldReceive('nullable')
                    ->once()
                    ->andReturnSelf();
                
                // Track if change() is called
                $blueprint->shouldReceive('change')
                    ->once()
                    ->andReturnUsing(function () use (&$changeCalled) {
                        $changeCalled = true;
                        return Mockery::self();
                    });
                
                $callback($blueprint);
            });
        
        $migration = require base_path($this->migrationPath);
        $migration->up();
        
        $this->assertTrue($changeCalled, 'change() should be called when modifying existing column');
    }
}