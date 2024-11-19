<?php

namespace Financialplugins\ReleaseTool;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use ZipArchive;
use function Termwind\{render, ask, terminal};

class Release
{
    protected const CONFIG_FILE_NAME = 'release.json';
    protected const COMPOSER_FILE_NAME = 'composer.json';
    protected const SERVERS_FILE_NAME = '.idea/WebServers.xml';
    protected const TARGET_FOLDER_NAME = 'release';

    protected $sourceFolderPath;
    protected $targetFolderPath;
    protected $composer;
    protected $config;
    protected $servers;

    protected $taskIds;

    public function __construct(array $argv)
    {
        terminal()->clear();
        $this->printHeader();
        $this->sourceFolderPath = getcwd();
        $this->targetFolderPath = $this->sourcePath(static::TARGET_FOLDER_NAME);

        if ($this->sourceFolderPath == $this->targetFolderPath) {
            $this->printErrorAndExit(sprintf('Check the target path, it can not be the same as the source path: %s', $this->sourceFolderPath));
        }

        foreach ([static::CONFIG_FILE_NAME, static::COMPOSER_FILE_NAME/*, static::SERVERS_FILE_NAME*/] as $fileName) {
            $filePath = $this->sourcePath($fileName);

            if (!file_exists($filePath)) {
                $this->printErrorAndExit(sprintf('%s file not found in %s', $fileName, $this->sourceFolderPath));
            }
        }

        $this->composer = json_decode(file_get_contents($this->sourcePath(static::COMPOSER_FILE_NAME)));
        $this->config = json_decode(file_get_contents($this->sourcePath(static::CONFIG_FILE_NAME)), JSON_OBJECT_AS_ARRAY);
        $this->taskIds = array_slice($argv, 1);
//        $this->servers = $this->mapWebservers(simplexml_load_file($this->sourcePath(static::SERVERS_FILE_NAME)));

        $this->printVar('Source folder', $this->sourceFolderPath);
        $this->printVar('Target folder', $this->targetFolderPath);
//        $this->printVar('Number of servers', count($this->servers));
        $this->printVar('Number of tasks', count($this->config['tasks']));

//        $a = $this->renderQuestion('Do you need FTP?');
//        $this->renderVar('FTP', $a);

        try {
            $this->deleteFolder($this->targetFolderPath);
            $this->createFolder($this->targetFolderPath);
        } catch (Exception $e) {
            $this->printErrorAndExit($e->getMessage());
        }

        $this->process();

        $this->printString('Release completed.');
    }

    protected function process(): void
    {
        foreach ($this->config['tasks'] as $i => $task) {
            $methodName = sprintf('process%sTask', ucfirst($this->getTaskType($task)));

            if (isset($task['skip']) || (!empty($this->taskIds) && (!isset($task['id']) || !in_array($task['id'], $this->taskIds)))) {
                $this->printStatus(sprintf('Task #%d (%s)', ++$i, 'skipped'), TRUE);
                continue;
            }

            if (method_exists($this, $methodName)) {
                try {
                    $this->$methodName($task);
                    $this->printStatus(sprintf('Task #%d (%s)', ++$i, $this->getTaskType($task)), TRUE);
                } catch (Exception $e) {
                    $this->printStatus(sprintf('Task #%d (%s)', ++$i, $this->getTaskType($task)), FALSE);
                    $this->printErrorAndExit($e->getMessage());
                }
            }
        }
    }

