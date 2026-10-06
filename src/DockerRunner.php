<?php

namespace Financialplugins\ReleaseTool;

use Exception;

/**
 * Runs shell commands inside a throwaway Docker container.
 *
 * The source folder is mounted at the working directory, the process runs as the host user (so files it writes are not
 * root-owned) and a host cache folder is mounted at /cache so package manager caches survive between runs.
 */
class DockerRunner
{
    protected const DEFAULT_WORKDIR = '/work';
    protected const DEFAULT_SHELL = 'sh';

    /** @var array<string, true> Images that have already passed the pre-flight check in this run */
    protected static array $checkedImages = [];

    /**
     * @param string $cacheFolderPath Host folder mounted at /cache
     */
    public function __construct(protected string $cacheFolderPath)
    {
    }

    /**
     * Run one shell command in a container and return its exit code. Output is streamed.
     *
     * @param array $docker The "docker" block of a task
     * @param string $sourceFolderPath Host folder mounted as the working directory
     * @throws Exception When Docker or the image is unavailable
     */
    public function run(array $docker, string $sourceFolderPath, string $command): int
    {
        $this->assertImageAvailable($docker);

        if (!is_dir($this->cacheFolderPath) && !@mkdir($this->cacheFolderPath, 0775, true) && !is_dir($this->cacheFolderPath)) {
            throw new Exception(sprintf('Unable to create the cache folder: %s', $this->cacheFolderPath));
        }

        passthru($this->buildCommand($docker, $sourceFolderPath, $command), $exitCode);

        return $exitCode;
    }

    /**
     * Build the full `docker run` command line
     */
    public function buildCommand(array $docker, string $sourceFolderPath, string $command): string
    {
        $workdir = $docker['workdir'] ?? static::DEFAULT_WORKDIR;
        $shell = $docker['shell'] ?? static::DEFAULT_SHELL;

        $args = ['docker', 'run', '--rm', '--init'];

        $user = $docker['user'] ?? $this->hostUser();
        if ($user !== null && $user !== 'root') {
            $args[] = '--user ' . escapeshellarg($user);
        }

        if (!empty($docker['network'])) {
            $args[] = '--network ' . escapeshellarg($docker['network']);
        }

        // HOME is not writable for an arbitrary uid, and tools such as npm and composer need somewhere to write
        $env = array_merge(['HOME' => '/tmp'], $docker['env'] ?? []);
        foreach ($env as $name => $value) {
            $args[] = '-e ' . escapeshellarg($name . '=' . $value);
        }

        $volumes = array_merge(
            [$sourceFolderPath => $workdir, $this->cacheFolderPath => '/cache'],
            $docker['volumes'] ?? []
        );
        foreach ($volumes as $hostPath => $containerPath) {
            $args[] = '-v ' . escapeshellarg($hostPath . ':' . $containerPath);
        }

        $args[] = '-w ' . escapeshellarg($workdir);
        // Replace the image's entrypoint: project images (for example an app image) start services in theirs
        $args[] = '--entrypoint ' . escapeshellarg($shell);
        $args[] = escapeshellarg($docker['image']);
        $args[] = '-c ' . escapeshellarg($command);

        return implode(' ', $args);
    }

    /**
     * The host user as uid:gid, or null where the platform has no such concept (Windows)
     */
    protected function hostUser(): ?string
    {
        if (!function_exists('posix_getuid')) {
            return null;
        }

        return posix_getuid() . ':' . posix_getgid();
    }

    /**
     * Make sure Docker works and the image exists locally, pulling it when it does not
     *
     * @throws Exception
     */
    protected function assertImageAvailable(array $docker): void
    {
        $image = $docker['image'] ?? null;

        if (!is_string($image) || $image === '') {
            throw new Exception('The "docker" option requires an "image".');
        }

        if (isset(static::$checkedImages[$image])) {
            return;
        }

        $output = [];
        exec('docker version --format "{{.Server.Version}}" 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            throw new Exception('Docker is not available (is it installed, running and accessible to this user?): ' . implode(' ', $output));
        }

        $output = [];
        exec('docker image inspect ' . escapeshellarg($image) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $output = [];
            exec('docker pull ' . escapeshellarg($image) . ' 2>&1', $output, $exitCode);
            if ($exitCode !== 0) {
                throw new Exception(sprintf(
                    'Docker image "%s" was not found locally and could not be pulled. Build the project images first (for example "docker compose build").',
                    $image
                ));
            }
        }

        static::$checkedImages[$image] = true;
    }
}
