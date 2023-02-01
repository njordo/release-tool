<?php

namespace Financialplugins\ReleaseTool;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Finder\Finder;
use ZipArchive;
use function Termwind\{render, ask};

class Release
{
    protected const CONFIG_FILE_NAME = 'release.json';
    protected const TARGET_FOLDER_NAME = 'release';

    protected $sourceFolderPath;
    protected $targetFolderPath;
    protected $config;

    public function __construct()
    {
        $this->renderHeader();
        $this->sourceFolderPath = getcwd();
        $configFilePath = $this->sourcePath(static::CONFIG_FILE_NAME);

        $this->renderVar('Source folder', $this->sourceFolderPath);

        if (!file_exists($configFilePath)) {
            $this->renderError(sprintf('release.json file not found in %s', $this->sourceFolderPath));
            exit;
        }

        $this->config = json_decode(file_get_contents($configFilePath), JSON_OBJECT_AS_ARRAY);
        $this->targetFolderPath = $this->sourcePath($this->config['target'] ?? static::TARGET_FOLDER_NAME);

        $this->renderVar('Target folder', $this->targetFolderPath);

        if ($this->sourceFolderPath == $this->targetFolderPath) {
            $this->renderError(sprintf('Check the target path, it can not be the same as the source path: %s', $this->sourceFolderPath));
            exit;
        }

        if (!isset($this->config['zip']) || $this->config['zip'] == '') {
            $this->renderError('Application zip file name should be specified.');
            exit;
        }

        $this->renderVar('Number of tasks', count($this->config['tasks']));

//        $a = $this->renderQuestion('Do you need FTP?');

//        $this->renderVar('FTP', $a);

        $this->prepare();
        $this->copy();

        return $this;
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

    protected function prepare(): void
    {
        $this->deleteFolder($this->targetFolderPath);
        $this->createFolder($this->targetFolderPath);
    }

    protected function copy(): void
    {
        $fullZipArchive = new ZipArchive;
        $fullZipArchive->open($this->targetPath($this->config['zip']), ZipArchive::CREATE);

        foreach ($this->config['tasks'] as $i => $config) {
            $baseTargetFolderPath = $this->targetPath($config['target']);
            $this->createFolder($baseTargetFolderPath);

            $itemZipArchive = new ZipArchive;
            $itemZipFilePath = $this->targetPath($config['zip']);
            $itemZipArchive->open($itemZipFilePath, ZipArchive::CREATE);

            foreach ($config['map'] as $item) {
                $searchFolderPath = $this->sourcePath(is_array($item) ? $item['folder'] : $item);

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

                foreach ($finder as $matchedItem) {
//                    $this->renderText(sprintf('Matched item: %s', $matchedItem->getRealPath()));

                    $matchedItemTempPath = str_replace($this->sourceFolderPath, $baseTargetFolderPath, $matchedItem->getRealPath());
                    $zipRealPath = trim(str_replace($baseTargetFolderPath, '', $matchedItemTempPath), DIRECTORY_SEPARATOR);

                    // if the found item is a folder
                    if (is_dir($matchedItem->getRealPath())) {
                        $this->createFolder($matchedItemTempPath);
//                        $this->renderText(sprintf('Add folder to full zip archive: %s', $zipRealPath));

                        $fullZipArchive && $fullZipArchive->addEmptyDir($zipRealPath);
                        $itemZipArchive && $itemZipArchive->addEmptyDir($zipRealPath);
                    } else {
                        $matchedFileTempFolderPath = substr($matchedItemTempPath, 0, strrpos($matchedItemTempPath, $matchedItem->getFilename()) - 1);
                        $this->createFolder($matchedFileTempFolderPath);

                        // copy file to the temp folder
                        $this->copyFile($matchedItem->getRealPath(), $matchedItemTempPath);

                        // zip file to the full archive
                        $fullZipArchive->addFile($matchedItem->getRealPath(), $zipRealPath);
                        $itemZipArchive->addFile($matchedItem->getRealPath(), $zipRealPath);
                    }
                }
            }

            $this->renderResult(sprintf('Task %d, zip %s, %d files', ++$i, $itemZipFilePath, $itemZipArchive->count()), $itemZipArchive->close());
        }

        $this->renderResult(sprintf('Full zip %s, %d files', $this->targetPath($this->config['zip']), $fullZipArchive->count()), $fullZipArchive->close());
    }

    public function createFolder(string $path): bool
    {
        $result = FALSE;

        if (is_dir($path)) {
            $result = TRUE;
        } else {
            try {
                $result = mkdir($path, 0755, TRUE);
            } catch (Exception $e) {
                $this->renderError($e->getMessage());
            }
        }

//        $this->renderResult(sprintf('Create folder %s', $path), $result);

        return $result;
    }

    /**
     * Delete folder and all files and subfolders recursively
     *
     * @param  string  $path
     * @return bool
     */
    protected function deleteFolder(string $path): bool
    {
        $result = FALSE;

        if (is_dir($path)) {
            try {
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

                foreach ($files as $file) {
                    if ($file->isDir()) {
                        rmdir($file->getPathName());
                    } else {
                        if (($file->isFile() || $file->isLink())) {
                            unlink($file->getPathname());
                        }
                    }
                }

                $result = rmdir($path);
            } catch (Exception $e) {
                $this->renderError($e->getMessage());
            }
        }

//        $this->renderResult(sprintf('Delete folder %s', $path), $result);

        return $result;
    }

    protected function copyFile(string $source, string $destination): bool
    {
        $result = FALSE;

        try {
            $result = copy($source, $destination);
        } catch (Exception $e) {
            $this->renderError($e->getMessage());
        }

//        $this->renderResult(sprintf('Copy file %s to %s', $source, $destination), $result);

        return $result;
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
