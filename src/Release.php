<?php

namespace Financialplugins\ReleaseTool;

use Exception;
use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use function Termwind\{render, ask, terminal};

/**
 * Release Tool - Automates the process of creating releases from source code
 * Supports various tasks like copying files, creating zips, filtering content, etc.
 */
class Release
{
    protected const CONFIG_FILE_NAME = 'release.json';
    protected const COMPOSER_FILE_NAME = 'composer.json';
    protected const PACKAGE_FILE_NAME = 'package.json';
    protected const TARGET_FOLDER_NAME = 'release';

    protected string $sourceFolderPath;
    protected string $targetFolderPath;
    protected ?object $composer;
    protected ?object $package;
    protected array $config;

    protected array $taskIds;

    /**
     * Constructor - Initialize the release process
     * Sets up paths, validates required files, and starts the release process
     *
     * @param array $argv Command line arguments, task IDs can be passed to run specific tasks only
     */
    public function __construct(array $argv)
    {
        terminal()->clear();
        $this->printHeader();

        // Set source folder to current working directory
        $this->sourceFolderPath = getcwd();
        $this->targetFolderPath = $this->sourcePath(static::TARGET_FOLDER_NAME);

        // Validate that target path is different from source path to prevent issues
        if ($this->sourceFolderPath == $this->targetFolderPath) {
            $this->printErrorAndExit(sprintf('Check the target path, it can not be the same as the source path: %s', $this->sourceFolderPath));
        }

        $this->ensureDirectoryExists($this->targetFolderPath);

        // Determine configuration file from CLI parameter: config=filename
        $configFileName = $this->getArgument('config', static::CONFIG_FILE_NAME);

        // Check for required configuration files
        foreach ([$configFileName] as $fileName) {
            $filePath = $this->sourcePath($fileName);

            if (!file_exists($filePath)) {
                $this->printErrorAndExit(sprintf('%s file not found in %s', $fileName, $this->sourceFolderPath));
            }
        }

        // Load configuration files
        $composerFilePath = $this->sourcePath(static::COMPOSER_FILE_NAME);
        $packageFilePath = $this->sourcePath(static::PACKAGE_FILE_NAME);
        $this->composer = file_exists($composerFilePath) ? json_decode(file_get_contents($composerFilePath)) : null;
        $this->package = file_exists($packageFilePath) ? json_decode(file_get_contents($packageFilePath)) : null;
        $this->config = json_decode(file_get_contents($this->sourcePath($configFileName)), JSON_OBJECT_AS_ARRAY);

        // Extract task IDs from "tasks=" CLI parameter (comma-separated), ignore others
        $tasksArg = $this->getArgument('tasks');

        $this->taskIds = [];
        if (!is_null($tasksArg) && $tasksArg !== '') {
            $this->taskIds = array_values(array_filter(array_map('trim', explode(',', $tasksArg)), fn ($v) => $v !== ''));
        }

        $this->printVar('Source folder', $this->sourceFolderPath);
        $this->printVar('Target folder', $this->targetFolderPath);
        $this->printVar('Number of tasks', count($this->config['tasks']));

        // Start processing tasks
        $this->process();

        $this->printString('Release completed.');
    }

    /**
     * Process all configured tasks in sequence
     * Supports task filtering by ID and skipping tasks marked with 'skip' flag
     */
    protected function process(): void
    {
        foreach ($this->config['tasks'] as $i => $task) {
            // Build dynamic method name based on task type
            $methodName = sprintf('process%sTask', ucfirst($this->getTaskType($task)));

            // Skip task if marked for skipping or not in the filtered task IDs list
            if (isset($task['skip']) || (!empty($this->taskIds) && (!isset($task['id']) || !in_array($task['id'], $this->taskIds)))) {
                $this->printStatus(sprintf('Task #%d', ++$i), 'warning', 'SKIPPED');
                continue;
            }

            // Execute task if corresponding method exists
            if (method_exists($this, $methodName)) {
                try {
                    $this->$methodName($task);
                    $this->printStatus(sprintf('Task #%d (%s)', ++$i, $this->getTaskType($task)));
                } catch (Exception $e) {
                    $this->printStatus(sprintf('Task #%d (%s)', ++$i, $this->getTaskType($task)), 'error', 'FAILED');
                    $this->printErrorAndExit($e->getMessage());
                }
            }
        }
    }

    protected function processCleanTask(): void
    {
        try {
            $this->deleteFolder($this->targetFolderPath);
            $this->ensureDirectoryExists($this->targetFolderPath);
        } catch (Exception $e) {
            $this->printErrorAndExit($e->getMessage());
        }
    }

