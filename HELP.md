# Release Tool Help

## Checklist

- Understand how the tool starts and where it reads configuration from
- Learn the task execution model and shared task fields
- Use the CLI arguments to select a config file or run only specific tasks
- See examples for every supported task type
- Know what files, archives, or remote actions each task produces

## Overview

`ReleaseTool` is a PHP-based build and release helper. It reads a JSON configuration file, executes tasks in order, and writes most output into a `release/` folder inside the current project.

The entry script is `make`:

```php
require __DIR__ . '/vendor/autoload.php';
new Release($argv);
```

When you run the tool:

1. It uses the current working directory as the **source folder**.
2. It creates or reuses a `release/` directory in that folder.
3. It loads `release.json` by default.
4. It optionally filters tasks by task ID and can exclude selected task IDs from the CLI.
5. It processes tasks sequentially.
6. It stops immediately if a task fails.

Supported task types:

- `clean`
- `delete`
- `command`
- `mkdir`
- `copy`
- `zip`
- `phar`
- `ssh`
- `pot`

> If `type` is omitted, the task defaults to `zip`.

---

## Requirements

From `composer.json`, the project requires:

- PHP with `ext-simplexml`
- PHP with `ext-zip`
- `symfony/finder`
- `phpseclib/phpseclib`
- `nunomaduro/termwind`

If dependencies are not installed yet:

```powershell
composer install
```

---

## Running the Tool

Run from the project root:

```powershell
php .\make
```

Use a custom config file:

```powershell
php .\make config=release.production.json
```

Run only selected tasks by `id`:

```powershell
php .\make tasks=build-zip,deploy
```

Skip selected tasks by `id`:

```powershell
php .\make skip-tasks=deploy,notify
```

Run only selected tasks and then exclude some of them:

```powershell
php .\make tasks=clean,package,deploy skip-tasks=deploy
```

Use both together:

```powershell
php .\make config=release.production.json tasks=clean,package,deploy
```

### CLI filtering rules

- `tasks=...` acts as an allow-list.
- `skip-tasks=...` acts as a deny-list.
- If the same task ID appears in both lists, it is skipped.
- Tasks without an `id` cannot be selected or skipped by these CLI filters.
- A task with a config-level `skip` field is always skipped.

### Typical console output

The tool prints a header and a few status lines. A typical successful run looks like:

```text
Release Tool
Source folder: D:/Web/MyProject
Target folder: D:/Web/MyProject/release
Number of tasks: 4
Task #1 (clean) OK
Task #2 (copy) OK
Task #3 (zip) OK
Task #4 (ssh) OK
Release completed.
```

A failed task stops the run and prints an error.

---

## Configuration File Structure

The config file is JSON and must contain a `tasks` array.

### Minimal example

```json
{
  "tasks": [
    { "type": "clean" },
    {
      "type": "mkdir",
      "items": ["dist"]
    }
  ]
}
```

### Common task fields

Most tasks support these top-level properties:

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | string | Optional task ID, useful with `tasks=...` and `skip-tasks=...` |
| `type` | string | Task type; if missing, defaults to `zip` |
| `skip` | any | If present, the task is skipped |
| `items` | array | Task-specific input items |

### Task execution rules

- Tasks run in the order defined in `tasks`.
- If `skip` exists, that task is skipped.
- If `tasks=...` is passed on the CLI, only tasks with matching `id` values run.
- If `skip-tasks=...` is passed on the CLI, tasks with matching `id` values are excluded.
- If both CLI filters are used, `skip-tasks` removes tasks from the `tasks` allow-list.
- If a task throws an exception or calls an error exit, processing stops.

---

## Source and Target Paths

The tool uses two important base paths:

- **Source folder** = current working directory
- **Target folder** = `<source>/release`

Most tasks write to the target folder.

### Important exception: `delete`

The `delete` task works on the **source folder**, not the `release/` folder.

---

## Item Syntax and File Matching

Several task types (`delete`, `copy`, `zip`, `phar`, `pot`, and `ssh`) accept `items` that point to files or folders.

### Shorthand item

A string item means “use this folder”.

```json
{
  "type": "copy",
  "items": ["src"]
}
```

### Full item object

