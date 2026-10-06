<?php

namespace Financialplugins\ReleaseTool;

use Closure;
use Exception;

/**
 * Executes the commands of a "command" task or a source "prepare" step, on the host or inside a Docker container.
 */
class CommandRunner
{
    /**
     * @param string $sourceFolderPath Folder the commands run in (the current directory on the host, the mount in Docker)
     * @param Closure $warn Receives a message when a command fails but the failure is tolerated
     */
    public function __construct(
        protected DockerRunner $docker,
        protected string $sourceFolderPath,
        protected Closure $warn,
    ) {
    }

    /**
     * Run every command of a task or step.
     *
     * A failing command stops the run when "failOnError" is true. Its default is true for Docker commands and when the
     * source is staged from git, and false otherwise, because host commands used to be fire-and-forget.
     *
     * @param array $task Configuration with "items" and optionally "docker" and "failOnError"
     * @param bool $strictByDefault Default for "failOnError" of commands that run on the host
     * @throws Exception
     */
    public function run(array $task, bool $strictByDefault): void
    {
        $docker = $task['docker'] ?? null;
        $failOnError = $task['failOnError'] ?? ($docker !== null || $strictByDefault);

        foreach ($task['items'] ?? [] as $command) {
            if (!is_string($command)) {
                throw new Exception('Command items must be strings.');
            }

            $exitCode = $docker !== null
                ? $this->docker->run($docker, $this->sourceFolderPath, $command)
                : $this->runOnHost($command);

            if ($exitCode === 0) {
                continue;
            }

            if ($failOnError) {
                throw new Exception(sprintf('Command "%s" failed with exit code %d.', $command, $exitCode));
            }

            ($this->warn)(sprintf('Command "%s" exited with code %d, continuing. Set "failOnError": true to stop instead.', $command, $exitCode));
        }
    }

    protected function runOnHost(string $command): int
    {
        $result = passthru($command, $exitCode);

        // passthru() only returns false when the command could not be started at all
        return $result === false ? 1 : $exitCode;
    }
}
