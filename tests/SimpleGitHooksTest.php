<?php

declare(strict_types=1);

namespace SimpleGitHooks\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleGitHooks\SimpleGitHooks;

class SimpleGitHooksTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/sgh-test-' . uniqid('', true);
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function initGitRepo(string $dir): string
    {
        $gitDir = $dir . '/.git';
        mkdir($gitDir, 0777, true);
        mkdir($gitDir . '/hooks', 0777, true);
        file_put_contents($gitDir . '/HEAD', 'ref: refs/heads/master');

        return $gitDir;
    }

    public function testValidateHooksAcceptsValidHooks(): void
    {
        $config = [
            'pre-commit' => 'echo 1',
            'pre-push'   => 'echo 2',
        ];
        self::assertTrue(SimpleGitHooks::validateHooks($config));
    }

    public function testValidateHooksAcceptsPreserveUnused(): void
    {
        $config = [
            'pre-commit'     => 'echo 1',
            'preserveUnused' => ['commit-msg'],
        ];
        self::assertTrue(SimpleGitHooks::validateHooks($config));
    }

    public function testValidateHooksRejectsUnknownKeys(): void
    {
        $config = [
            'pre-commit'   => 'echo 1',
            'unknown-hook' => 'echo 2',
        ];
        self::assertFalse(SimpleGitHooks::validateHooks($config));
    }

    public function testGetConfigFromComposerJsonExtraKey(): void
    {
        $composer = [
            'name'  => 'foo/bar',
            'extra' => [
                'simple-git-hooks' => [
                    'pre-commit' => 'echo extra',
                ],
            ],
        ];
        file_put_contents($this->tmpRoot . '/composer.json', json_encode($composer));

        $config = SimpleGitHooks::getConfig($this->tmpRoot);
        self::assertSame(['pre-commit' => 'echo extra'], $config);
    }

    public function testRootLevelKeyIsNotSupported(): void
    {
        $composer = [
            'name'             => 'foo/bar',
            'simple-git-hooks' => [
                'pre-commit' => 'echo root',
            ],
        ];
        file_put_contents($this->tmpRoot . '/composer.json', json_encode($composer));

        $config = SimpleGitHooks::getConfig($this->tmpRoot);
        self::assertNull($config);
    }

    public function testGetConfigFromDotJsonFileTakesPrecedence(): void
    {
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'extra' => ['simple-git-hooks' => ['pre-commit' => 'from composer']],
        ]));
        file_put_contents($this->tmpRoot . '/.simple-git-hooks.json', json_encode([
            'pre-commit' => 'from dotfile',
        ]));

        $config = SimpleGitHooks::getConfig($this->tmpRoot);
        self::assertSame(['pre-commit' => 'from dotfile'], $config);
    }

    public function testGetConfigFromSimpleJsonFile(): void
    {
        file_put_contents($this->tmpRoot . '/simple-git-hooks.json', json_encode([
            'pre-push' => 'composer test',
        ]));

        $config = SimpleGitHooks::getConfig($this->tmpRoot);
        self::assertSame(['pre-push' => 'composer test'], $config);
    }

    public function testGetConfigReturnsNullWhenNoConfig(): void
    {
        self::assertNull(SimpleGitHooks::getConfig($this->tmpRoot));
    }

    public function testGetConfigThrowsOnInvalidKeys(): void
    {
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'extra' => ['simple-git-hooks' => ['not-a-hook' => 'echo 1']],
        ]));

        $this->expectException(RuntimeException::class);
        SimpleGitHooks::getConfig($this->tmpRoot);
    }

    public function testGetConfigFromCustomPath(): void
    {
        $custom = $this->tmpRoot . '/my-hooks.json';
        file_put_contents($custom, json_encode([
            'commit-msg' => 'echo msg',
        ]));

        $config = SimpleGitHooks::getConfig($this->tmpRoot, $custom);
        self::assertSame(['commit-msg' => 'echo msg'], $config);
    }

    public function testGetGitProjectRootFindsGitDir(): void
    {
        $sub = $this->tmpRoot . '/a/b/c';
        mkdir($sub, 0777, true);
        $this->initGitRepo($this->tmpRoot);

        $root = SimpleGitHooks::getGitProjectRoot($sub);
        self::assertNotNull($root);
        self::assertStringEndsWith('.git', $root);
    }

    public function testGetGitProjectRootReturnsNullOutsideRepo(): void
    {
        $root = SimpleGitHooks::getGitProjectRoot(sys_get_temp_dir());
        self::assertNull($root);
    }

    public function testSetHookCreatesFileWithPrependScript(): void
    {
        $this->initGitRepo($this->tmpRoot);

        $result = SimpleGitHooks::setHook('pre-commit', 'echo test', $this->tmpRoot);
        self::assertTrue($result['success']);
        self::assertTrue($result['hookChanged']);

        $hookPath = $this->tmpRoot . '/.git/hooks/pre-commit';
        self::assertFileExists($hookPath);

        $content = file_get_contents($hookPath);
        self::assertStringContainsString('SKIP_SIMPLE_GIT_HOOKS', $content);
        self::assertStringContainsString('echo test', $content);
        self::assertStringStartsWith('#!/bin/sh', $content);

        // Executable bit set
        self::assertTrue(is_executable($hookPath));
    }

    public function testSetHookIsIdempotent(): void
    {
        $this->initGitRepo($this->tmpRoot);

        SimpleGitHooks::setHook('pre-commit', 'echo test', $this->tmpRoot);
        $result = SimpleGitHooks::setHook('pre-commit', 'echo test', $this->tmpRoot);

        self::assertFalse($result['hookChanged']);
    }

    public function testSetHookUpdatesExistingHook(): void
    {
        $this->initGitRepo($this->tmpRoot);

        SimpleGitHooks::setHook('pre-commit', 'echo old', $this->tmpRoot);
        $result = SimpleGitHooks::setHook('pre-commit', 'echo new', $this->tmpRoot);

        self::assertTrue($result['hookChanged']);
        $content = file_get_contents($this->tmpRoot . '/.git/hooks/pre-commit');
        self::assertStringContainsString('echo new', $content);
    }

    public function testRemoveHookDeletesFile(): void
    {
        $this->initGitRepo($this->tmpRoot);

        SimpleGitHooks::setHook('pre-commit', 'echo test', $this->tmpRoot);
        self::assertFileExists($this->tmpRoot . '/.git/hooks/pre-commit');

        $removed = SimpleGitHooks::removeHook('pre-commit', $this->tmpRoot);
        self::assertTrue($removed);
        self::assertFileDoesNotExist($this->tmpRoot . '/.git/hooks/pre-commit');
    }

    public function testRemoveHookReturnsFalseWhenMissing(): void
    {
        $this->initGitRepo($this->tmpRoot);
        self::assertFalse(SimpleGitHooks::removeHook('pre-commit', $this->tmpRoot));
    }

    public function testSetHooksFromConfigInstallsAndCleansUnused(): void
    {
        $this->initGitRepo($this->tmpRoot);
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'extra' => [
                'simple-git-hooks' => [
                    'pre-commit' => 'echo commit',
                    'pre-push'   => 'echo push',
                ],
            ],
        ]));

        // Pre-existing hook that is no longer in config should be removed
        file_put_contents($this->tmpRoot . '/.git/hooks/commit-msg', '#!/bin/sh' . PHP_EOL . 'echo old');
        chmod($this->tmpRoot . '/.git/hooks/commit-msg', 0755);

        $result = SimpleGitHooks::setHooksFromConfig($this->tmpRoot);
        self::assertTrue($result['isHookChanged']);

        self::assertFileExists($this->tmpRoot . '/.git/hooks/pre-commit');
        self::assertFileExists($this->tmpRoot . '/.git/hooks/pre-push');
        self::assertFileDoesNotExist($this->tmpRoot . '/.git/hooks/commit-msg');
    }

    public function testSetHooksFromConfigPreservesUnused(): void
    {
        $this->initGitRepo($this->tmpRoot);
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'extra' => [
                'simple-git-hooks' => [
                    'pre-commit'     => 'echo commit',
                    'preserveUnused' => ['commit-msg'],
                ],
            ],
        ]));

        file_put_contents($this->tmpRoot . '/.git/hooks/commit-msg', '#!/bin/sh' . PHP_EOL . 'echo keep');
        chmod($this->tmpRoot . '/.git/hooks/commit-msg', 0755);

        SimpleGitHooks::setHooksFromConfig($this->tmpRoot);

        self::assertFileExists($this->tmpRoot . '/.git/hooks/commit-msg');
    }

    public function testSetHooksFromConfigPreserveAllUnused(): void
    {
        $this->initGitRepo($this->tmpRoot);
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'extra' => [
                'simple-git-hooks' => [
                    'pre-commit'     => 'echo commit',
                    'preserveUnused' => true,
                ],
            ],
        ]));

        file_put_contents($this->tmpRoot . '/.git/hooks/commit-msg', '#!/bin/sh' . PHP_EOL . 'echo keep');
        chmod($this->tmpRoot . '/.git/hooks/commit-msg', 0755);

        SimpleGitHooks::setHooksFromConfig($this->tmpRoot);

        self::assertFileExists($this->tmpRoot . '/.git/hooks/commit-msg');
    }

    public function testSetHooksFromConfigThrowsWhenNoConfig(): void
    {
        $this->initGitRepo($this->tmpRoot);
        $this->expectException(RuntimeException::class);
        SimpleGitHooks::setHooksFromConfig($this->tmpRoot);
    }

    public function testRemoveHooksRemovesAllHooks(): void
    {
        $this->initGitRepo($this->tmpRoot);
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'extra' => [
                'simple-git-hooks' => [
                    'pre-commit' => 'echo commit',
                ],
            ],
        ]));

        SimpleGitHooks::setHooksFromConfig($this->tmpRoot);
        self::assertFileExists($this->tmpRoot . '/.git/hooks/pre-commit');

        SimpleGitHooks::removeHooks($this->tmpRoot);
        self::assertFileDoesNotExist($this->tmpRoot . '/.git/hooks/pre-commit');
    }

    public function testCheckSimpleGitHooksInDependenciesFindsRequireDev(): void
    {
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'require-dev' => [
                'watsonhaw/simple-git-hooks' => '^1.0',
            ],
        ]));

        self::assertTrue(SimpleGitHooks::checkSimpleGitHooksInDependencies($this->tmpRoot));
    }

    public function testCheckSimpleGitHooksInDependenciesFalseWhenAbsent(): void
    {
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'require-dev' => [
                'other/package' => '^1.0',
            ],
        ]));

        self::assertFalse(SimpleGitHooks::checkSimpleGitHooksInDependencies($this->tmpRoot));
    }
}