```json
{
  "folder": "src",
  "destination": "app",
  "name": "*.php",
  "notName": "*.test.php",
  "notPath": "vendor",
  "depth": "< 3",
  "exclude": ["node_modules", "tests"],
  "files": true,
  "filters": [
    "removeComments",
    {
      "type": "replace",
      "search": "APP_ENV=dev",
      "replace": "APP_ENV=prod"
    }
  ]
}
```

### Supported item fields

| Field | Type | Meaning |
| --- | --- | --- |
| `folder` | string or string[] | Folder to search in |
| `destination` | string | Destination subfolder in the target or archive |
| `name` | string or array | Include filename pattern(s) |
| `notName` | string or array | Exclude filename pattern(s) |
| `notPath` | string or array | Exclude path pattern(s) |
| `depth` | string | Finder depth expression |
| `exclude` | string or array | Exclude directories |
| `files` | boolean | If `false`, search directories instead of files |
| `filters` | array | Content filters; used by `zip` and `phar` |

### Matching behavior

- Dotfiles are included.
- By default, matching targets are **files**.
- Set `"files": false` to match directories instead.
- Finder behavior follows Symfony Finder conventions.

---

## Filters

Filters are applied sequentially to file contents.

> Filters are used by archive-building tasks such as `zip` and `phar`. They are not applied by `copy`.

### 1. `removeComments`

Removes comments from:

- PHP via tokenizer
- JavaScript via regex
- Vue files via regex

Example:

```json
"filters": ["removeComments"]
```

Result:

- `// comment` and `/* ... */` are removed from JS/Vue
- `T_COMMENT` and `T_DOC_COMMENT` are removed from PHP

### 2. `addCopyright`

Adds a generated copyright header:

- for PHP using data from `composer.json`
- for JS using data from `package.json`

Example:

```json
"filters": ["addCopyright"]
```

Expected result:

- PHP files get a docblock-style header replacing the opening `<?php`
- JS files get a banner comment prepended

### 3. `replace`

Performs string replacement or regex replacement.

Example with plain text:

```json
{
  "type": "replace",
  "search": "https://staging.example.com",
  "replace": "https://example.com"
}
```

Example with regex:

```json
{
  "type": "replace",
  "search": "#Version:\\s+[0-9.]+#",
  "replace": "Version: 2.0.0"
}
```

Special behavior:

- If `replace` contains `EXEC(...)`, that PHP code is evaluated and substituted.

Example:

```json
{
  "type": "replace",
  "search": "{BUILD_DATE}",
  "replace": "EXEC(date('Y-m-d'))"
}
```

### 4. `encodeStringUtf`

Finds quoted strings and replaces them with UTF-8 hex escape sequences.

Example:

```json
{
  "type": "encodeStringUtf",
  "search": ["žlutý", "Пример"]
}
```

Possible result:

```text
'žlutý' -> "\xc5\xbe\x6c\x75\x74\xc3\xbd"
```

### 5. `exec`

Runs arbitrary PHP code during filtering.

Example:

```json
{
  "type": "exec",
  "code": "$contents = strtoupper($contents);"
}
```

Expected result:

- The code is evaluated during processing.
- It may modify `$contents` or trigger side effects.

> Use `exec` and `replace` + `EXEC(...)` carefully. They execute PHP code during your build.

---

## Task Reference

## `clean`

Deletes and recreates the `release/` directory.

### Input

```json
{
  "id": "clean",
  "type": "clean"
}
```

### Expected result

- Existing `release/` contents are removed.
- A fresh empty `release/` directory is created.

### Example output

```text
Task #1 (clean) OK
```

---

## `delete`

Deletes matching files or folders from the **source directory**.

### Input

```json
{
  "id": "cleanup-source",
  "type": "delete",
  "items": [
    {
      "folder": "build",
      "name": "*.map"
    },
    {
      "folder": "cache",
      "files": false,
      "name": "tmp*"
    }
  ]
}
```

### Expected result

- All `build/*.map` matches are removed from the source tree.
- Matching directories under `cache` whose names start with `tmp` are removed recursively.

### Example source/result

Before:

```text
build/app.js.map
cache/tmp-123/
```

After:

```text
build/                 (map file removed)
cache/                 (tmp-123 removed)
```

> Be careful: this task does not target `release/` unless your source folder itself contains that path and you point to it explicitly.

---

## `command`

