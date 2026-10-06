# Release Tool Help

## Checklist

- Understand how the tool starts and where it reads configuration from
- Learn the task execution model and shared task fields
- Use the CLI arguments to select a config file or run only specific tasks
- See examples for every supported task type
- Know what files, archives, or remote actions each task produces
- Pick a source mode: build from git in Docker (recommended) or package the working tree (legacy)
- Migrate an existing project to the git and Docker mode

## Overview

`ReleaseTool` is a PHP-based build and release helper. It reads a JSON configuration file, executes tasks in order, and writes most output into a `release/` folder inside the current project.

The entry script is `make`:

```php
require __DIR__ . '/vendor/autoload.php';
Release::ensurePharWritable($argv);
new Release($argv);
```

When you run the tool:

1. It uses the current working directory as the **project**. The config file and the `release/` folder live there.
2. It creates or reuses a `release/` directory in the project.
3. It loads `release.json` by default.
4. It decides where the tasks read their **source** from (see below).
5. It optionally filters tasks by task ID and can exclude selected task IDs from the CLI.
6. It processes tasks sequentially.
7. It stops immediately if a task fails.

### Source modes

| Mode | Config | The tasks read from | Use it when |
| --- | --- | --- | --- |
| **Git** (recommended) | `"source": {"type": "git", ...}` | An isolated copy of a committed ref, prepared by build steps that run in Docker containers | The project is developed in Docker, or you want reproducible releases |
| **Working tree** (legacy) | no `source` block, or `"source": {"type": "working-tree"}` | The project folder itself, including uncommitted changes and whatever `vendor/` and `node_modules/` happen to be there | The project has not moved to Docker yet |

```text
git mode

  project/                      ~/.cache/release-tool/stages/project-1a2b3c4d/
    build.json   ── ref ──▶       exported commit + prepare steps (composer, npm, ...)
    release/  ◀──── symlink ───   release  ──▶ project/release
        ▲                              │
        └──── zip / phar / copy ───────┘  tasks run here, artifacts land in project/release/
```

