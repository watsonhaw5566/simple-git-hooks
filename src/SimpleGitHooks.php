<?php

declare(strict_types=1);

namespace SimpleGitHooks;

use RuntimeException;
use Throwable;

class SimpleGitHooks
{
    public const VALID_GIT_HOOKS = [
        'applypatch-msg',
        'pre-applypatch',
        'post-applypatch',
        'pre-commit',
        'pre-merge-commit',
        'prepare-commit-msg',
        'commit-msg',
        'post-commit',
        'pre-rebase',
        'post-checkout',
        'post-merge',
        'pre-push',
        'pre-receive',
        'update',
        'proc-receive',
        'post-receive',
        'post-update',
        'reference-transaction',
        'push-to-checkout',
        'pre-auto-gc',
        'post-rewrite',
        'sendemail-validate',
        'fsmonitor-watchman',
        'p4-changelist',
        'p4-prepare-changelist',
        'p4-post-changelist',
        'p4-pre-submit',
        'post-index-change',
    ];

    public const VALID_OPTIONS = ['preserveUnused'];

    public const PREPEND_SCRIPT = <<<'SH'
#!/bin/sh
if [ "$SKIP_SIMPLE_GIT_HOOKS" = "1" ]; then
    echo "[INFO] SKIP_SIMPLE_GIT_HOOKS is set to 1, skipping hook."
    exit 0
fi
if [ -f "$SIMPLE_GIT_HOOKS_RC" ]; then
    . "$SIMPLE_GIT_HOOKS_RC"
fi
SH;

    private const CONFIG_ERROR = '[ERROR] Config was not found! Please add `.simple-git-hooks.json` or `simple-git-hooks.json` file or `simple-git-hooks` entry in composer.json.' . PHP_EOL . 'Check README for details';

    /**
     * Walk up from the given directory to find the .git directory.
     * Supports worktrees (where .git is a file pointing to gitdir).
     */
    public static function getGitProjectRoot(?string $directory = null): ?string
    {
        $directory = $directory ?? (string) getcwd();
        if ($directory === '') {
            return null;
        }

        $dir = self::normalize($directory);
        if ($dir === '') {
            return null;
        }

        $fullPath = $dir . DIRECTORY_SEPARATOR . '.git';

        if (file_exists($fullPath)) {
            if (!is_dir($fullPath)) {
                // worktree: .git is a file containing "gitdir: <path>"
                $content = (string) file_get_contents($fullPath);
                if (preg_match('/^gitdir: (.*)\s*$/', $content, $match)) {
                    $gitDir = self::resolvePath(dirname($fullPath), $match[1]);
                    $commonDir = $gitDir . DIRECTORY_SEPARATOR . 'commondir';
                    if (file_exists($commonDir)) {
                        $commonDir = trim((string) file_get_contents($commonDir));

                        return self::normalize(self::resolvePath($gitDir, $commonDir));
                    }

                    return self::normalize($gitDir);
                }
            }

            return self::normalize($fullPath);
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            // Reached filesystem root without finding .git
            return null;
        }

        return self::getGitProjectRoot($parent);
    }