Runs local shell commands on the machine executing the tool.

### Input

```json
{
  "id": "build-assets",
  "type": "command",
  "items": [
    "npm ci",
    "npm run build"
  ]
}
```

### Expected result

- Each command is executed with `shell_exec(...)`.
- Output is not streamed task-by-task by this method.
- The task only treats the command as failed when `shell_exec(...)` returns `false`; non-zero shell exit codes are not checked explicitly.

### Example result

- `node_modules` is installed
- frontend assets are built before packaging tasks begin

---

## `mkdir`

Creates directories inside `release/`.

### Input

```json
{
  "id": "prepare-folders",
  "type": "mkdir",
  "items": [
    "dist",
    "logs",
    "packages/app"
  ]
}
```

### Expected result

Created directories:

```text
release/dist/
release/logs/
release/packages/app/
```

---

## `copy`

Copies matched files and directories from source into `release/`.

### Input

```json
{
  "id": "copy-app",
  "type": "copy",
  "items": [
    {
      "folder": "src",
      "destination": "app",
      "name": "*.php",
      "exclude": ["tests"]
    },
    {
      "folder": "public",
      "destination": "public"
    }
  ]
}
```

### Expected result

Source:

```text
src/Controller/HomeController.php
public/index.php
```

Target:

```text
release/app/Controller/HomeController.php
release/public/index.php
```

### Notes

- If `destination` is omitted, the original folder name is used inside `release/`.
- Directory creation is automatic.
- `copy` does not apply file-content filters.

---

## `zip`

Creates a ZIP archive in `release/`.

### Input

```json
{
  "id": "package-zip",
  "type": "zip",
  "output": "packages/app.zip",
  "root": "my-app",
  "items": [
    {
      "folder": "src",
      "destination": "app",
      "name": "*.php"
    },
    {
      "folder": "public",
      "destination": "public",
      "filters": [
        {
          "type": "replace",
          "search": "APP_ENV=dev",
          "replace": "APP_ENV=prod"
        }
      ]
    }
  ]
}
```

### Expected result

Created file:

```text
release/packages/app.zip
```

Archive contents:

```text
my-app/app/...php
my-app/public/index.php
```

If filters are used:

- matching files are added with transformed content
- non-filtered files are stored directly

### Notes

- `root` prefixes paths inside the ZIP only.
- `output` is relative to `release/`.

---

## `phar`

Creates a PHAR archive in `release/`.

### Input with entry file

```json
{
  "id": "package-phar",
  "type": "phar",
  "output": "packages/tool.phar",
  "root": "app",
  "entry": "bin/console.php",
  "items": [
    {
      "folder": "src",
      "destination": "src",
      "name": "*.php"
    },
    {
      "folder": "bin",
      "destination": "bin"
    }
  ]
}
```

### Expected result

Created file:

```text
release/packages/tool.phar
```

Generated default stub behavior:

- maps the PHAR alias
- requires `phar://tool.phar/app/bin/console.php`
- halts the compiler

### Input without `entry`

```json
{
  "type": "phar",
  "output": "packages/library.phar",
  "items": [
    "src"
  ]
}
```

### Expected result without `entry`

- `release/packages/library.phar` is created
- the default stub maps the alias only
- no entry file is automatically required

### Custom stub example

```json
{
  "type": "phar",
  "output": "packages/custom.phar",
  "stub": "build/stub.php",
  "items": ["src"]
}
```

or inline:

```json
{
  "type": "phar",
  "output": "packages/custom.phar",
  "stub": "<?php Phar::mapPhar('custom.phar'); echo 'hello'; __HALT_COMPILER();",
  "items": ["src"]
}
```

### Notes

- `output` is required.
- If `stub` is provided, it takes precedence over `entry`.
- If `entry` is omitted or empty, no default `require` statement is added.
- The archive signature algorithm is SHA-512.
- PHAR creation requires `phar.readonly=0` at runtime.

---

## `ssh`

Uploads files by SFTP and can execute remote shell commands over SSH.

### Supported fields

