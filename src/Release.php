<?php

namespace Financialplugins\ReleaseTool;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Finder\Finder;
use ZipArchive;
use function Termwind\{render, ask, terminal};

class Release
{
    protected const CONFIG_FILE_NAME = 'release.json';
    protected const TARGET_FOLDER_NAME = 'release';

    protected $sourceFolderPath;
    protected $targetFolderPath;
    protected $config;
    protected $zip;

    public function __construct()
    {
        terminal()->clear();
        $this->renderHeader();
        $this->sourceFolderPath = getcwd();
        $configFilePath = $this->sourcePath(static::CONFIG_FILE_NAME);

        $this->renderVar('Source folder', $this->sourceFolderPath);

        if (!file_exists($configFilePath)) {
            $this->renderError(sprintf('release.json file not found in %s', $this->sourceFolderPath));
            exit;
        }

        $this->config = json_decode(file_get_contents($configFilePath), JSON_OBJECT_AS_ARRAY);
        $this->targetFolderPath = $this->sourcePath(static::TARGET_FOLDER_NAME);

        $this->renderVar('Target folder', $this->targetFolderPath);

        if ($this->sourceFolderPath == $this->targetFolderPath) {
            $this->renderError(sprintf('Check the target path, it can not be the same as the source path: %s', $this->sourceFolderPath));
            exit;
        }

        $this->renderVar('Number of tasks', count($this->config['tasks']));

        if (!isset($this->config['zip']) || $this->config['zip'] == '') {
            $this->renderError('Application zip file name should be specified.');
            exit;
        }

        $this->zip = new ZipArchive;
        $this->zip->open($this->targetPath($this->config['zip']), ZipArchive::CREATE);

//        $a = $this->renderQuestion('Do you need FTP?');
//        $this->renderVar('FTP', $a);

        try {
            $this->deleteFolder($this->targetFolderPath);
            $this->createFolder($this->targetFolderPath);
        } catch (Exception $e) {
            $this->renderError($e->getMessage());
            exit;
        }

        $this->run();

        $this->renderResult(sprintf('Full zip %s, %d files', $this->targetPath($this->config['zip']), $this->zip->count()), $this->zip->close());
        $this->renderText('Release completed.');
    }

    protected function renderHeader(): void
    {
        render(<<<HTML
          <div class="bg-blue-500 text-blue px-2 uppercase">Release Tool</div>
        HTML);
    }

    protected function renderError(string $message): void
    {
        render(<<<HTML
          <div>
            <span class="bg-red-500 text-red px-1 mr-1">Error</span>
            <span class="text-red">$message</span>
          </div>
        HTML);
    }

    protected function renderVar(string $name, ?string $value): void
    {
        render(<<<HTML
          <div>
            <span class="text-gray-900 mr-1">$name:</span>
            <span class="text-blue">$value</span>
          </div>
        HTML);
    }

    protected function renderQuestion(string $question): mixed
    {
        return ask(<<<HTML
          <div>
            <span class="font-bold mr-1">Question:</span>
            <span class="italic mr-1">$question</span>
          </div>
        HTML);
    }

    protected function renderResult(string $title, bool $success): void
    {
        $result = $success ? "<span class=\"text-green uppercase font-bold\">Ok</span>" : "<span class=\"text-red uppercase font-bold\">Error</span>";

        render(<<<HTML
          <div>
            <span class="text-gray-200 mr-1">$title</span>
            $result
          </div>
        HTML);
    }

    protected function renderText(string $string): void
    {
        render(<<<HTML
          <span class="text-gray-200 mr-1">$string</span>
        HTML);
    }

    protected function processDeleteTask(array $task): void
    {
        foreach ($task['items'] as $item) {
            $searchFolderPath = $this->sourcePath($this->getItemFolder($item));

            foreach (iterator_to_array($this->initFinder($searchFolderPath, $item)) as $matchedItem) {
                $path = $matchedItem->getRealPath();

                if (is_dir($path)) {
                    $this->deleteFolder($path);
                } elseif (is_file($path) || is_link($path)) {
                    unlink($path);
                }
            }
        }
    }

    protected function processCommandTask(array $task): void
    {
        foreach ($task['items'] as $command) {
            // run command
            $result = shell_exec($command);

            if (!$result) {
                throw new Exception(sprintf('Command can not be completed: %s', $command));
            }
        }
    }

    protected function processMkdirTask2(array $task): void
    {
        foreach ($task['items'] as $item) {
            $this->createFolder($this->targetPath($item));
        }
    }

    protected function processZipTask2(array $task): void
    {
        $itemZipArchive = new ZipArchive;
        $itemZipFilePath = $this->targetPath($task['zip']);
        $itemZipArchive->open($itemZipFilePath, ZipArchive::CREATE);

        foreach ($task['items'] as $item) {
            $relativePath = $this->getItemFolder($item);
            $searchFolderPath = $this->sourcePath($relativePath);

            foreach ($this->initFinder($searchFolderPath, $item) as $matchedItem) {
                $zipPath = $relativePath . DIRECTORY_SEPARATOR . $matchedItem->getRelativePathname();

                // if the found item is a folder
                if (is_dir($matchedItem->getRealPath())) {
                    $this->zip->addEmptyDir($zipPath);
                    $itemZipArchive->addEmptyDir($zipPath);
                } else {
                    // zip file to the full archive
                    $this->zip->addFile($matchedItem->getRealPath(), $zipPath);
                    $itemZipArchive->addFile($matchedItem->getRealPath(), $zipPath);
                }
            }
        }

        $itemZipArchive->close();
    }

    protected function run(): void
    {
        foreach ($this->config['tasks'] as $i => $task) {
            $methodName = sprintf('process%sTask', ucfirst($this->getTaskType($task)));

            if (method_exists($this, $methodName)) {
                try {
                    $this->$methodName($task);
                    $this->renderResult(sprintf('Task #%d (%s)', ++$i, $this->getTaskType($task)), TRUE);
                } catch (Exception $e) {
                    $this->renderError($e->getMessage());
                    $this->renderResult(sprintf('Task #%d (%s)', ++$i, $this->getTaskType($task)), FALSE);
                    exit;
                }
            }
        }
    }

    protected function getTaskType(array $task): string
    {
        return $task['type'] ?? 'zip';
    }

    protected function getItemFolder(string|array $item): string
    {
        return is_array($item) ? $item['folder'] : $item;
    }

    protected function initFinder(string $searchFolderPath, string|array $item): Finder
    {
        $finder = (new Finder)->in($searchFolderPath)->ignoreDotFiles(FALSE);

        if (is_array($item)) {
            if (isset($item['files']) && $item['files'] === FALSE) {
                $finder->directories();
            } else {
                $finder->files();
            }

            if (isset($item['name'])) {
                $finder->name($item['name']);
            }
            if (isset($item['depth'])) {
                $finder->depth($item['depth']);
            }
            if (isset($item['exclude'])) {
                $finder->exclude($item['exclude']);
            }
        }

        return $finder;
    }

    protected function createFolder(string $path): bool
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
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathName());
                } else {
                    if (($file->isFile() || $file->isLink())) {
                        @unlink($file->getPathname()) or throw new Exception(sprintf('Resource temporarily unavailable: %s', $file->getPathname()));
                    }
                }
            }

            return rmdir($path);
        }

        return FALSE;
    }

    protected function sourcePath(string $relativePath): string
    {
        return trim($this->sourceFolderPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
    }

    protected function targetPath(string $relativePath): string
    {
        return trim($this->targetFolderPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
    }
}