Projects without a `source` block keep working exactly as before. They only get a notice recommending the git mode, see [Legacy working-tree mode](#legacy-working-tree-mode).

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

The tool runs **on the host**, not in a container: it needs your SSH config and agent for the `ssh` task. Everything that must match the project's toolchain (Composer, npm, build scripts) runs in Docker containers through the [`docker` option](#the-docker-option) instead.

On the host:

- PHP with `ext-simplexml` and `ext-zip`
- `git` and `tar` for the git mode (Linux, macOS or WSL; it is not available on Windows)
- Docker, when a config uses the `docker` option

From `composer.json`, the project requires:

- `symfony/finder`
- `phpseclib/phpseclib`
- `nunomaduro/termwind`

If dependencies are not installed yet:

```shell
composer install
```

### Optional: `release-tool` on the PATH

`bin/release-tool` is an entry point you can link, so that you can run the tool from any project without spelling out the path to `make`:

```shell
ln -s ~/projects/release-tool/bin/release-tool ~/.local/bin/release-tool
release-tool config=build.json tasks=deploy
```

`php ~/projects/release-tool/make ...` keeps working and is equivalent.

### PHAR creation

`phar.readonly` cannot be changed while PHP is running, and most installations have it on. When the config contains a `phar` task and it is on, the tool starts itself again in a child PHP with `-d phar.readonly=0`, so no php.ini change and no extra flag are needed. If you prefer to change PHP once, add a CLI-only drop-in (Debian/Ubuntu):

```shell
echo 'phar.readonly = Off' | sudo tee /etc/php/<version>/cli/conf.d/99-release-tool.ini
```

---

## Running the Tool

Run from the project root. The examples use `release-tool` (see [Optional: `release-tool` on the PATH](#optional-release-tool-on-the-path)); on Windows or without the link, use `php path/to/make` instead.

```shell
release-tool
```

Use a custom config file:

```shell
release-tool config=release.production.json
```

Run only selected tasks by `id`:

```shell
release-tool tasks=build-zip,deploy
```

Skip selected tasks by `id`:

```shell
release-tool skip-tasks=deploy,notify
```

Run only selected tasks and then exclude some of them:

```shell
release-tool tasks=clean,package,deploy skip-tasks=deploy
```

Use both together:

```shell
release-tool config=release.production.json tasks=clean,package,deploy
```

### All arguments

| Argument | Meaning |
| --- | --- |
| `config=<file>` | Config file, relative to the project. Default `release.json` |
| `tasks=<id>,<id>` | Run only these task IDs |
| `skip-tasks=<id>,<id>` | Do not run these task IDs |
| `ref=<git ref>` | Git mode only: build this branch, tag or commit instead of the config's `ref` (default `HEAD`) |
| `rebuild=1` | Git mode only: discard the stage and prepare it again, even if it is up to date |

| Environment variable | Meaning |
| --- | --- |
| `RELEASE_TOOL_CACHE_DIR` | Where git stages and the Docker caches are kept. Default `$XDG_CACHE_HOME/release-tool`, or `~/.cache/release-tool` |

### CLI filtering rules

- `tasks=...` acts as an allow-list.
- `skip-tasks=...` acts as a deny-list.
- If the same task ID appears in both lists, it is skipped.
- Tasks without an `id` cannot be selected or skipped by these CLI filters. This includes an ID-less `clean`: it does not run when `tasks=...` is used, so the artifacts of earlier runs stay in `release/`.
- A task with a config-level `skip` field is always skipped.

### Running individual tasks

Give the tasks you want to run on their own an `id`, then select them with `tasks=...`. Examples for a config where `blackjack` zips a game, `payments.phar` builds an archive that the `payments` zip includes, and `deploy` uploads:

```shell
release-tool config=build.json tasks=blackjack            # one zip
release-tool config=build.json tasks=payments.phar,payments
release-tool config=build.json tasks=deploy               # upload what is already in release/
release-tool config=build.json skip-tasks=deploy          # everything except the upload
```

- Artifacts stay in `release/` between runs, so a task can use what an earlier run produced. A task that consumes another task's output (a zip that includes a `.phar`) needs that output to exist: run the producing task in the same command or once beforehand.
- In git mode the source is only staged when a selected task reads it. `tasks=deploy` or a publish config that only uploads `release/...` never builds anything.
- In git mode the stage is reused while the commit is unchanged, so `tasks=blackjack` right after a full build only zips (seconds). After a new commit the stage is rebuilt first.

### Typical console output

The tool prints a header and a few status lines. A typical successful run looks like:

```text
Release Tool
Source: reusing HEAD (4a49d01c55), pass rebuild=1 to build it again    (git mode only)
Source folder: /home/me/.cache/release-tool/stages/MyProject-1a2b3c4d
Target folder: /home/me/projects/MyProject/release
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

- **Source folder**: where tasks read files from. In working-tree mode it is the current working directory (the project). In git mode it is the stage, an exported copy of the commit.
- **Target folder** = `<project>/release`, always in the project, in both modes.

Most tasks write to the target folder. Paths in the config are relative to the source folder, so `release/...` always points at the artifacts: in git mode the stage contains a `release` symlink to `<project>/release`.

### Important exception: `delete`

The `delete` task works on the **source folder**, not the `release/` folder. In git mode that is the disposable stage, so it can no longer touch your working tree.

---

## Building from git in Docker

The recommended mode. The tool exports a committed ref into an isolated **stage**, runs your build steps in containers inside it, and then runs the tasks against the stage. The result depends only on the commit and the images, not on what is lying in your working tree or on the versions of Node and PHP installed on the host.

### Configuration

Add a top-level `source` block:

```json
{
  "source": {
    "type": "git",
    "ref": "HEAD",
    "prepare": [
      {
        "docker": { "image": "my-app-image", "env": { "COMPOSER_CACHE_DIR": "/cache/composer" } },
        "items": ["composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction"]
      },
      {
        "docker": { "image": "node:24-alpine", "env": { "npm_config_cache": "/cache/npm" } },
        "items": ["npm ci", "npm run prod"]
      }
    ]
  },
  "tasks": [
    { "type": "clean" },
    { "id": "app", "zip": "app.zip", "items": ["app", "vendor", "public"] }
  ]
}
```

| Field | Meaning |
| --- | --- |
| `type` | `git`, or `working-tree` for the [legacy mode](#legacy-working-tree-mode). `git` is the default when a `source` block exists |
| `ref` | Branch, tag or commit to build. Default `HEAD`. The `ref=` CLI argument overrides it |
| `prepare` | Steps run when the stage is built. Each step has the shape of a [`command`](#command) task (`items`, optional `docker`, optional `failOnError`) |

`prepare` steps are where dependencies are installed and assets are built. Everything the tasks package that is not committed (`vendor/`, compiled assets, generated files) must be produced by a `prepare` step. Do not put these steps in ordinary `command` tasks: those run on every run, while `prepare` is skipped when the stage is up to date. A `prepare` step stops the run when a command fails.

### What a run does

1. **Resolves the ref** to a commit. When it is the checked-out commit (the default), the run **refuses to start if tracked files have uncommitted changes**, because the artifacts would claim to be that commit. Untracked files only produce a warning, since they are not part of a build. A ref that is not the checked-out commit ignores the working tree, so you can build a tag while you have local edits.
2. **Finds the stage**, a folder under `RELEASE_TOOL_CACHE_DIR` (default `~/.cache/release-tool/stages/<project>-<hash>`). It is outside the project, so your IDE, linters and git never see it.
3. **Reuses or rebuilds it.** The stage is reused when the commit and the `prepare` steps are the same as last time and the previous preparation finished. Otherwise it is deleted, the commit is exported with `git archive`, and the `prepare` steps run. Pass `rebuild=1` to force this, for example after a base image changed (the images themselves are not part of the comparison).
4. **Links `release/`** inside the stage to `<project>/release`, `chdir`s into the stage and runs the tasks. Artifacts end up in the project and survive across runs and stage rebuilds.

Staging only happens when a selected task reads sources (`copy`, `zip`, `phar`, `pot`, `delete`, `command`, or an `ssh` task that uploads something outside `release/`). A run of `tasks=deploy` does not build anything.

The config file is always read from the project, not the stage. With a clean working tree it is the committed one. When you build another ref with `ref=`, the current config is still the one used.

### Limitations

- Needs `git`, `tar` and symlinks: Linux, macOS or WSL. On Windows the tool stops with a message; use `"type": "working-tree"` there.
- `git archive` applies `export-ignore` from `.gitattributes`, and does not include submodules or Git LFS contents.
- Only one run per project at a time can use a stage; a second run fails with a message instead of corrupting it.
- If a container ran as root (`"user": "root"`) and created files in the stage, the host user cannot delete the stage later. Keep the default user, or remove the stage folder with `sudo`.

---

## The `docker` option

A `command` task, or a `prepare` step, can run its commands inside a container instead of on the host:

```json
{
  "id": "assets",
  "type": "command",
  "docker": { "image": "node:24-alpine", "env": { "npm_config_cache": "/cache/npm" } },
  "items": ["npm ci", "npm run prod"]
}
```

Each item is run as `docker run --rm --init ...`. By default the container:

- mounts the source folder at `/work` and starts there;
- runs as the host user (uid:gid), so files it writes into the source are owned by you, never by root;
- gets `HOME=/tmp`, because an arbitrary uid has no home directory and npm and Composer need a writable one;
- mounts a host cache folder at `/cache`, shared between runs and projects (`<RELEASE_TOOL_CACHE_DIR>/cache`), so point package manager caches there: `COMPOSER_CACHE_DIR=/cache/composer`, `npm_config_cache=/cache/npm`;
- has the image's entrypoint replaced by a shell, so an image that starts services in its entrypoint (an application image) just runs your command.

| Field | Default | Meaning |
| --- | --- | --- |
| `image` | required | Image to run. If it is not available locally the tool tries to pull it |
| `env` | `{}` | Extra environment variables |
| `volumes` | `{}` | Extra mounts, host path to container path |
| `workdir` | `/work` | Mount point and working directory of the source |
| `user` | host uid:gid | Run as another user. `"root"` runs as the image's root |
| `shell` | `sh` | Shell used to run the item (`sh -c "<item>"`) |
| `network` | Docker default | Passed to `--network` |

**Which image to use.** For PHP, prefer the project's own application image (the one its `compose.yml` builds): it has the PHP version and extensions production needs, so Composer resolves for the right platform. Build it first, for example `docker compose build`. For Node use a public image such as `node:24-alpine`. The tool checks Docker and the image before running and explains what is missing.

**Exit codes.** Commands that run in Docker always stop the run when they fail.

---

## Legacy working-tree mode

This is how the tool always worked, and it is still fully supported for projects that have not moved to Docker:

- Tasks read from the project folder, so **uncommitted changes are packaged**, which is handy for a quick demo build.
- `vendor/`, `node_modules/` and every other folder are packaged as they are on the host. Make sure they are up to date: nothing refreshes them for you.
- `command` tasks run on the host.
- `phar.readonly` is handled automatically as described under [PHAR creation](#phar-creation).

When the config has no `source` block and a selected task reads sources, the tool prints a notice recommending the git mode. To keep working in the old way and hide the notice, declare it:

```json
{ "source": { "type": "working-tree" }, "tasks": [ ... ] }
```

Two details differ from older versions: the output of `command` tasks is now shown, and a failing host command now prints a warning (it still does not stop the run unless you set `"failOnError": true`, see [`command`](#command)).

---

## Migrating a project to git and Docker

1. Make sure the project builds in containers: the application image builds and the frontend builds with its Node image.
2. Add the `source` block with `"type": "git"`.
3. Move the host `composer`, `npm` and script `command` tasks into `prepare` steps with a `docker` image, and delete them from `tasks`.
4. Install **production** dependencies only (`composer install --no-dev`), and move to `require` any package that production code uses but that was in `require-dev` (a Faker-based console command is a typical case).
5. Check that every gitignored path your tasks package is produced by a `prepare` step. Anything that used to exist only in an old working tree will now be missing. The zip file listings show which.
6. Remove `delete` tasks that cleaned the working tree before packaging; the stage is clean by definition.
7. Commit, run once with `skip-tasks=<upload task>`, and compare the file lists of the new archives with the previous release's.
8. Run individual tasks to confirm the stage is reused (`tasks=<id>` should finish quickly and print `Source: reusing ...`).

Projects can migrate one by one; the two modes do not interfere with each other.

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
  "targetName": "bootstrap.php",
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
| `targetName` | string | For `copy` and `zip` tasks: rename each matched file to this final filename while keeping its relative parent folders |
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

Runs shell commands on the host, or inside a container with the [`docker` option](#the-docker-option).

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

With Docker:

```json
{
  "id": "build-assets",
  "type": "command",
  "docker": { "image": "node:24-alpine" },
  "items": ["npm ci", "npm run build"]
}
```

### Fields

| Field | Meaning |
| --- | --- |
| `items` | Commands, run in order, each with `sh -c` in the source folder |
| `docker` | Run the commands in a container, see [The `docker` option](#the-docker-option) |
| `failOnError` | `true` stops the run when a command exits with a non-zero code, `false` prints a warning and continues |

### Expected result

- Each command runs in the source folder and its output is shown as it is produced.
- The default for `failOnError` is `true` for commands that run in Docker and for any command when the source is built from git. It is `false` for host commands in [working-tree mode](#legacy-working-tree-mode), where they have always been fire-and-forget; a non-zero exit prints a warning there.

### Example result

- `node_modules` is installed
- frontend assets are built before packaging tasks begin

> In git mode, put dependency installs and builds in `source.prepare` rather than in a `command` task: `prepare` is skipped while the stage is up to date, a `command` task runs every time.

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

### Rename a copied file

Input:

```json
{
  "id": "copy-env-template",
  "type": "copy",
  "items": [
    {
      "folder": ".",
      "name": ".env.install",
      "destination": "",
      "targetName": ".env"
    }
  ]
}
```

Expected result:

```text
release/.env
```

### Notes

- If `destination` is omitted, the original folder name is used inside `release/`.
- Directory creation is automatic.
- `copy` does not apply file-content filters.
- `targetName` changes only the final file name; it does not change destination folders.
- `targetName` is valid for files only and must not contain `/` or `\`.

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

### Rename a file inside the ZIP

Input:

```json
{
  "id": "package-env-template",
  "type": "zip",
  "output": "packages/env.zip",
  "items": [
    {
      "folder": "",
      "name": ".env.install",
      "targetName": ".env"
    }
  ]
}
```

Expected archive contents:

```text
.env
```

### Notes

- `root` prefixes paths inside the ZIP only.
- `output` is relative to `release/`.
- `targetName` changes only the final file name inside the archive; it does not change destination folders.
- `targetName` is valid for files only and must not contain `/` or `\`.

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
| `sshConfig` | string | Cond. | `Host` entry name from `~/.ssh/config` |
| `sshConfigFile` | string | No | SSH config file path, default `~/.ssh/config` |
| `host` | string | Cond. | Remote server hostname or IP, or override for `sshConfig` |
| `port` | int | No | SSH port, default `22`, or value from `sshConfig` |
| `username` | string | Cond. | SSH/SFTP username, or value from `sshConfig` |
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

### SSH config and agent auth example

If `~/.ssh/config` contains:

```sshconfig
Host production
  HostName example.com
  User deploy
  Port 22
  IdentityFile ~/.ssh/production_ed25519
```

and the matching key is loaded in your SSH agent, the task can use the named config:

```json
{
  "type": "ssh",
  "sshConfig": "production",
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

On Windows, when `SSH_AUTH_SOCK` is not set, the tool uses the built-in OpenSSH agent pipe:

```text
\\.\pipe\openssh-ssh-agent
```

Explicit task values override the SSH config values, so you can set `host`, `port`, `username`, or `privateKey` in the task when needed. Use `sshConfigFile` to read a non-default config file.

### Notes

- Remote directories are created automatically.
- File uploads use SFTP.
- Commands run over a separate SSH connection after uploads finish.
- `commands` must be strings.
- Every command is prefixed with `cd <path> &&` automatically.
- `sshConfig` reads `HostName`, `User`, `Port`, `IdentityFile`, and `IdentityAgent` from OpenSSH config.
- Agent auth is used automatically when `sshConfig` is supplied.
- If `IdentityFile` is set, the matching key must be loaded in ssh-agent.
- If a command exits with a non-zero status or times out, the task fails and the run stops. The command's stdout and stderr are printed first.
- If the server does not report an exit status, the tool falls back to the old rule: a command that writes only stderr fails.
- A failed run exits the tool with status `1`.

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

PHP does not allow turning `phar.readonly` off while it is running, so the tool starts itself again with `-d phar.readonly=0` when the config has a `phar` task (see [PHAR creation](#phar-creation)). If you still get this error, the config may be read from a different location than expected, or something prevents starting a child PHP process (`passthru` disabled). Run `php -d phar.readonly=0 make ...` yourself, or set `phar.readonly = Off` in the CLI php.ini.

### Uncommitted changes in tracked files

Git mode only builds committed code. Commit or stash the changes, or build another commit that is not checked out with `ref=<ref>`. To package uncommitted work on purpose, use [working-tree mode](#legacy-working-tree-mode).

### Docker is not available / image not found

Check that `docker ps` works for your user. If the image is a project image, build it first (for example `docker compose build`); public images are pulled automatically.

### The stage looks stale

The stage is rebuilt when the commit or the `prepare` steps change. It is not rebuilt when only something outside git changed, such as a rebuilt base image or a package published to a registry. Run with `rebuild=1`, or delete the stage folder under `~/.cache/release-tool/stages/`.

### Another release run is using the same stage

A second run for the same project started while the first one is still working. Wait for it. The lock is released automatically when a run ends or is killed.

### Building from git on Windows

Not supported: it needs `git`, `tar` and symlinks. Use `"type": "working-tree"`, or run the tool from WSL.

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