    /**
     * Process delete task - removes files and folders matching specified patterns
     *
     * @param array $task Task configuration containing items to delete
     */
    protected function processDeleteTask(array $task): void
    {
        foreach ($task['items'] as $item) {
            // Process each folder specified in the item
            foreach ($this->getTaskItemFolders($item) as $folder) {
                $searchFolderPath = $this->sourcePath($folder);

                // Find and delete all matching items
                foreach (iterator_to_array($this->makeFinder($searchFolderPath, $item)) as $matchedItem) {
                    $path = $matchedItem->getRealPath();

                    if (is_dir($path)) {
                        $this->deleteFolder($path);
                    } elseif (is_file($path) || is_link($path)) {
                        unlink($path);
                    }
                }
            }
        }
    }

    /**
     * Process command task - executes shell commands
     *
     * @param array $task Task configuration containing commands to execute
     */
    protected function processCommandTask(array $task): void
    {
        foreach ($task['items'] as $command) {
            $result = shell_exec($command);

            // Check if command execution failed
            if ($result === FALSE) {
                $this->printErrorAndExit(sprintf('Command "%s" can not be completed, result: %s', $command, $result));
            }
        }
    }

    /**
     * Process mkdir task - creates directories in the target folder
     *
     * @param array $task Task configuration containing directories to create
     */
    protected function processMkdirTask(array $task): void
    {
        foreach ($task['items'] as $item) {
            $this->ensureDirectoryExists($this->targetPath($item));
        }
    }

    /**
     * Process copy task - copies files and folders from source to target
     *
     * @param array $task Task configuration containing items to copy
     */
    protected function processCopyTask(array $task): void
    {
        foreach ($task['items'] as $item) {
            foreach ($this->getTaskItemFolders($item) as $folder) {
                $searchFolderPath = $this->sourcePath($folder);

                foreach ($this->makeFinder($searchFolderPath, $item) as $match) {
                    // Determine destination path based on item configuration
                    $destinationPath = isset($item['destination'])
                        ? $this->targetPath($item['destination'] . '/' . $match->getRelativePathname())
                        : $this->targetPath($folder . '/' . $match->getRelativePathname());

                    if (is_dir($match->getRealPath())) {
                        $this->ensureDirectoryExists($destinationPath);
                    } else {
                        // Create parent directory structure before copying file
                        $fileFolderFolderPath = substr($destinationPath, 0, strrpos($destinationPath, $match->getFilename()) - 1);
                        $this->ensureDirectoryExists($fileFolderFolderPath);
                        copy($match->getRealPath(), $destinationPath);
                    }
                }
            }
        }
    }

    /**
     * Process zip task - creates ZIP archives with specified files and folders
     *
     * @param array $task Task configuration containing items to zip and zip settings
     */
    protected function processZipTask(array $task): void
    {
        // Initialize ZIP archive
        $taskZipArchive = new ZipArchive;
        $taskZipFilePath = $this->targetPath($task['output'] ?? $task['zip']);
        $res = $taskZipArchive->open($taskZipFilePath, ZipArchive::CREATE);

        if ($res !== true) {
            $this->printErrorAndExit(sprintf('Unable to open/create zip "%s" (code: %s)', $taskZipFilePath, $res));
        }

        $taskZipRoot = $task['root'] ?? null;

        foreach ($task['items'] as $item) {
            // Loop through folders of each item
            foreach ($this->getTaskItemFolders($item) as $folder) {
                $searchFolderPath = $this->sourcePath($folder);

                // Loop through matched items (files or folders) in this folder
                foreach ($this->makeFinder($searchFolderPath, $item) as $match) {
                    // Build ZIP internal path structure
                    $zipDestinationFolder = $item['destination'] ?? $folder;
                    $parts = array_values(array_filter([$taskZipRoot, $zipDestinationFolder, $this->path($match->getRelativePathname())], fn($v) => $v !== null && $v !== ''));
                    $zipPath = implode('/', $parts);

                    if (is_dir($match->getRealPath())) {
                        $taskZipArchive->addEmptyDir($zipPath);
                    } else {
                        // Apply filters if specified, otherwise add file directly
                        if (isset($item['filters'])) {
                            $filteredContents = $this->filter($match, $item['filters']);
                            $taskZipArchive->addFromString($zipPath, $filteredContents);
                        } else {
                            $taskZipArchive->addFile($match->getRealPath(), $zipPath);
                        }
                    }
                }
            }
        }

        $taskZipArchive->close();
    }

