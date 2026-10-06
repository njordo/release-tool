<?php

namespace Financialplugins\ReleaseTool;

use Closure;
use Exception;

/**
 * An isolated copy of a project exported from a git commit, plus the state needed to reuse it between runs.
 *
 * Layout under the cache root:
 *   stages/<name>/        the exported (and prepared) project
 *   stages/<name>.json    commit, hash of the "source" config and whether preparation finished
 *   stages/<name>.lock    held for the whole run so two runs never work in the same stage
 */
class GitStage
{
    protected string $stagesPath;
    protected string $stagePath;
    protected string $statePath;
    protected string $lockPath;

    /** @var resource|null */
    protected $lockHandle = null;

    /**
     * @throws Exception
     */
    public function __construct(protected string $projectPath, string $cacheRootPath)
    {
        if (PHP_OS_FAMILY === 'Windows') {
            throw new Exception('Building from git requires git, tar and symlinks (Linux, macOS or WSL). Use "type": "working-tree" on Windows.');
        }

        $realProjectPath = realpath($projectPath);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($realProjectPath)) . '-' . substr(sha1($realProjectPath), 0, 8);

        $this->stagesPath = rtrim($cacheRootPath, '/') . '/stages';
        $this->stagePath = $this->stagesPath . '/' . $name;
        $this->statePath = $this->stagesPath . '/' . $name . '.json';
        $this->lockPath = $this->stagesPath . '/' . $name . '.lock';
    }

    public function path(): string
    {
        return $this->stagePath;
    }

    /**
     * Resolve a ref to a commit SHA
     *
     * @throws Exception
     */
    public function resolveRef(string $ref): string
    {
        [$code, $lines] = $this->git(['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);

        if ($code !== 0 || !isset($lines[0])) {
            throw new Exception(sprintf('"%s" is not a commit in %s.', $ref, $this->projectPath));
        }

        return $lines[0];
    }

    /**
     * When the commit to build is the checked out one, refuse to build while tracked files have uncommitted changes:
     * the artifacts would claim to be that commit but contain something else.
     *
     * @return string[] Untracked files, which are not part of a build (a warning, not an error)
     * @throws Exception
     */
    public function assertCommitted(string $sha): array
    {
        [$code, $lines] = $this->git(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);

        if ($code !== 0 || ($lines[0] ?? null) !== $sha) {
            return [];
        }

        [, $status] = $this->git(['status', '--porcelain', '--', '.']);

        $untracked = [];
        $modified = [];
        foreach ($status as $line) {
            if (str_starts_with($line, '??')) {
                $untracked[] = substr($line, 3);
            } else {
                $modified[] = $line;
            }
        }

        if ($modified) {
            throw new Exception(sprintf(
                "Uncommitted changes in tracked files, commit or stash them (or build another commit with ref=<ref>):\n%s",
                implode("\n", array_slice($modified, 0, 10))
            ));
        }

        return $untracked;
    }

    /**
     * Take the lock for this stage
     *
     * @throws Exception
     */
    public function lock(): void
    {
        $this->ensureDirectory($this->stagesPath);

        $this->lockHandle = fopen($this->lockPath, 'c');

        if (!$this->lockHandle || !flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            throw new Exception('Another release run is using the same stage. Wait for it to finish.');
        }
    }

    /**
     * A stage is reusable when it was exported from the same commit, prepared by the same steps and the preparation
     * finished
     */
    public function isFresh(string $sha, string $prepareHash): bool
    {
        $state = $this->readState();

        return is_dir($this->stagePath)
            && ($state['sha'] ?? null) === $sha
            && ($state['prepareHash'] ?? null) === $prepareHash
            && ($state['prepared'] ?? false) === true;
    }

    /**
     * Export the commit into a clean stage and run the preparation. The state only says "prepared" once every step
     * succeeded, so a failed preparation is never reused.
     *
     * @param Closure $prepare Receives the stage path
     * @throws Exception
     */
    public function rebuild(string $sha, string $prepareHash, Closure $prepare): void
    {
        $this->writeState(['sha' => $sha, 'prepareHash' => $prepareHash, 'prepared' => false]);

        $this->remove($this->stagePath);
        $this->ensureDirectory($this->stagePath);

        // The archive goes through a file, not a pipe, so that a failing git is not hidden by tar's exit code
        $tarPath = $this->stagePath . '.tar';
        [$prefix] = $this->git(['rev-parse', '--show-prefix']);
        $treeish = $sha . (isset($prefix[0]) && $prefix[0] !== '' ? ':' . $prefix[0] : '');

        [$code, $lines] = $this->git(['archive', '--format=tar', '-o', $tarPath, $treeish]);
        if ($code !== 0) {
            throw new Exception('git archive failed: ' . implode(' ', $lines));
        }

        exec(sprintf('tar -xf %s -C %s 2>&1', escapeshellarg($tarPath), escapeshellarg($this->stagePath)), $output, $code);
        @unlink($tarPath);
        if ($code !== 0) {
            throw new Exception('Unable to extract the exported commit: ' . implode(' ', $output));
        }

        $prepare($this->stagePath);

        $this->writeState(['sha' => $sha, 'prepareHash' => $prepareHash, 'prepared' => true]);
    }

    /**
     * Make `<stage>/release` point at the project's release folder, so the paths in the config that refer to
     * "release/..." keep working and the artifacts stay in the project.
     *
     * @throws Exception
     */
    public function linkRelease(string $releaseFolderPath): void
    {
        $linkPath = $this->stagePath . '/release';

        if (is_link($linkPath)) {
            if (readlink($linkPath) === $releaseFolderPath) {
                return;
            }

            unlink($linkPath);
        } elseif (file_exists($linkPath)) {
            throw new Exception('The commit contains a "release" path, which would clash with the release folder.');
        }

        if (!symlink($releaseFolderPath, $linkPath)) {
            throw new Exception(sprintf('Unable to link %s to %s.', $linkPath, $releaseFolderPath));
        }
    }

    /**
     * Remove a file, symlink or folder inside the stages folder. `rm -rf` never follows symlinks, which matters because
     * the stage contains a link to the project's release folder.
     *
     * @throws Exception
     */
    protected function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (!str_starts_with($path, $this->stagesPath . '/')) {
            throw new Exception(sprintf('Refusing to remove a path outside the stages folder: %s', $path));
        }

        exec(sprintf('rm -rf %s 2>&1', escapeshellarg($path)), $output, $code);

        if ($code !== 0 || file_exists($path) || is_link($path)) {
            throw new Exception(sprintf(
                'Unable to remove the old stage %s (files created by a container running as root cannot be removed by this user): %s',
                $path,
                implode(' ', $output)
            ));
        }
    }

    /**
     * @return array{0: int, 1: string[]} Exit code and output lines
     */
    protected function git(array $args): array
    {
        $command = 'git -C ' . escapeshellarg($this->projectPath) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($command, $lines, $code);

        return [$code, $lines];
    }

    protected function readState(): array
    {
        $state = is_file($this->statePath) ? json_decode((string) file_get_contents($this->statePath), true) : null;

        return is_array($state) ? $state : [];
    }

    protected function writeState(array $state): void
    {
        file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * @throws Exception
     */
    protected function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new Exception(sprintf('Unable to create the folder: %s', $path));
        }
    }
}
