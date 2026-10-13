<?php
declare(strict_types=1);

/**
 * Security Tests for Persian Ticketing System
 * 
 * Run with: php tests/SecurityTest.php
 */

class SecurityTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function run(): void
    {
        echo "=== Security Tests ===\n\n";
        
        $this->testConfigSecurity();
        $this->testInstallerAccess();
        $this->testFreshSchemaCompatibility();
        $this->testRebuildCompleteness();
        $this->testCsrfProtection();
        $this->testRateLimiting();
        $this->testInputValidation();
        $this->testFileUploadSecurity();
        $this->testSqlInjectionPrevention();
        
        echo "\n=== Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        
        if ($this->failed > 0) {
            echo "\nErrors:\n";
            foreach ($this->errors as $error) {
                echo "  - {$error}\n";
            }
            exit(1);
        }
        
        echo "\nAll tests passed!\n";
    }

    private function testConfigSecurity(): void
    {
        echo "Testing config security...\n";

        $root = dirname(__DIR__);
        $sample = $root . '/config.sample.php';
        $envExample = $root . '/.env.example';
        $htaccess = $root . '/.htaccess';
        if (!is_file($sample) || !is_file($envExample) || !is_file($htaccess)) {
            $this->fail('A safe configuration example or access rule is missing');
            return;
        }

        $sampleConfig = file_get_contents($sample);
        if (!is_string($sampleConfig) || !str_contains($sampleConfig, "'inventory_token' => ''")) {
            $this->fail('Configuration example must not contain a reusable inventory token');
            return;
        }

        $rules = file_get_contents($htaccess);
        if (!is_string($rules) || !str_contains($rules, 'config\\.php') || !str_contains($rules, 'Require all denied')) {
            $this->fail('Apache does not deny direct access to config.php');
            return;
        }

        $environment = file_get_contents($envExample);
        if (!is_string($environment) || !str_contains($environment, 'DB_PASSWORD=') || !str_contains($environment, 'LDAP_BIND_PASSWORD=')) {
            $this->fail('.env.example is missing expected empty secret fields');
            return;
        }

        $this->pass();
    }

    private function testFreshSchemaCompatibility(): void
    {
        echo "Testing fresh schema portability...\n";

        $schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
        if (!is_string($schema)
            || str_contains(strtoupper($schema), 'CREATE INDEX IF NOT EXISTS')
            || str_contains(strtoupper($schema), 'ADD COLUMN IF NOT EXISTS')
            || !str_contains($schema, 'ad_dn VARCHAR(255) NULL')) {
            $this->fail('Fresh schema contains non-portable conditional DDL or lacks domain-scan columns');
            return;
        }

        $this->pass();
    }

    private function testRebuildCompleteness(): void
    {
        echo "Testing destructive rebuild table coverage...\n";

        $root = dirname(__DIR__);
        $schema = file_get_contents($root . '/schema.sql');
        $rebuild = file_get_contents($root . '/rebuild-final.sql');
        if (!is_string($schema) || !is_string($rebuild)
            || !preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z][a-z0-9_]*)/i', $schema, $schemaMatches)
            || !preg_match('/DROP TABLE IF EXISTS(.*?);/is', $rebuild, $dropMatch)) {
            $this->fail('Could not read the schema or rebuild table list');
            return;
        }

        preg_match_all('/^\s*([a-z][a-z0-9_]*)\s*,?\s*$/im', trim($dropMatch[1]), $dropMatches);
        $dropped = array_fill_keys(array_map('strtolower', $dropMatches[1]), true);
        $missing = array_values(array_filter($schemaMatches[1], static fn (string $table): bool => !isset($dropped[strtolower($table)])));
        if ($missing !== []) {
            $this->fail('Rebuild leaves schema tables behind: ' . implode(', ', $missing));
            return;
        }

        $this->pass();
    }

    private function testInstallerAccess(): void
    {
        echo "Testing installer access controls...\n";

        $installer = file_get_contents(__DIR__ . '/../install.php');
        if (!is_string($installer)
            || !str_contains($installer, "['127.0.0.1', '::1']")
            || !str_contains($installer, 'is_file($configFile) || is_file($lockFile)')
            || !str_contains($installer, '(string) $_GET[\'force\'] === \'1\'')
            || !str_contains($installer, 'hash_equals($name, $dropConfirmation)')
            || !str_contains($installer, "['mysql', 'information_schema', 'performance_schema', 'sys']")
            || !str_contains($installer, "SHOW TABLES")) {
            $this->fail('Installer is missing local-only, installed-state, destructive-confirmation, or empty-database checks');
            return;
        }

        $this->pass();
    }

    private function testCsrfProtection(): void
    {
        echo "Testing CSRF protection...\n";
        
        $bootstrap = file_get_contents(__DIR__ . '/../bootstrap.php');
        if (!is_string($bootstrap) || !str_contains($bootstrap, 'function csrf_token(')) {
            $this->fail('csrf_token function not found');
            return;
        }
        if (!str_contains($bootstrap, 'function require_csrf(')) {
            $this->fail('require_csrf function not found');
            return;
        }
        
        $this->pass();
    }

    private function testRateLimiting(): void
    {
        echo "Testing rate limiting...\n";
        
        $bootstrap = file_get_contents(__DIR__ . '/../bootstrap.php');
        if (!is_string($bootstrap) || !str_contains($bootstrap, 'function api_rate_limit(')) {
            $this->fail('api_rate_limit function not found');
            return;
        }
        if (!str_contains($bootstrap, 'function check_api_rate_limit(')) {
            $this->fail('check_api_rate_limit function not found');
            return;
        }
        
        $this->pass();
    }

    private function testInputValidation(): void
    {
        echo "Testing input validation...\n";
        
        // Test that validation functions exist in index.php
        $indexContent = file_get_contents(__DIR__ . '/../index.php');
        
        if (strpos($indexContent, 'function valid_choice') === false) {
            $this->fail('valid_choice function not found in index.php');
            return;
        }
        
        if (strpos($indexContent, 'function valid_iranian_national_code') === false) {
            $this->fail('valid_iranian_national_code function not found in index.php');
            return;
        }
        
        $this->pass();
    }

    private function testFileUploadSecurity(): void
    {
        echo "Testing file upload security...\n";
        
        // Test that upload functions exist in index.php
        $indexContent = file_get_contents(__DIR__ . '/../index.php');
        
        if (strpos($indexContent, 'function store_attachment') === false) {
            $this->fail('store_attachment function not found in index.php');
            return;
        }
        
        if (strpos($indexContent, 'function store_profile_photo') === false) {
            $this->fail('store_profile_photo function not found in index.php');
            return;
        }
        
        $this->pass();
    }

    private function testSqlInjectionPrevention(): void
    {
        echo "Testing SQL injection prevention...\n";
        
        // Test that PDO is used (check bootstrap for PDO usage)
        $bootstrap = file_get_contents(__DIR__ . '/../bootstrap.php');
        
        if (strpos($bootstrap, 'PDO::ATTR_EMULATE_PREPARES') === false) {
            $this->fail('PDO emulation not disabled');
            return;
        }
        
        if (strpos($bootstrap, 'PDO::ERRMODE_EXCEPTION') === false) {
            $this->fail('PDO error mode not set to exception');
            return;
        }
        
        $this->pass();
    }

    private function pass(): void
    {
        $this->passed++;
        echo "  ✓ Passed\n";
    }

    private function fail(string $message): void
    {
        $this->failed++;
        $this->errors[] = $message;
        echo "  ✗ Failed: {$message}\n";
    }
}

// Run tests
$test = new SecurityTest();
$test->run();