    /**
     * Resolve a possibly-relative path against a base directory.
     */
    private static function resolvePath(string $base, string $path): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
    }

    private static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }
        // Windows drive letter, e.g. C:\
        if (strlen($path) >= 2 && $path[1] === ':') {
            return true;
        }

        return false;
    }

    private static function normalize(string $path): string
    {
        // Collapse repeated separators and resolve ./.. where possible.
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (!empty($resolved) && end($resolved) !== '..') {
                    array_pop($resolved);
                } else {
                    $resolved[] = '..';
                }
                continue;
            }
            $resolved[] = $part;
        }
        $prefix = self::isAbsolute($path) ? DIRECTORY_SEPARATOR : '';

        return $prefix . implode(DIRECTORY_SEPARATOR, $resolved);
    }

    /**
     * Return the hooks directory, respecting core.hooksPath if set locally.
     */
    public static function getHooksDirPath(string $projectRoot, string $gitRoot): string
    {
        $defaultHooksDirPath = $gitRoot . DIRECTORY_SEPARATOR . 'hooks';

        $customHooksDirPath = self::runGitConfig('core.hooksPath', $projectRoot);
        if ($customHooksDirPath === null || $customHooksDirPath === '') {
            return $defaultHooksDirPath;
        }

        return self::isAbsolute($customHooksDirPath)
            ? $customHooksDirPath
            : self::resolvePath($projectRoot, $customHooksDirPath);
    }

    private static function runGitConfig(string $key, string $cwd): ?string
    {
        $command = sprintf(
            'git config --local %s',
            escapeshellarg($key),
        );
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            return null;
        }

        return trim((string) $output);
    }

    /**
     * Install (write) a single hook. Returns whether the hook content changed.
     *
     * @return array{hookChanged: bool, success: bool}
     */
    public static function setHook(string $hook, string $command, ?string $projectRoot = null): array
    {
        $projectRoot = $projectRoot ?? (string) getcwd();

        $gitRoot = self::getGitProjectRoot($projectRoot);
        if ($gitRoot === null) {
            fwrite(STDERR, '[INFO] No `.git` root folder found, skipping' . PHP_EOL);

            return ['hookChanged' => false, 'success' => false];
        }

        $hookCommand = self::PREPEND_SCRIPT . PHP_EOL . $command . PHP_EOL;
        $hookDirectory = self::getHooksDirPath($projectRoot, $gitRoot);
        $hookPath = $hookDirectory . DIRECTORY_SEPARATOR . $hook;

        if (!is_dir($hookDirectory)) {
            mkdir($hookDirectory, 0777, true);
        }

        if (file_exists($hookPath)) {
            $existingHook = (string) file_get_contents($hookPath);
            if ($existingHook === $hookCommand) {
                return ['hookChanged' => false, 'success' => true];
            }
        }

        file_put_contents($hookPath, $hookCommand);
        chmod($hookPath, 0755);
        fwrite(STDOUT, sprintf('[INFO] Successfully set the %s with command: %s%s', $hook, $command, PHP_EOL));

        return ['hookChanged' => true, 'success' => true];
    }

    /**
     * Remove a single hook. Returns true if a file was deleted.
     */
    public static function removeHook(string $hook, ?string $projectRoot = null): bool
    {
        $projectRoot = $projectRoot ?? (string) getcwd();

        $gitRoot = self::getGitProjectRoot($projectRoot);
        if ($gitRoot === null) {
            fwrite(STDERR, '[INFO] No `.git` root folder found, skipping' . PHP_EOL);

            return false;
        }

        $hookDirectory = self::getHooksDirPath($projectRoot, $gitRoot);
        $hookPath = $hookDirectory . DIRECTORY_SEPARATOR . $hook;

        if (file_exists($hookPath)) {
            unlink($hookPath);

            return true;
        }

        return false;
    }

    /**
     * Install all hooks defined in the config.
     *
     * @return array{isHookChanged: bool}
     */
    public static function setHooksFromConfig(?string $projectRoot = null, array $argv = []): array
    {
        $projectRoot = $projectRoot ?? (string) getcwd();

        $customConfigPath = self::getCustomConfigPath($argv);
        $config = self::getConfig($projectRoot, $customConfigPath);
        if ($config === null) {
            throw new RuntimeException(self::CONFIG_ERROR);
        }

        $preserveUnused = [];
        if (isset($config['preserveUnused'])) {
            $preserveUnused = is_array($config['preserveUnused'])
                ? $config['preserveUnused']
                : self::VALID_GIT_HOOKS;
        }

        $isHookChanged = false;
        foreach (self::VALID_GIT_HOOKS as $hook) {
            if (array_key_exists($hook, $config)) {
                $result = self::setHook($hook, (string) $config[$hook], $projectRoot);
                if ($result['hookChanged']) {
                    $isHookChanged = true;
                }
            } elseif (!in_array($hook, $preserveUnused, true)) {
                if (self::removeHook($hook, $projectRoot)) {
                    $isHookChanged = true;
                }
            }
        }

        return ['isHookChanged' => $isHookChanged];
    }

    /**
     * Uninstall all hooks (except those listed in preserveUnused).
     */
    public static function removeHooks(?string $projectRoot = null, array $argv = []): void
    {
        $projectRoot = $projectRoot ?? (string) getcwd();

        $customConfigPath = self::getCustomConfigPath($argv);
        $config = self::getConfig($projectRoot, $customConfigPath);

        $preserveUnused = [];
        if ($config !== null && isset($config['preserveUnused'])) {
            $preserveUnused = is_array($config['preserveUnused'])
                ? $config['preserveUnused']
                : [];
        }

        foreach (self::VALID_GIT_HOOKS as $hook) {
            if (!in_array($hook, $preserveUnused, true)) {
                self::removeHook($hook, $projectRoot);
            }
        }
    }

    private static function getCustomConfigPath(array $argv): string
    {
        return $argv[1] ?? '';
    }

    /**
     * Resolve the active configuration from supported sources.
     *
     * @return array<string, mixed>|null
     */
    public static function getConfig(string $projectRootPath, string $configFileName = ''): ?array
    {
        $sources = [
            function () use ($projectRootPath): ?array {
                return self::getConfigFromFile($projectRootPath, '.simple-git-hooks.json');
            },
            function () use ($projectRootPath): ?array {
                return self::getConfigFromFile($projectRootPath, 'simple-git-hooks.json');
            },
            function () use ($projectRootPath): ?array {
                return self::getConfigFromComposerJson($projectRootPath);
            },
        ];

        if ($configFileName !== '') {
            array_unshift($sources, function () use ($projectRootPath, $configFileName): ?array {
                return self::getConfigFromFile($projectRootPath, $configFileName);
            });
        }

        foreach ($sources as $source) {
            $config = $source();
            if ($config !== null) {
                if (!self::validateHooks($config)) {
                    throw new RuntimeException('[ERROR] Config was not in correct format. Please check git hooks or options name');
                }

                return $config;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getConfigFromComposerJson(string $projectRootPath): ?array
    {
        $composerJsonPath = rtrim($projectRootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (!file_exists($composerJsonPath) || !is_file($composerJsonPath)) {
            return null;
        }

        $raw = (string) file_get_contents($composerJsonPath);
        $composer = json_decode($raw, true);
        if (!is_array($composer)) {
            return null;
        }

        if (isset($composer['extra']['simple-git-hooks']) && is_array($composer['extra']['simple-git-hooks'])) {
            return $composer['extra']['simple-git-hooks'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getConfigFromFile(string $projectRootPath, string $fileName): ?array
    {
        $filePath = self::isAbsolute($fileName)
            ? $fileName
            : rtrim($projectRootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

        if (!file_exists($filePath) || !is_file($filePath)) {
            return null;
        }

        $raw = (string) file_get_contents($filePath);
        $config = json_decode($raw, true);
        if (!is_array($config)) {
            return null;
        }

        return $config;
    }

    /**
     * Validate that every key in the config is a known git hook or option.
     *
     * @param array<string, mixed> $config
     */
    public static function validateHooks(array $config): bool
    {
        foreach (array_keys($config) as $hookOrOption) {
            if (!in_array($hookOrOption, self::VALID_GIT_HOOKS, true)
                && !in_array($hookOrOption, self::VALID_OPTIONS, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether installation should be skipped via the SKIP_INSTALL_SIMPLE_GIT_HOOKS env var.
     */
    public static function skipInstall(): bool
    {
        $skip = getenv('SKIP_INSTALL_SIMPLE_GIT_HOOKS');
        if ($skip === '1' || $skip === 'true') {
            fwrite(STDOUT, sprintf('[INFO] SKIP_INSTALL_SIMPLE_GIT_HOOKS is set to "%s", skipping installing hook.%s', $skip, PHP_EOL));

            return true;
        }

        return false;
    }

    /**
     * Check whether simple-git-hooks is listed as a dependency in composer.json.
     */
    public static function checkSimpleGitHooksInDependencies(string $projectRootPath): bool
    {
        $composerJsonPath = rtrim($projectRootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';
        if (!file_exists($composerJsonPath) || !is_file($composerJsonPath)) {
            return false;
        }

        $raw = (string) file_get_contents($composerJsonPath);
        $composer = json_decode($raw, true);
        if (!is_array($composer)) {
            return false;
        }

        if (isset($composer['require']['watsonhaw/simple-git-hooks'])) {
            fwrite(STDERR, '[WARN] You should move simple-git-hooks to the require-dev!' . PHP_EOL);

            return true;
        }

        return isset($composer['require-dev']['watsonhaw/simple-git-hooks']);
    }

    /**
     * Composer post-install / post-update hook entry point.
     */
    public static function postInstall(): void
    {
        if (self::skipInstall()) {
            return;
        }

        $projectDirectory = (string) getcwd();
        if (!self::checkSimpleGitHooksInDependencies($projectDirectory)) {
            return;
        }

        try {
            self::setHooksFromConfig($projectDirectory);
        } catch (Throwable $e) {
            fwrite(STDERR, '[ERROR] Was not able to set git hooks. Reason: ' . $e->getMessage() . PHP_EOL);
        }
    }
}