    /**
     * Process phar task - creates a PHAR archive with specified files and folders
     *
     * Task options:
     * - output (string) required: path/name of the phar file relative to target folder
     * - root (string) optional: prepend path inside the archive
     * - entry (string) optional: bootstrap file inside the phar used by default stub (defaults to "index.php")
     * - stub (string) optional: custom stub content or path to a stub file (absolute or relative to source)
     * - items (array) required: same structure as copy/zip tasks
     *
     * @param array $task
     */
    protected function processPharTask(array $task): void
    {
        if (!isset($task['output']) || !$task['output']) {
            $this->printErrorAndExit('PHAR task requires "output" parameter.');
        }

        // Ensure phar.readonly is disabled
        $readonly = ini_get('phar.readonly');
        if ($readonly && $readonly !== '0') {
            @ini_set('phar.readonly', '0');
            if (ini_get('phar.readonly') !== '0') {
                $this->printErrorAndExit('Unable to create PHAR: phar.readonly is enabled.');
            }
        }

        $pharFilePath = $this->targetPath($task['output']);
        $pharDir = dirname($pharFilePath);
        $this->ensureDirectoryExists($pharDir);

        // Recreate file if exists
        if (file_exists($pharFilePath)) {
            unlink($pharFilePath);
        }

        $alias = basename($pharFilePath);
        $pharRoot = $task['root'] ?? '';

        // Build PHAR
        try {
            $phar = new Phar($pharFilePath);
            $phar->setAlias($alias);
            $phar->startBuffering();

            if (isset($task['items'])) {
                foreach ($task['items'] as $item) {
                    foreach ($this->getTaskItemFolders($item) as $folder) {
                        $searchFolderPath = $this->sourcePath($folder);
                        // Try fast path for large folders: use buildFromIterator when no filters/criteria are specified
                        $isArray = is_array($item);
                        $hasFilters = $isArray && isset($item['filters']);
                        $allowedKeys = ['folder', 'destination'];
                        $hasOnlyAllowedKeys = !$isArray || empty(array_diff(array_keys($item), $allowedKeys));
                        $isDir = is_dir($searchFolderPath);

                        if ($isDir && !$hasFilters && $hasOnlyAllowedKeys) {
                            // Build prefix to mirror previous behavior (root + destination or folder name)
                            $pharRootFolder = $pharRoot ? $pharRoot . '/' : '';
                            $destinationFolder = ($isArray && isset($item['destination']) && $item['destination'] !== '')
                                ? rtrim($item['destination'], '/') . '/'
                                : ($folder ? rtrim($folder, '/') . '/' : '');
                            $prefix = $pharRootFolder . $destinationFolder;

                            // Generator producing key => absolute path for files only
                            $gen = (function () use ($searchFolderPath, $prefix) {
                                $directory = new RecursiveDirectoryIterator($searchFolderPath, RecursiveDirectoryIterator::SKIP_DOTS);
                                $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
                                $baseLen = strlen(rtrim($searchFolderPath, '\\/')) + 1;
                                foreach ($iterator as $file) {
                                    if ($file->isFile()) {
                                        $real = $file->getPathname();
                                        $rel = substr($real, $baseLen);
                                        $key = $prefix . str_replace('\\', '/', $rel);
                                        yield $key => $real;
                                    }
                                }
                            })();

                            $phar->buildFromIterator($gen);
                            continue; // next folder
                        }

                        // Fallback: precise selection via Finder (supports filters and patterns)
                        foreach ($this->makeFinder($searchFolderPath, $item) as $match) {
                            $pharRootFolder = $pharRoot ? $pharRoot . '/' : '';
                            $destinationFolder = isset($item['destination']) ? $item['destination'] . '/' : ($folder ? $folder . '/' : '');
                            $pharPath = $pharRootFolder . $destinationFolder . $this->path($match->getRelativePathname());

                            if (is_dir($match->getRealPath())) {
                                $phar->addEmptyDir($pharPath);
                            } else {
                                if (isset($item['filters'])) {
                                    $filtered = $this->filter($match, $item['filters']);
                                    $phar->addFromString($pharPath, $filtered);
                                } else {
                                    $phar->addFile($match->getRealPath(), $pharPath);
                                }
                            }
                        }
                    }
                }
            }

            // Stub
            $entry = $task['entry'] ?? 'index.php';
            $stubContent = null;
            if (isset($task['stub']) && is_string($task['stub']) && $task['stub'] !== '') {
                $stubSource = $task['stub'];
                if (str_contains($stubSource, '<?php')) {
                    $stubContent = $stubSource;
                } else {
                    $stubPath = is_file($stubSource) ? $stubSource : $this->sourcePath($stubSource);
                    if (is_file($stubPath)) {
                        $stubContent = file_get_contents($stubPath);
                    }
                }
            }

            if ($stubContent === null) {
                // Default stub maps the phar alias and includes the entry file from the archive
                $entryPath = 'phar://' . $alias . '/' . ltrim($pharRoot . '/' . $entry, '/');
                $stubContent = "<?php\nPhar::mapPhar('" . addslashes($alias) . "');\nrequire '" . addslashes($entryPath) . "';\n__HALT_COMPILER();";
            }

            $phar->setStub($stubContent);
            $phar->setSignatureAlgorithm(Phar::SHA512);

            $phar->stopBuffering();
        } catch (\Exception $e) {
            $this->printErrorAndExit('PHAR creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Process sftp task - uploads files via SFTP and optionally executes remote commands
     *
     * Expected task options:
     * - host (string) required
     * - port (int) optional, default 22
     * - username (string) required
     * - password (string) optional if no privateKey
     * - privateKey (string) optional path to private key (absolute or relative to source)
     * - passphrase (string) optional passphrase for private key
     * - root (string) optional remote root directory, default "/"
     * - timeout (int|float) optional connection timeout seconds
     * - commands (array) optional list of shell commands to execute on remote host (via SSH)
     * - items (array) required, same structure as copy/zip tasks
     *
     * @param array $task
     */
    protected function processSshTask(array $task): void
    {
        $host = $task['host'] ?? null;
        $port = (int)($task['port'] ?? 22);
        $username = $task['username'] ?? null;
        $password = $task['password'] ?? null;
        $privateKey = $task['privateKey'] ?? null;
        $passphrase = $task['passphrase'] ?? null;
        $targetPath = $task['path'] ?? '/';
        $timeout = $task['timeout'] ?? 10;
        $commandTimeout = $task['commandTimeout'] ?? $timeout;

        if (!$host || !$username) {
            $this->printErrorAndExit('SFTP task requires "host" and "username" parameters.');
        }

        // Prepare key if provided
        $key = null;
        if ($privateKey) {
            $keyPath = is_file($privateKey) ? $privateKey : $this->sourcePath($privateKey);
            if (!is_file($keyPath)) {
                $this->printErrorAndExit(sprintf('Private key not found: %s', $privateKey));
            }
            $key = PublicKeyLoader::load(file_get_contents($keyPath), is_null($passphrase) ? false : $passphrase);
        }

        // Connect SFTP (for file transfers)
        $sftp = new SFTP($host, $port, $timeout);
        $authOk = $key
            ? $sftp->login($username, $key)
            : ($password ? $sftp->login($username, $password) : false);

        if ($authOk) {
            $this->printString(sprintf('SFTP connected to %s@%s:%d', $username, $host, $port));
            $this->ensureRemoteDirectoryExists($sftp, $targetPath);
            if (!$sftp->chdir($targetPath)) {
                $this->printErrorAndExit(sprintf('Failed to change directory to %s', $targetPath));
            }
        } else {
            $this->printErrorAndExit('SFTP authentication failed.');
        }

        // Upload items
        if (isset($task['items'])) {
            foreach ($task['items'] as $item) {
                foreach ($this->getTaskItemFolders($item) as $folder) {
                    $searchFolderPath = $this->sourcePath($folder);

                    foreach ($this->makeFinder($searchFolderPath, $item) as $match) {
                        if (isset($item['destination'])) {
                            $this->ensureRemoteDirectoryExists($sftp, $item['destination']);
                            $itemTargetPath = rtrim($targetPath, '/') . '/' . $item['destination'];
                        } else {
                            $itemTargetPath = $targetPath;
                        }

                        if (is_dir($match->getRealPath())) {
                            $this->ensureRemoteDirectoryExists($sftp, $itemTargetPath);
                        } else {
                            $itemFilePath = $itemTargetPath . '/' . $match->getRelativePathname();
                            // Ensure nested directories for the file exist
                            $this->ensureRemoteDirectoryExists($sftp, dirname($itemFilePath));
                            $sftp->put($itemFilePath, $match->getRealPath(), SFTP::SOURCE_LOCAL_FILE)
                                ? $this->printStatus(sprintf('File: %s', $itemFilePath), 'success', 'UPLOADED')
                                : $this->printErrorAndExit(sprintf('Failed to upload: %s', $itemFilePath));
                        }
                    }
                }
            }
        }

        // Execute commands (if any) on a separate SSH2 connection to avoid channel conflicts with SFTP
        if (isset($task['commands']) && is_array($task['commands']) && count($task['commands']) > 0) {
            $ssh = new SSH2($host, $port, $timeout);
            $authCmdOk = $key
                ? $ssh->login($username, $key)
                : ($password ? $ssh->login($username, $password) : false);

            if (!$authCmdOk) {
                $this->printErrorAndExit('SSH authentication failed for command execution.');
            }

            $ssh->setTimeout($commandTimeout);

            foreach ($task['commands'] as $command) {
                if (is_string($command)) {
                    $commandText = str_replace('{path}', $targetPath, $command);
                    $cmd = $commandText;
                } else {
                    $commandText = $command['cmd'];
                    $cd = isset($command['cd']) ? 'cd ' . escapeshellarg(str_replace('{path}', $targetPath, $command['cd'])) . ' && ' : '';
                    $cmd = $cd . $commandText;
                }

                // Run command and drain both STDOUT and STDERR
                $output = (string) $ssh->exec($cmd);
                $errorOutput = (string) $ssh->getStdError();

                if ($output === '' && $errorOutput === '' && $ssh->isTimeout()) {
                    $this->printErrorAndExit(sprintf('Remote command timed out: %s', $commandText));
                }

                if ($output === '' && $errorOutput !== '') {
                    $this->printErrorAndExit(sprintf('Remote command failed: %s; stderr: %s', $commandText, trim($errorOutput)));
                }

                if ($output === false) {
                    $this->printErrorAndExit(sprintf('Remote command failed: %s', $commandText));
                } else {
                    $this->printString(sprintf('%s@%s:%s# %s', $username, $host, $targetPath, $commandText));
                    $this->printText(trim($output));
                    if ($errorOutput !== '') {
                        $this->printText(trim($errorOutput));
                    }
                }
            }

            $ssh->disconnect();
        }

        $sftp->disconnect();
    }

    /**
     * Ensure a remote directory (and all its parents) exists on the SFTP server.
     *
     * @param SFTP $sftp
     * @param string $remoteDir
     * @return void
     */
    protected function ensureRemoteDirectoryExists(SFTP $sftp, string $remoteDir): void
    {
        $remoteDir = rtrim($this->path($remoteDir), '/');
        if ($remoteDir === '') {
            return;
        }

        $isAbsolute = str_starts_with($remoteDir, '/');
        $parts = array_values(array_filter(explode('/', ltrim($remoteDir, '/')), fn ($p) => $p !== ''));
        $current = $isAbsolute ? '/' : '';

        foreach ($parts as $part) {
            $current = ($current === '' || $current === '/') ? ($current . $part) : ($current . '/' . $part);
            if (!$sftp->is_dir($current)) {
                if (!$sftp->mkdir($current)) {
                    $this->printErrorAndExit(sprintf('Unable to create remote directory: %s', $current));
                }
            }
        }
    }

    /**
     * Apply filters to file content (remove comments, add copyright, replace text, etc.)
     *
     * @param SplFileInfo $file File to filter
     * @param array $filters Array of filter configurations
     * @return string Filtered content
     */
    protected function filter(SplFileInfo $file, array $filters): string
    {
        $contents = $file->getContents();

        // Apply each filter in sequence
        foreach ($filters as $filter) {
            // Build dynamic filter method name
            $method = 'filter' . ucfirst(is_string($filter) ? $filter : $filter['type']);

            if (method_exists($this, $method)) {
                $contents = $this->$method($file, $contents, $filter);
            }
        }

        return $contents;
    }

    /**
     * Filter to remove comments from PHP, JS, and Vue files
     *
     * @param SplFileInfo $file File being processed
     * @param string $contents File content
     * @param string|array $filter Filter configuration
     * @return string Content with comments removed
     */
    protected function filterRemoveComments(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if ($file->getExtension() == 'php') {
            // Use PHP tokenizer to safely remove comments while preserving code structure
            $result = '';
            $tokens = token_get_all($contents);

            foreach ($tokens as $token) {
                if (is_array($token)) {
                    list($id, $text) = $token;

                    // Skip comment tokens
                    if (in_array($id, [T_COMMENT, T_DOC_COMMENT])) {
                        continue;
                    }

                    $token = $text;
                }

                $result .= $token;
            }

            $contents = $result;
        } elseif (in_array($file->getExtension(), ['js', 'vue'])) {
            // Remove single-line and multi-line comments using regex
            $contents = preg_replace('~^\s*//.*$|/\*.*?\*/~m', '', $contents);
        }

        return $contents;
    }

    /**
     * Filter to add copyright header to PHP files
     *
     * @param SplFileInfo $file File being processed
     * @param string $contents File content
     * @param string|array $filter Filter configuration
     * @return string Content with copyright header added
     */
    protected function filterAddCopyright(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if ($file->getExtension() == 'php') {
            if (!$this->composer) {
                return '';
            }

            // Define copyright template
            $copyright = <<<TEXT
                <?php
                /**
                 *   %s
                 *   %s
                 *   %s
                 * 
                 *   @copyright  Copyright (c) %s, All rights reserved
                 *   @author     %s <%s>
                 *   @see        %s
                */
                TEXT;

            // Extract author information from composer.json
            $author = $this->composer->authors[0] ?? [];
            $description = $this->composer->description ?? $this->composer->name;

            // Replace opening PHP tag with copyright header
            $contents = str_replace(
                '<?php',
                sprintf(
                    $copyright,
                    $description,
                    str_repeat('-', strlen($description)),
                    $file->getFilename(),
                    $author->name ?? '',
                    $author->name ?? '',
                    $author->email ?? '',
                    $author->homepage ?? '',
                ),
                $contents
            );
        }
        if ($file->getExtension() == 'js') {
            if (!$this->package) {
                return '';
            }

            // Define copyright template
            $copyright = <<<TEXT
                /*!
                 * ============================================================
                 * Projec       : %s
                 * Author       : %s
                 * Website      : %s
                 * License      : %s
                 * Copyright    : (c) %d %s
                 * ============================================================
                 */
                TEXT;

            // Extract author information from composer.json
            $authorName = $this->package->author->name ?? '';
            $authorEmail = $this->package->author->email ?? '';
            $authorWebsite = $this->package->author->url ?? '';
            $license = $this->package->license ?? '';
            $year = date('Y');
            $description = $this->package->description ?? $this->package->name;

            $contents = sprintf(
                $copyright,
                $description,
                $authorName . ($authorEmail ? ' <' . $authorEmail . '>' : ''),
                $authorWebsite,
                $license,
                $year,
                $authorName
            ) . "\n" . $contents;
        }

        return $contents;
    }

    /**
     * Filter to replace text or patterns in file content
     *
     * @param SplFileInfo $file File being processed
     * @param string $contents File content
     * @param string|array $filter Filter configuration with search/replace parameters
     * @return string Content with replacements applied
     */
    protected function filterReplace(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if (!isset($filter['search']) || !isset($filter['replace'])) {
            return $contents;
        }

        $search = $filter['search'];
        $replace = $filter['replace'];

        // Execute PHP code in replace value if EXEC() pattern is found
        if (preg_match('#EXEC\((.+)\)#', $replace, $m)) {
            $replace = str_replace($m[0], eval($m[1]), $replace);
        }

        // Use regex or string replacement based on search pattern
        return preg_match('/^#.*#[ismU]*$/', $search) // if $search is a regular expression
            ? preg_replace($search, $replace, $contents)
            : str_replace($search, $replace, $contents);
    }

    /**
     * Filter to encode specified strings to UTF-8 hexadecimal format
     *
     * @param SplFileInfo $file File being processed
     * @param string $contents File content
     * @param string|array $filter Filter configuration with search strings
     * @return string Content with strings encoded
     */
    protected function filterEncodeStringUtf(SplFileInfo $file, string $contents, string|array $filter): string
    {
        $search = $filter['search'] ?? NULL;

        if (is_null($search)) {
            return $contents;
        }

        // Convert single search string to array
        if (is_string($search)) {
            $search = [$search];
        }

        // Build regex patterns for each search string
        $search = array_map(fn($s) => '/\'(' . str_replace('/', '\/', $s) . ')\'/', $search);

        // Replace found strings with their UTF-8 encoded equivalent
        return preg_replace_callback($search, fn ($matches) => '"' . $this->encodeStringUtf($matches[1]) . '"', $contents);
    }

    /**
     * Filter to execute custom PHP code during processing
     *
     * @param SplFileInfo $file File being processed
     * @param string $contents File content
     * @param string|array $filter Filter configuration with PHP code to execute
     * @return string Unchanged content (code execution may have side effects)
     */
    protected function filterExec(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if (!isset($filter['code'])) {
            return $contents;
        }

        // Execute the provided PHP code
        eval($filter['code']);

        return $contents;
    }

    /**
     * Given a string replace all characters with their UTF-8 equivalent in \xHH format
     *
     * @param  string  $string
     * @return string
     */
    protected function encodeStringUtf(string $string): string
    {
        return implode(
            '',
            array_map(function($char) {
                return '\x'.bin2hex($char);
                },
                str_split($string)
            )
        );
    }

    /**
     * Get the type of task, defaulting to 'zip' if not specified
     *
     * @param array $task Task configuration
     * @return string Task type
     */
    protected function getTaskType(array $task): string
    {
        return $task['type'] ?? 'zip';
    }

    /**
     * Extract folder(s) from task item configuration
     * Handles both string and array folder specifications
     *
     * @param string|array $item Task item configuration
     * @return array Array of folder paths
     */
    protected function getTaskItemFolders(string|array $item): array
    {
        $folder = is_array($item) ? $item['folder'] : $item;
        return is_array($folder) ? $folder : [$folder];
    }

    /**
     * Create a Symfony Finder instance configured for the given search parameters
     *
     * @param string $searchFolderPath Base path to search in
     * @param string|array $item Item configuration with search criteria
     * @return Finder Configured finder instance
     */
    protected function makeFinder(string $searchFolderPath, string|array $item): Finder
    {
        $finder = (new Finder)->in($searchFolderPath)->ignoreDotFiles(FALSE);

        if (is_array($item)) {
            // Configure finder to search for files or directories
            if (isset($item['files']) && $item['files'] === FALSE) {
                $finder->directories();
            } else {
                $finder->files();
            }

            // Apply search criteria (name patterns, exclusions, depth limits, etc.)
            foreach (['name', 'notName', 'notPath', 'depth', 'exclude'] as $method) {
                if (isset($item[$method])) {
                    $finder->$method($item[$method]);
                }
            }
        }

        return $finder;
    }

    /**
     * Create a directory if it doesn't exist
     *
     * @param string $path Directory path to create
     * @return bool True if directory exists or was created successfully
     */
    protected function ensureDirectoryExists(string $path): bool
    {
        return is_dir($path) ? TRUE : mkdir($path, 0755, TRUE);
    }

    /**
     * Delete folder and all files and subfolders recursively
     *
     * @param  string  $path
     * @return bool
     */
    protected function deleteFolder(string $path): bool
    {
        if (is_dir($path)) {
            // Use recursive iterator to traverse all files and subdirectories
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathName());
                } else {
                    if (($file->isFile() || $file->isLink())) {
                        // Use @ to suppress warnings, throw exception if deletion fails
                        @unlink($file->getPathname()) or throw new Exception(sprintf('Resource temporarily unavailable: %s', $file->getPathname()));
                    }
                }
            }

            return rmdir($path);
        }

        return FALSE;
    }