    protected function processDeleteTask(array $task): void
    {
        foreach ($task['items'] as $item) {
            foreach ($this->getTaskItemFolder($item) as $folder) {
                $searchFolderPath = $this->sourcePath($folder);

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

    protected function processCommandTask(array $task): void
    {
        foreach ($task['items'] as $command) {
            $result = shell_exec($command);

            if ($result === FALSE) {
                $this->printErrorAndExit(sprintf('Command "%s" can not be completed, result: %s', $command, $result));
            }
        }
    }

    protected function processMkdirTask(array $task): void
    {
        foreach ($task['items'] as $item) {
            $this->createFolder($this->targetPath($item));
        }
    }

    protected function processCopyTask(array $task): void
    {
        foreach ($task['items'] as $item) {
            foreach ($this->getTaskItemFolder($item) as $folder) {
                $searchFolderPath = $this->sourcePath($folder);

                foreach ($this->makeFinder($searchFolderPath, $item) as $match) {
                    $destinationPath = isset($item['destination'])
                        ? $this->targetPath($item['destination'] . '/' . $match->getRelativePathname())
                        : $this->targetPath($folder . '/' . $match->getRelativePathname());

                    if (is_dir($match->getRealPath())) {
                        $this->createFolder($destinationPath);
                    } else {
                        $fileFolderFolderPath = substr($destinationPath, 0, strrpos($destinationPath, $match->getFilename()) - 1);
                        $this->createFolder($fileFolderFolderPath);
                        copy($match->getRealPath(), $destinationPath);
                    }
                }
            }
        }
    }

    protected function processZipTask(array $task): void
    {
        $itemZipArchive = new ZipArchive;
        $itemZipFilePath = $this->targetPath($task['zip']);
        $itemZipArchive->open($itemZipFilePath, ZipArchive::CREATE);
        $itemZipRoot = $task['root'] ?? '';

        foreach ($task['items'] as $item) {
            // loop through folders of each item
            foreach ($this->getTaskItemFolder($item) as $folder) {
                $searchFolderPath = $this->sourcePath($folder);

                // loop through matched items (files or folders) in this folder
                foreach ($this->makeFinder($searchFolderPath, $item) as $match) {
                    $zipRootFolder = $itemZipRoot ? $itemZipRoot . '/' : '';
                    $zipDestinationFolder = isset($item['destination']) ? $item['destination'] . '/' : ($folder ? $folder . '/' : '');

                    $zipPath = $zipRootFolder . $zipDestinationFolder . $this->path($match->getRelativePathname());

                    if (is_dir($match->getRealPath())) {
                        $itemZipArchive->addEmptyDir($zipPath);
                    } else {
                        if (isset($item['filters'])) {
                            $filteredContents = $this->filter($match, $item['filters']);
                            $itemZipArchive->addFromString($zipPath, $filteredContents);
                        } else {
                            $itemZipArchive->addFile($match->getRealPath(), $zipPath);
                        }
                    }
                }
            }
        }

        $itemZipArchive->close();
    }

    protected function filter(SplFileInfo $file, array $filters): string
    {
        $contents = $file->getContents();

        foreach ($filters as $filter) {
            $method = 'filter' . ucfirst(is_string($filter) ? $filter : $filter['type']);

            if (method_exists($this, $method)) {
                $contents = $this->$method($file, $contents, $filter);
            }
        }

        return $contents;
    }

    protected function filterRemoveComments(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if ($file->getExtension() == 'php') {
            $result = '';
            $tokens = token_get_all($contents);

            foreach ($tokens as $token) {
                if (is_array($token)) {
                    list($id, $text) = $token;

                    if (in_array($id, [T_COMMENT, T_DOC_COMMENT])) {
                        continue;
                    }/* elseif ($id == T_WHITESPACE) {
                        $text = ' '; // replace multiple spaces, tabs and newlines with a single white space
                    }*/

                    $token = $text;
                }

                $result .= $token;
            }

            $contents = $result;
        } elseif (in_array($file->getExtension(), ['js', 'vue'])) {
            // replace single line and multi-line comments
            $contents = preg_replace('/\/\/[^\r\n]*|\/\*[\s\S]*?\*\//', '', $contents);
        }

        return $contents;
    }

    protected function filterAddCopyright(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if ($file->getExtension() == 'php') {
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

            $author = $this->composer->authors[0] ?? [];

            $contents = str_replace(
                '<?php',
                sprintf(
                    $copyright,
                    $this->composer->description ?? $this->composer->name,
                    str_repeat('-', strlen($this->composer->description ?? $this->composer->name)),
                    $file->getFilename(),
                    $author->name ?? '',
                    $author->name ?? '',
                    $author->email ?? '',
                    $author->homepage ?? '',
                ),
                $contents
            );
        }

        return $contents;
    }

    protected function filterReplace(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if (!isset($filter['search']) || !isset($filter['replace'])) {
            return $contents;
        }

        $search = $filter['search'];
        $replace = $filter['replace'];

        if (preg_match('#EXEC\((.+)\)#', $replace, $m)) {
            $replace = str_replace($m[0], eval($m[1]), $replace);
        }

        return preg_match('/^#.*#[ismU]*$/', $search) // if $search is a regular expression
            ? preg_replace($search, $replace, $contents)
            : str_replace($search, $replace, $contents);
    }

    protected function filterEncodeStringUtf(SplFileInfo $file, string $contents, string|array $filter): string
    {
        $search = $filter['search'] ?? NULL;

        if (is_null($search)) {
            return $contents;
        }

        if (is_string($search)) {
            $search = [$search];
        }

        $search = array_map(fn($s) => '/\'(' . str_replace('/', '\/', $s) . ')\'/', $search);

        return preg_replace_callback($search, fn ($matches) => '"' . $this->encodeStringUtf($matches[1]) . '"', $contents);
    }


    protected function filterExec(SplFileInfo $file, string $contents, string|array $filter): string
    {
        if (!isset($filter['code'])) {
            return $contents;
        }

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

    protected function getTaskType(array $task): string
    {
        return $task['type'] ?? 'zip';
    }

    protected function getTaskItemFolder(string|array $item): array
    {
        $folder = is_array($item) ? $item['folder'] : $item;
        return is_array($folder) ? $folder : [$folder];
    }

    protected function makeFinder(string $searchFolderPath, string|array $item): Finder
    {
        $finder = (new Finder)->in($searchFolderPath)->ignoreDotFiles(FALSE);

        if (is_array($item)) {
            if (isset($item['files']) && $item['files'] === FALSE) {
                $finder->directories();
            } else {
                $finder->files();
            }

            foreach (['name', 'notName', 'notPath', 'depth', 'exclude'] as $method) {
                if (isset($item[$method])) {
                    $finder->$method($item[$method]);
                }
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

    protected function path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    protected function sourcePath(string $relativePath): string
    {
        return trim($this->sourceFolderPath . '/' . $this->path($relativePath), '/');
    }

    protected function targetPath(string $relativePath): string
    {
        return trim($this->targetFolderPath . '/' . $this->path($relativePath), '/');
    }

    protected function printHeader(): void
    {
        render(<<<HTML
          <div class="bg-blue-500 text-blue px-2 uppercase">Release Tool</div>
        HTML
        );
    }

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

    protected function printErrorAndExit(string $message): void
    {
        $this->printError($message);
        exit;
    }

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

    protected function printStatus(string $title, bool $success): void
    {
        $result = $success ? "<span class=\"text-green uppercase font-bold\">Ok</span>" : "<span class=\"text-red uppercase font-bold\">Error</span>";

        render(<<<HTML
          <div>
            <span class="text-gray-200 mr-1">$title</span>
            $result
          </div>
        HTML
        );
    }

    protected function printString(string $string): void
    {
        render(<<<HTML
          <span class="text-gray-200 mr-1">$string</span>
        HTML
        );
    }

    protected function mapWebservers(SimpleXMLElement $xml): array
    {
        $result = [];

        foreach ($xml->component->option->webServer as $server) {
            $name = (string) $server->attributes()->name;

            $result[$name] = (object) [
                'host' => (string) $server->fileTransfer->attributes()->host,
                'port' => (string) $server->fileTransfer->attributes()->port,
                'rootFolder' => (string) $server->fileTransfer->attributes()->rootFolder,
                'accessType' => (string) $server->fileTransfer->attributes()->accessType,
            ];
        }

        return $result;
    }
}
