<?php
declare(strict_types=1);

/**
 * Architecture Tests for Persian Ticketing System
 * 
 * Run with: php tests/ArchitectureTest.php
 */

class ArchitectureTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $errors = [];

    public function run(): void
    {
        echo "=== Architecture Tests ===\n\n";
        
        $this->testMvcStructure();
        $this->testCoreClasses();
        $this->testControllers();
        $this->testViews();
        $this->testRoutes();
        
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

    private function testMvcStructure(): void
    {
        echo "Testing application structure...\n";
        
        $requiredDirs = [
            'api',
            'assets',
            'cron',
            'storage',
            'tests',
            'tools',
        ];
        
        foreach ($requiredDirs as $dir) {
            $path = __DIR__ . '/../' . $dir;
            if (!is_dir($path)) {
                $this->fail("Directory {$dir} not found");
                return;
            }
        }
        
        $this->pass();
    }

    private function testCoreClasses(): void
    {
        echo "Testing installer and application entry files...\n";
        
        $requiredFiles = [
            'index.php',
            'bootstrap.php',
            'install.php',
            'schema.sql',
            'config.sample.php',
            '.htaccess',
            'storage/.htaccess',
        ];
        
        foreach ($requiredFiles as $file) {
            $path = __DIR__ . '/../' . $file;
            if (!file_exists($path)) {
                $this->fail("Required file {$file} not found");
                return;
            }
        }
        
        $this->pass();
    }

    private function testControllers(): void
    {
        echo "Testing application modules...\n";
        
        $requiredModules = [
            'inventory.php',
            'domain-scan.php',
            'organization.php',
            'backup.php',
            'cd-dvd.php',
            'food-ticket.php',
            'traffic-control.php',
        ];
        
        foreach ($requiredModules as $module) {
            $path = __DIR__ . '/../' . $module;
            if (!file_exists($path)) {
                $this->fail("Application module {$module} not found");
                return;
            }
        }
        
        $this->pass();
    }

    private function testViews(): void
    {
        echo "Testing interface assets...\n";
        
        $requiredAssets = [
            'assets/style.css',
            'assets/app.js',
            'assets/cd-dvd.js',
            'assets/fonts/Vazirmatn-Regular.woff2',
            'food-ticket-web/index.html',
        ];
        
        foreach ($requiredAssets as $asset) {
            $path = __DIR__ . '/../' . $asset;
            if (!file_exists($path)) {
                $this->fail("Interface asset {$asset} not found");
                return;
            }
        }
        
        $this->pass();
    }

    private function testRoutes(): void
    {
        echo "Testing routes...\n";
        
        $indexFile = __DIR__ . '/../index.php';
        if (!file_exists($indexFile)) {
            $this->fail('Application entry point not found');
            return;
        }
        
        $content = file_get_contents($indexFile);
        if (!is_string($content)
            || !str_contains($content, "\$page = (string) (\$_GET['page'] ?? (")
            || !str_contains($content, "require __DIR__ . '/bootstrap.php';")
            || str_contains($content, 'vendor/autoload.php')
            || str_contains($content, 'App\\Controllers\\')) {
            $this->fail('Legacy page routing is incomplete or still dispatches to the unused MVC scaffold');
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
$test = new ArchitectureTest();
$test->run();