    /**
     * Normalize path separators to forward slashes
     *
     * @param string $path Path to normalize
     * @return string Normalized path
     */
    protected function path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Build absolute path for source file/folder
     *
     * @param string $relativePath Relative path from source folder
     * @return string Absolute source path
     */
    protected function sourcePath(string $relativePath): string
    {
        return trim($this->sourceFolderPath . '/' . $this->path($relativePath), '/');
    }

    /**
     * Build absolute path for target file/folder
     *
     * @param string $relativePath Relative path from target folder
     * @return string Absolute target path
     */
    protected function targetPath(string $relativePath): string
    {
        return trim($this->targetFolderPath . '/' . $this->path($relativePath), '/');
    }

    /**
     * Print the application header using Termwind
     */
    protected function printHeader(): void
    {
        render(<<<HTML
          <div class="bg-blue-500 text-blue px-2 uppercase">Release Tool</div>
        HTML
        );
    }

    /**
     * Print error message using Termwind
     *
     * @param string $message Error message to display
     */
    protected function printError(string $message): void
    {
        render(<<<HTML
          <div>
            <span class="bg-red-500 text-red px-1 mr-1">Error</span>
            <span class="text-red">$message</span>
          </div>
        HTML
        );
    }

    /**
     * Print error message and exit the application
     *
     * @param string $message Error message to display before exit
     */
    protected function printErrorAndExit(string $message): void
    {
        $this->printError($message);
        exit;
    }