| Field | Type | Required | Meaning |
| --- | --- | --- | --- |
| `host` | string | Yes | Remote server hostname or IP |
| `port` | int | No | SSH port, default `22` |
| `username` | string | Yes | SSH/SFTP username |
| `password` | string | Cond. | Password auth, used if no `privateKey` |
| `privateKey` | string | Cond. | Path to private key |
| `passphrase` | string | No | Private key passphrase |
| `path` | string | No | Remote upload target and working directory, default `/` |
| `timeout` | int/float | No | Connection timeout |
| `commandTimeout` | int/float | No | Remote command timeout |
| `commands` | string[] | No | Commands to run remotely |
| `items` | array | Yes | Files/folders to upload |

### Important command behavior

For each command string:

1. the tool first runs `cd {path} && ...`, so the command executes inside the configured remote folder
2. `{path}` is still replaced with the configured remote `path` if you want to reference it explicitly inside the command
3. `{datetime}` is replaced with the current timestamp in `Y-m-d_H-i-s`

### Input example using password auth

```json
{
  "id": "deploy",
  "type": "ssh",
  "host": "example.com",
  "username": "deploy",
  "password": "secret",
  "path": "/var/www/my-app",
  "timeout": 15,
  "commandTimeout": 120,
  "items": [
    {
      "folder": "release-build",
      "destination": "current"
    }
  ],
  "commands": [
    "php artisan migrate --force",
    "php artisan cache:clear",
    "tar -czf {path}/backup-{datetime}.tar.gz {path}/storage"
  ]
}
```

### Expected result

Remote upload result:

```text
/var/www/my-app/current/...
```

Remote command behavior:

```sh
cd /var/www/my-app && php artisan migrate --force
cd /var/www/my-app && php artisan cache:clear
cd /var/www/my-app && tar -czf /var/www/my-app/backup-2026-03-27_14-30-00.tar.gz /var/www/my-app/storage
```

### Example console output

```text
deploy@example.com:/var/www/my-app# cd /var/www/my-app && php artisan migrate --force
Migrating: 2026_03_27_000000_add_index
Migrated:  2026_03_27_000000_add_index
```

### Key-based auth example

```json
{
  "type": "ssh",
  "host": "example.com",
  "port": 22,
  "username": "deploy",
  "privateKey": "keys/deploy_rsa",
  "passphrase": "optional-passphrase",
  "path": "/srv/app",
  "items": [
    {
      "folder": "release",
      "destination": "app"
    }
  ],
  "commands": [
    "composer install --no-dev --optimize-autoloader",
    "php bin/console cache:clear"
  ]
}
```

### Notes

- Remote directories are created automatically.
- File uploads use SFTP.
- Commands run over a separate SSH connection after uploads finish.
- `commands` must be strings.
- Every command is prefixed with `cd <path> &&` automatically.
- If a command times out or writes only stderr, the task fails.

---

## `pot`

Extracts translatable strings from source files and builds:

- a `.pot` file in `release/`
- a `text-strings.php` file in `release/`

### Supported fields

| Field | Type | Meaning |
| --- | --- | --- |
| `domain` | string | Translation domain; defaults to composer/package name or `messages` |
| `output` | string | Output POT filename; defaults to `<domain>.pot` |
| `functions` | string[] | Functions to scan; defaults to `__`, `_e`, `_x`, `_n`, `$t`, `_ex`, `_nx` |
| `items` | array | Files/folders to scan |

### Input

```json
{
  "id": "translations",
  "type": "pot",
  "domain": "my-plugin",
  "output": "languages/my-plugin.pot",
  "functions": ["__", "_e", "_n", "$t"],
  "items": [
    {
      "folder": ["src", "templates"],
      "name": ["*.php", "*.js", "*.vue"],
      "exclude": ["vendor", "node_modules"]
    }
  ]
}
```

### Example scanned source

```php
__('Save changes', 'my-plugin');
_e('Done', 'my-plugin');
_n('%d file', '%d files', $count, 'my-plugin');
```

### Expected result

Generated files:

```text
release/languages/my-plugin.pot
release/text-strings.php
```

Example `.pot` content:

```po
msgid ""
msgstr ""
"Project-Id-Version: financialplugins/release-tool\n"
...

msgid "%d file"
msgstr ""

msgid "%d files"
msgstr ""

msgid "Done"
msgstr ""

msgid "Save changes"
msgstr ""
```

Example `text-strings.php` content:

```php
<?php
    defined('ABSPATH') or die('Direct access is not allowed');
    
    return [
      'Done' => __('Done', 'my-plugin'),
      'Save changes' => __('Save changes', 'my-plugin')
    ];
```

