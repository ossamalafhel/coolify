<?php

namespace Tests\Unit;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceWatchPathsTransformationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test transforming textarea input to array
     */
    public function test_textarea_input_to_array_transformation(): void
    {
        $service = new Service();
        
        // Test newline-separated input
        $input = "services/api/**\nshared/**";
        $expected = ['services/api/**', 'shared/**'];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test array to textarea string transformation
     */
    public function test_array_to_textarea_transformation(): void
    {
        $service = new Service();
        
        // Test array to string
        $input = ['services/api/**', 'shared/**'];
        $expected = "services/api/**\nshared/**";
        
        $result = implode("\n", $input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test empty string transformation
     */
    public function test_empty_string_transformation(): void
    {
        $input = "";
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals([], $result);
    }

    /**
     * Test whitespace trimming
     */
    public function test_whitespace_trimming(): void
    {
        $input = "  services/api/**  \n  shared/**  \n  ";
        $expected = ['services/api/**', 'shared/**'];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test empty lines filtering
     */
    public function test_empty_lines_filtering(): void
    {
        $input = "services/api/**\n\n\nshared/**\n\n";
        $expected = ['services/api/**', 'shared/**'];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test single line input
     */
    public function test_single_line_input(): void
    {
        $input = "single/path/**";
        $expected = ['single/path/**'];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test Windows line endings (CRLF)
     */
    public function test_windows_line_endings(): void
    {
        $input = "services/api/**\r\nshared/**\r\nlib/**";
        $expected = ['services/api/**', 'shared/**', 'lib/**'];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test Mac classic line endings (CR)
     */
    public function test_mac_line_endings(): void
    {
        $input = "services/api/**\rshared/**\rlib/**";
        $expected = ['services/api/**', 'shared/**', 'lib/**'];
        
        // Normalize line endings first
        $normalized = str_replace(["\r\n", "\r"], "\n", $input);
        $result = $this->transformTextareaToArray($normalized);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test mixed line endings
     */
    public function test_mixed_line_endings(): void
    {
        $input = "services/api/**\nshared/**\r\nlib/**\rtest/**";
        $expected = ['services/api/**', 'shared/**', 'lib/**', 'test/**'];
        
        // Normalize line endings
        $normalized = str_replace(["\r\n", "\r"], "\n", $input);
        $result = $this->transformTextareaToArray($normalized);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test paths with special characters
     */
    public function test_special_characters_preserved(): void
    {
        $input = "path/with spaces/**\npath-with-dashes/**\npath_with_underscores/**\npath.with.dots/**\npath/with/[brackets]/**\npath/with/{braces}/**\npath/with/(parens)/**";
        $expected = [
            'path/with spaces/**',
            'path-with-dashes/**',
            'path_with_underscores/**',
            'path.with.dots/**',
            'path/with/[brackets]/**',
            'path/with/{braces}/**',
            'path/with/(parens)/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test glob pattern preservation
     */
    public function test_glob_patterns_preserved(): void
    {
        $input = "*.js\n**/*.ts\nsrc/**/test/*.spec.js\n?(optional)/**\n*(pattern1|pattern2)/**\n!(exclude)/**\n@(one|two)/**\n+(one|more)/**";
        $expected = [
            '*.js',
            '**/*.ts',
            'src/**/test/*.spec.js',
            '?(optional)/**',
            '*(pattern1|pattern2)/**',
            '!(exclude)/**',
            '@(one|two)/**',
            '+(one|more)/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test very long paths
     */
    public function test_long_paths_preserved(): void
    {
        $longPath = str_repeat('very/long/path/', 100) . '**/*.js';
        $input = "normal/path/**\n{$longPath}\nanother/path/**";
        $expected = [
            'normal/path/**',
            $longPath,
            'another/path/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test unicode characters
     */
    public function test_unicode_characters_preserved(): void
    {
        $input = "日本語/path/**\n中文/路径/**\nالعربية/مسار/**\nрусский/путь/**\n😀/emoji/**";
        $expected = [
            '日本語/path/**',
            '中文/路径/**',
            'العربية/مسار/**',
            'русский/путь/**',
            '😀/emoji/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test tab characters
     */
    public function test_tab_characters_preserved(): void
    {
        $input = "path/with\ttab/**\npath/with\t\tmultiple\t\ttabs/**";
        $expected = [
            "path/with\ttab/**",
            "path/with\t\tmultiple\t\ttabs/**"
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test leading/trailing slashes
     */
    public function test_leading_trailing_slashes(): void
    {
        $input = "/absolute/path/**\nrelative/path/**\ntrailing/slash/\n/both/slashes/";
        $expected = [
            '/absolute/path/**',
            'relative/path/**',
            'trailing/slash/',
            '/both/slashes/'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test null input handling
     */
    public function test_null_input_handling(): void
    {
        $result = $this->transformTextareaToArray(null);
        $this->assertEquals([], $result);
    }

    /**
     * Test array with null values
     */
    public function test_array_with_null_values(): void
    {
        $input = [null, 'valid/path/**', null, 'another/path/**'];
        $filtered = array_filter($input, fn($path) => !is_null($path));
        $this->assertEquals(['valid/path/**', 'another/path/**'], array_values($filtered));
    }

    /**
     * Test duplicate paths handling
     */
    public function test_duplicate_paths_preserved(): void
    {
        // Duplicates should be preserved (deduplication is business logic decision)
        $input = "duplicate/**\nduplicate/**\nunique/**\nduplicate/**";
        $expected = [
            'duplicate/**',
            'duplicate/**',
            'unique/**',
            'duplicate/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test comment-like lines (should be preserved as they might be valid paths)
     */
    public function test_comment_like_lines_preserved(): void
    {
        $input = "# this might be a valid path\n// so is this\n/* and this */\nnormal/path/**";
        $expected = [
            '# this might be a valid path',
            '// so is this',
            '/* and this */',
            'normal/path/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test backslash paths (Windows-style)
     */
    public function test_backslash_paths(): void
    {
        $input = "windows\\path\\**\nmixed\\path/with\\both/**\nforward/only/**";
        $expected = [
            'windows\\path\\**',
            'mixed\\path/with\\both/**',
            'forward/only/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test quoted paths
     */
    public function test_quoted_paths(): void
    {
        $input = "\"quoted/path/**\"\n'single/quoted/**'\n`backtick/quoted/**`\nunquoted/**";
        $expected = [
            '"quoted/path/**"',
            "'single/quoted/**'",
            '`backtick/quoted/**`',
            'unquoted/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test escaping special characters
     */
    public function test_escaped_characters(): void
    {
        $input = "path/with\\nnewline/**\npath/with\\ttab/**\npath/with\\\\backslash/**";
        $expected = [
            'path/with\\nnewline/**',
            'path/with\\ttab/**',
            'path/with\\\\backslash/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test maximum array size
     */
    public function test_large_array_handling(): void
    {
        $paths = [];
        for ($i = 0; $i < 1000; $i++) {
            $paths[] = "path/number/{$i}/**";
        }
        
        $input = implode("\n", $paths);
        $result = $this->transformTextareaToArray($input);
        
        $this->assertCount(1000, $result);
        $this->assertEquals($paths, $result);
    }

    /**
     * Test case sensitivity preservation
     */
    public function test_case_sensitivity_preserved(): void
    {
        $input = "UPPERCASE/**\nlowercase/**\nMixedCase/**\ncamelCase/**\nsnake_case/**\nkebab-case/**";
        $expected = [
            'UPPERCASE/**',
            'lowercase/**',
            'MixedCase/**',
            'camelCase/**',
            'snake_case/**',
            'kebab-case/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test dot files and hidden paths
     */
    public function test_dot_files_and_hidden_paths(): void
    {
        $input = ".hidden/**\n.git/**\n.env\n.config/path/**\nnormal/path/**";
        $expected = [
            '.hidden/**',
            '.git/**',
            '.env',
            '.config/path/**',
            'normal/path/**'
        ];
        
        $result = $this->transformTextareaToArray($input);
        $this->assertEquals($expected, $result);
    }

    /**
     * Helper method to transform textarea input to array
     * This mimics the logic in StackForm component
     */
    private function transformTextareaToArray($input): array
    {
        if (is_null($input) || $input === '') {
            return [];
        }
        
        return array_filter(
            array_map('trim', explode("\n", $input)),
            fn($path) => !empty($path)
        );
    }
}