    /**
     * Print variable name and value using Termwind
     *
     * @param string $name Variable name
     * @param string|null $value Variable value
     */
    protected function printVar(string $name, ?string $value): void
    {
        render(<<<HTML
          <div>
            <span class="text-gray-900 mr-1">$name:</span>
            <span class="text-blue">$value</span>
          </div>
        HTML
        );
    }

    /**
     * Print question prompt and return user input
     *
     * @param string $question Question to ask user
     * @return mixed User's response
     */
    protected function printQuestion(string $question): mixed
    {
        return ask(<<<HTML
          <div>
            <span class="font-bold mr-1">Question:</span>
            <span class="italic mr-1">$question</span>
          </div>
        HTML
        );
    }

    /**
     * Print task status using Termwind
     *
     * @param  string  $title
     * @param  string  $type
     * @param  string  $status
     * @return void
     */
    protected function printStatus(string $title, string $type = 'success', string $status = 'OK'): void
    {
        $class = match (strtolower($type)) {
            'success' => 'text-green',
            'error' => 'text-red',
            'warning' => 'text-yellow',
            default => 'text-gray-200',
        };

        $result = sprintf('<span class="%s uppercase font-bold">%s</span>', $class, $status);

        render(<<<HTML
          <div>
            <span class="text-gray-200 mr-1">$title</span>
            $result
          </div>
        HTML
        );
    }