### Notes

- Strings are deduplicated.
- Strings are sorted alphabetically, case-insensitively.
- Plural functions contribute both singular and plural strings.

---

## Full Example `release.json`

This example shows a realistic end-to-end release pipeline.

```json
{
  "tasks": [
    {
      "id": "clean",
      "type": "clean"
    },
    {
      "id": "build-assets",
      "type": "command",
      "items": [
        "npm ci",
        "npm run build"
      ]
    },
    {
      "id": "prepare",
      "type": "mkdir",
      "items": [
        "packages",
        "languages"
      ]
    },
    {
      "id": "copy-runtime",
      "type": "copy",
      "items": [
        {
          "folder": "src",
          "destination": "app/src",
          "name": "*.php",
          "exclude": ["tests"]
        },
        {
          "folder": "public",
          "destination": "app/public"
        }
      ]
    },
    {
      "id": "translations",
      "type": "pot",
      "domain": "my-app",
      "output": "languages/my-app.pot",
      "items": [
        {
          "folder": ["src", "templates"],
          "name": ["*.php", "*.js", "*.vue"],
          "exclude": ["vendor", "node_modules"]
        }
      ]
    },
    {
      "id": "zip-package",
      "type": "zip",
      "output": "packages/my-app.zip",
      "root": "my-app",
      "items": [
        {
          "folder": "release/app",
          "destination": "",
          "name": "*"
        }
      ]
    },
    {
      "id": "phar-package",
      "type": "phar",
      "output": "packages/my-app.phar",
      "entry": "bin/run.php",
      "items": [
        {
          "folder": "src",
          "destination": "src"
        },
        {
          "folder": "bin",
          "destination": "bin"
        }
      ]
    }
  ]
}
```

### Expected high-level result

```text
release/
  app/
    src/...
    public/...
  languages/
    my-app.pot
  packages/
    my-app.zip
    my-app.phar
  text-strings.php
```

---

## Troubleshooting

### `release.json file not found`

Cause:

- you ran the tool from the wrong directory
- or the file is named differently and `config=...` was not supplied

Fix:

```powershell
php .\make config=release.production.json
```

### A task is skipped unexpectedly

Cause:

- the task has `skip` set
- or you ran with `tasks=...` and the task `id` is not in that list
- or you ran with `skip-tasks=...` and the task `id` is in that list

### PHAR creation fails because of `phar.readonly`

The tool attempts to disable `phar.readonly`, but if PHP disallows runtime changes you must disable it in your PHP configuration.

### SSH authentication fails

Check:

- `host`
- `port`
- `username`
- `password` or `privateKey`
- optional `passphrase`

### Remote command fails

Remember that commands are executed as:

```sh
cd {path} && your-command
```

So verify:

- the remote path exists
- the SSH user can access it
- the command is valid on the remote shell

---

## Best Practices

- Start your config with `clean` to avoid stale files in `release/`.
- Give important tasks an `id` so you can rerun them individually.
- Use `copy` to stage files, then `zip`, `phar`, or `ssh` to package/deploy.
- Use `exclude`, `notPath`, and `notName` to avoid packaging temporary files.
- Keep destructive `delete` tasks very specific.
- Prefer a custom `stub` for advanced PHAR bootstrap logic.
- Use `commandTimeout` for longer remote deployment steps.

---

## Quick Reference

| Task | Main purpose | Output/result |
| --- | --- | --- |
| `clean` | Reset `release/` | Empty `release/` folder |
| `delete` | Remove source files/folders | Source tree modified |
| `command` | Run local shell commands | Local build side effects |
| `mkdir` | Create target folders | Directories in `release/` |
| `copy` | Copy files into target | Files in `release/` |
| `zip` | Build ZIP archive | `.zip` in `release/` |
| `phar` | Build PHAR archive | `.phar` in `release/` |
| `ssh` | Upload and deploy remotely | Remote files and commands |
| `pot` | Extract translation strings | `.pot` + `text-strings.php` |

---

## Summary

`ReleaseTool` is a task runner for packaging, transforming, archiving, and deploying PHP-based projects. Put your workflow into `release.json`, run `php .\make`, and let the tool build your release step by step.