    /**
     * Print regular string using Termwind
     *
     * @param string $string String to display
     */
    protected function printString(string $string): void
    {
        render(<<<HTML
          <span class="text-gray-200 mr-1">$string</span>
        HTML
        );
    }

    protected function printText(string $text): void
    {
        foreach (explode("\n", $text) as $line) {
            $this->printString($line);
        }
    }

    protected function getArgument(string $prefix, ?string $defaultValue = null): ?string
    {
        $argv = $GLOBALS['argv'] ?? [];

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, $prefix . '=')) {
                $value = substr($arg, strlen($prefix) + 1);
                return $value !== '' ? $value : '';
            }
        }

        return $defaultValue;
    }

    protected function processPotTask(array $task): void
    {
        $domain = $task['domain'] ?? $this->composer->name ?? ($this->package->name ?? 'messages');
        $outputFile = $task['output'] ?? $domain . '.pot';
        $functions = $task['functions'] ?? ['__', '_e', '_x', '_n', '$t', '_ex', '_nx'];

        $pluralFunctions = array_intersect($functions, ['_n', 'ngettext', '_n_noop', '_nx', '_nx_noop']);
        $singleFunctions = array_diff($functions, $pluralFunctions);

        $strings = []; // ordered collection
        $seen = [];    // uniqueness set

        foreach ($task['items'] as $item) {
            foreach ($this->getTaskItemFolders($item) as $folder) {
                $searchFolderPath = $this->sourcePath($folder);
                foreach ($this->makeFinder($searchFolderPath, $item) as $match) {
                    if (is_dir($match->getRealPath())) {
                        continue;
                    }
                    $contents = $match->getContents();

                    // Single/context functions: first argument only
                    foreach ($singleFunctions as $fn) {
                        $rx = '/\b' . preg_quote($fn, '/') . '\s*\(\s*(["\'])(.*?)\1/';
                        if (preg_match_all($rx, $contents, $ms, PREG_SET_ORDER)) {
                            foreach ($ms as $m) {
                                $text = $this->unescapeString($m[2] ?? '');
                                if ($text !== '' && !isset($seen[$text])) {
                                    $seen[$text] = true;
                                    $strings[] = $text;
                                }
                            }
                        }
                    }

                    // Plural functions: first two translatable args
                    foreach ($pluralFunctions as $fn) {
                        $rxPlural = '/\b' . preg_quote($fn, '/') . '\s*\(\s*(["\'])(.*?)\1\s*,\s*(["\'])(.*?)\3/';
                        if (preg_match_all($rxPlural, $contents, $mp, PREG_SET_ORDER)) {
                            foreach ($mp as $p) {
                                $singular = $this->unescapeString($p[2] ?? '');
                                $plural = $this->unescapeString($p[4] ?? '');
                                if ($singular !== '' && !isset($seen[$singular])) {
                                    $seen[$singular] = true;
                                    $strings[] = $singular;
                                }
                                if ($plural !== '' && !isset($seen[$plural])) {
                                    $seen[$plural] = true;
                                    $strings[] = $plural;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Alphabetical (case-insensitive natural) ordering
        natcasesort($strings);
        $strings = array_values($strings);

        $productName = $this->composer->name ?? ($this->package->name ?? 'Project');
        $pot = $this->buildPot($strings, $productName);

        $outputPath = $this->targetPath($outputFile);
        $this->ensureDirectoryExists(dirname($outputPath));
        file_put_contents($outputPath, $pot);

        $phpFile = <<<PHP
        <?php
            defined('ABSPATH') or die('Direct access is not allowed');
            
            return [
              %s
            ];
        PHP;

        $phpFile = sprintf($phpFile, implode(",\n", array_map(function (string $string) use ($domain) {
            return "'" . addslashes($string) . "' => __('" . addslashes($string) . "', '" . addslashes($domain) . "')";
        }, $strings)));

        file_put_contents($this->targetPath('text-strings.php'), $phpFile);
    }

    /**
     * Create POT content from collected strings.
     *
     * @param array $strings
     * @param string $projectName
     * @return string
     */
    protected function buildPot(array $strings, string $projectName): string
    {
        $header = ''
            . 'msgid ""' . "\n"
            . 'msgstr ""' . "\n"
            . '"Project-Id-Version: ' . addslashes($projectName) . '\n"' . "\n"
            . '"Report-Msgid-Bugs-To: \n"' . "\n"
            . '"POT-Creation-Date: ' . gmdate('Y-m-d H:i:s\Z') . '\n"' . "\n"
            . '"PO-Revision-Date: ' . gmdate('Y-m-d H:i:s\Z') . '\n"' . "\n"
            . '"Last-Translator: \n"' . "\n"
            . '"Language-Team: \n"' . "\n"
            . '"MIME-Version: 1.0\n"' . "\n"
            . '"Content-Type: text/plain; charset=UTF-8\n"' . "\n"
            . '"Content-Transfer-Encoding: 8bit\n"' . "\n"
            . '"X-Generator: ReleaseTool\n"' . "\n\n";

        $body = '';
        foreach ($strings as $s) {
            // Skip empty and whitespace-only
            if (trim($s) === '') {
                continue;
            }
            $body .= 'msgid "' . $this->escapePo($s) . '"' . "\n";
            $body .= 'msgstr ""' . "\n\n";
        }

        return $header . $body;
    }

    /**
     * Unescape common string literal escapes to raw text.
     */
    protected function unescapeString(string $s): string
    {
        // Handle PHP/JS-style escapes minimally
        $s = str_replace(["\\n", "\\r", "\\t"], ["\n", "\r", "\t"], $s);
        $s = str_replace(['\\"', "\\'"], ['"', "'"], $s);
        return $s;
    }

    /**
     * Escape text for PO msgid/msgstr.
     */
    protected function escapePo(string $s): string
    {
        $s = str_replace(["\\", "\""], ["\\\\", "\\\""], $s);
        $s = str_replace(["\r\n", "\r"], ["\n", "\n"], $s);
        $s = str_replace("\n", "\\n", $s);
        return $s;
    }
}
