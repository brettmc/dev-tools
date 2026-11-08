# Development tools for OpenTelemetry PHP

![CI Build](https://github.com/opentelemetry-php/dev-tools/workflows/PHP%20QA/badge.svg)

`dev-tools` is the command line tool the OpenTelemetry PHP maintainers use to check and release the
packages in the two monorepos,
[opentelemetry-php](https://github.com/open-telemetry/opentelemetry-php) (core) and
[opentelemetry-php-contrib](https://github.com/open-telemetry/opentelemetry-php-contrib).

Each monorepo is split by [gitsplit](https://github.com/jderusse/gitsplit) into read-only package
repositories under [opentelemetry-php](https://github.com/opentelemetry-php), one per entry in its
`.gitsplit.yml`. Packages are released by tagging those split repositories. `dev-tools` works out
which packages have changed, what their next versions should be, and the order to tag them in. Then
it tags them, with release notes taken from each package's `CHANGELOG.md`.

It also checks changelogs and service provider dependencies in pull requests, keeps composer branch
aliases up to date, and prepares releases of the
[opentelemetry extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation) for
PECL.

## Contents

- [Commands](#commands)
- [Run the tool](#run-the-tool)
  - [Create a GitHub token](#create-a-github-token)
- [Release a monorepo, step by step](#release-a-monorepo-step-by-step)
- [Write changelog entries](#write-changelog-entries)
- [Check changelogs in a pull request](#check-changelogs-in-a-pull-request)
- [Prepare a release](#prepare-a-release)
  - [Start from an up-to-date checkout](#start-from-an-up-to-date-checkout)
  - [What release:prep changes](#what-releaseprep-changes)
  - [Tag order and constraint warnings](#tag-order-and-constraint-warnings)
  - [Packages versioned from a file](#packages-versioned-from-a-file)
  - [Read the suggestions as JSON](#read-the-suggestions-as-json)
  - [Render a release dashboard issue](#render-a-release-dashboard-issue)
- [Tag a release](#tag-a-release)
  - [Prepare the checkout](#prepare-the-checkout)
  - [How the split commit is found](#how-the-split-commit-is-found)
  - [Release interactively](#release-interactively)
  - [Release without asking, as in CI](#release-without-asking-as-in-ci)
  - [Read the result as JSON](#read-the-result-as-json)
- [Update branch aliases](#update-branch-aliases)
- [Check SPI package dependencies](#check-spi-package-dependencies)
- [Validate each package's composer.json](#validate-each-packages-composerjson)
- [List the latest releases](#list-the-latest-releases)
- [Release the PECL extension](#release-the-pecl-extension)
- [Develop dev-tools](#develop-dev-tools)

## Commands

| Command | What it does | GitHub token |
|---|---|---|
| `changelog:check` | Checks that changed packages have a changelog entry, and that changed changelogs parse | Not used |
| `release:prep` | Suggests each package's next version and prepares the changelogs, branch aliases and `replace` for tagging | Optional |
| `release:run` | Tags and releases the split repositories | Required |
| `update:branch-alias` | Moves each package's `dev-main` alias to its latest release | Optional |
| `packages:spi:check` | Checks `#[PackageDependency]` constraints against the monorepo | Not used |
| `packages:composer:validate` | Runs `composer validate` on each package | Not used |
| `release:list` | Lists the latest release of each package | Optional |
| `release:pecl` | Prints an updated `package.xml` for a PECL release of the extension | Not used |
| `tag:pecl` | Tags a release of the extension | Required |

A token is optional where GitHub is only read. It raises the API rate limit.

Run `otel help <command>` for a command's options.

## Run the tool

`dev-tools` is a dev dependency of both monorepos, so from a monorepo checkout with `composer install`
run, use `vendor/bin/otel`:

```shell
cd opentelemetry-php
vendor/bin/otel release:prep --dry-run
```

Or run it from a `dev-tools` checkout, and point `--path` at the monorepo:

```shell
bin/otel release:prep --path=../opentelemetry-php --dry-run
```

`--path` defaults to the working directory, except for `release:run`, which requires it. It needs PHP
8.2 or later.

### Create a GitHub token

`release:run` and `tag:pecl` need a token that can write to the repositories they tag. You need to be
an administrator or owner of [opentelemetry-php](https://github.com/opentelemetry-php) to create
releases. A lower-privileged token can do everything else, but the release itself fails.

[Create a fine-grained token](https://github.com/settings/personal-access-tokens/new):

| For | Resource owner | Repository access | Permissions |
|---|---|---|---|
| The split packages (API, SDK, contrib) | `opentelemetry-php` | All repositories | Contents: read and write |
| The extension | `open-telemetry` | Only `open-telemetry/opentelemetry-php-instrumentation` | Contents: read and write |

Pass it in `GITHUB_TOKEN` (preferred) or with `--token`:

```shell
export GITHUB_TOKEN=<fine-grained-token>
```

## Release a monorepo, step by step

1. Each pull request that changes a package adds an entry under `## Unreleased` in its
   `CHANGELOG.md`. [changelog:check](#check-changelogs-in-a-pull-request) enforces this in CI.
2. Run [release:prep](#prepare-a-release) on an up-to-date checkout. It dates each changelog section
   and moves the branch aliases and the root `replace` to the new versions.
3. Review the changes with `git diff`, open a pull request, and merge it.
4. Pull, then run [release:run](#tag-a-release) on that checkout. It tags each changed package as the
   version in its changelog, in the order their dependencies need.
5. If `release:run` says branch aliases are out of date, run
   [update:branch-alias](#update-branch-aliases) and open a pull request. After a prepared release
   there is usually nothing to update.

## Write changelog entries

Each package keeps its pending changes under `## Unreleased`, in
[Keep a Changelog](https://keepachangelog.com) sections. The sections decide the version
`release:prep` suggests:

| Unreleased contains | Suggested version |
|---|---|
| Nothing | No release |
| Only `Fixed` or `Security` | Patch |
| `Added`, `Changed` or `Deprecated` | Minor |
| `Breaking changes` or `Removed` | Minor, and the entries are listed as warnings |

A major is never suggested. Some breaking changes ship in 1.x minors, so the maintainer decides.

Each entry is a `- ` bullet under one of the `###` sections above. An entry may wrap onto indented
lines, and hold nested `- ` bullets and fenced code blocks:

````markdown
## Unreleased

### Added

- A `timeout` option for the OTLP exporter, which
  defaults to 10 seconds:
  ```php
  $exporter = new SpanExporter($transport, timeout: 5);
  ```

### Fixed

- Spans are no longer dropped at shutdown.
````

Link definitions such as `[#12]: https://...` may go anywhere. A release's notes include the ones
its entries use.

Anything else under Unreleased is an error naming its line, rather than being left out of the
release: an unknown heading, a `*` bullet, an entry before the first section, a paragraph, a code
block that is not closed, or a second `## Unreleased`.

## Check changelogs in a pull request

`changelog:check` checks that each package a pull request changes has an entry in its `CHANGELOG.md`.
Then it parses each changed `CHANGELOG.md` with the [rules above](#write-changelog-entries), and fails
on an error, naming the file and line. It reads only the checkout.

For each package with changed files it prints one status:

| Status | Fails |
|---|---|
| `changed, CHANGELOG.md updated` | No |
| `changed, CHANGELOG.md not updated` | Yes |
| `changed, has no CHANGELOG.md` | No |
| `CHANGELOG.md deleted` | Yes |
| `CHANGELOG.md updated` | No |
| `Markdown only, no entry needed` | No |

Under GitHub Actions (`GITHUB_ACTIONS` set), each failure is also printed as an `::error` workflow
command, which marks the file in the pull request.

The arguments are revisions for `git diff`. A pull request workflow checks out its merge commit with
`fetch-depth: 2`, and compares it with its first parent, the base branch:

```shell
vendor/bin/otel changelog:check HEAD^1 HEAD
```

Changelogs are read as they are in the last revision, so the result doesn't depend on what is checked
out. `A..B` and `A...B` are read at `B`. One revision is compared with the working tree, as `git diff`
does.

Without arguments it checks everything since the merge base with `origin/main`, including uncommitted
and untracked files, and parses every changelog. Run that before pushing:

```shell
vendor/bin/otel changelog:check
```

A change that doesn't affect users needs no entry. A maintainer can skip the check by adding the
`skip-changelog` label to the pull request, which the workflow tests in its `if:`.

| Option | Meaning |
|---|---|
| `--path` | Monorepo checkout (default: working directory) |

## Prepare a release

`release:prep` picks the next version of each package from its `CHANGELOG.md`, and prepares the
checkout for tagging. Run it before `release:run`, so the tag carries the dated changelog.

```shell
vendor/bin/otel release:prep --dry-run
vendor/bin/otel release:prep --set=open-telemetry/sdk=2.0.0
```

| Option | Meaning |
|---|---|
| `--path` | Monorepo checkout (default: working directory) |
| `--branch` | Upstream branch the checkout must include (default: `main`) |
| `--token` | GitHub token (default: `GITHUB_TOKEN`, optional) |
| `--filter` | Only packages whose prefix matches |
| `--dry-run` | Report the suggestions without writing anything |
| `--date` | Release date as `YYYY-MM-DD` (default: today) |
| `--set=<package>=<version\|skip>` | Release a package as this version, or not at all, without prompting. Repeatable. The package is the composer name or the split repository name. |
| `--format` | `text` (default), `markdown` for a pull request description, `json`, or `dashboard` for an issue body |
| `-n`, `--no-interaction` | Accept every suggestion without prompting |

For each package with a `CHANGELOG.md`, it prints the latest release, the suggestion and the reason,
and lists any breaking entries. Then it asks for the version: blank accepts the suggestion, `skip`
skips, and a typed version overrides it. A forced major warns when Unreleased records no breaking
changes.

A package with changes gets no suggestion when its latest tag is a pre-release or can't be parsed.
These are listed after the table. Choose a version at the prompt or with `--set`.

The checkout needs `composer install` even for `--dry-run`, as the `#[PackageDependency]` attributes
are read through its autoloader. Only the latest-release lookups go to GitHub.

### Start from an up-to-date checkout

A checkout behind the upstream branch would date each release without the changelog entries it is
missing. Those entries would stay under the new `## Unreleased`, and the tag would not carry them. So
before reading anything, `release:prep` looks up the tip of `--branch` on GitHub and checks that the
checkout's `HEAD` includes it. Commits on top of the branch and uncommitted changes are fine, since
they are the prep. `--dry-run` warns instead of failing.

The tip is looked up on GitHub rather than through a local remote, because `origin` may be a fork.
When the check fails, fetch the branch from the upstream repository and rebase onto it:

```shell
git fetch https://github.com/open-telemetry/opentelemetry-php main
git rebase FETCH_HEAD
```

### What release:prep changes

For each package released, it:

- renames `## Unreleased` to `## X.Y.Z - YYYY-MM-DD`, and adds an empty `## Unreleased` above it
- moves its `dev-main` branch alias to the new version's line, such as `1.10.x-dev` to `1.11.x-dev`,
  unless the alias already covers it (`1.x-dev` covers 1.11.0)

The version is a decision, so an alias ahead of it is lowered rather than kept: sdk-configuration at
`1.0.x-dev`, released as 0.10.0, becomes `0.10.x-dev`. A lowered alias is printed as a warning.

Then it sets the root `composer.json` `replace` entry of every split package to that package's alias,
whether or not it is released this round. Inside the monorepo, composer resolves a split package to
its `replace` entry, so that is the version a `#[PackageDependency]` is checked against at runtime.
Entries with no split are left alone. Only the values are rewritten, so the rest of each file is
untouched.

A skipped package keeps its changelog and alias, and is reported as "skipped by --set".
`--dry-run` reports every change without writing it.

### Tag order and constraint warnings

Packages in a monorepo depend on each other's new versions. Once the versions are chosen,
`release:prep` checks the constraints between the packages being released: `require` and `conflict`
in each `composer.json`, and the `#[PackageDependency]` attributes on the service providers each
registers.

- When a package needs another's new version (sdk `^1.16` needs api `1.11`, say), it prints the
  order to tag them in.
- When a `require` or `#[PackageDependency]` excludes another package's new version, or a `conflict`
  matches it, it names the package. This is how a forced major shows up: the dependents that need
  their constraint widened.

Packages outside the run, such as the contrib packages that require the SDK, are not checked.

### Packages versioned from a file

Two generated packages take their version from a file the generator writes, not from their
changelog sections. Each is released only when that version is newer than its latest tag.

- `open-telemetry/sem-conv` takes the semantic conventions version from `src/SemConv/VERSION`.
- `open-telemetry/gen-otlp-protobuf` mirrors `proto/otel/VERSION` exactly, patch included, so proto
  `v1.11.1` is package `1.11.1`.

### Read the suggestions as JSON

With `--format=json`, stdout holds one JSON object and nothing else. The table, prompts, messages and
errors go to stderr. The object is printed however the prep ends.

```json
{
    "packages": [
        {"package": "open-telemetry/api", "repository": "opentelemetry-php/api", "latest": "1.10.1", "version": "1.11.0", "reason": "new features", "warnings": [], "undecided": false, "changes": {"Added": ["A new thing ([#12])."]}},
        {"package": "open-telemetry/sdk", "repository": "opentelemetry-php/sdk", "latest": "1.15.0", "version": "1.16.0", "reason": "breaking changes recorded", "warnings": ["Dropped X."], "undecided": false, "changes": {"Breaking changes": ["Dropped X."], "Fixed": ["A bug."]}},
        {"package": "open-telemetry/context", "repository": "opentelemetry-php/context", "latest": "1.4.0", "version": null, "reason": "no unreleased changes", "warnings": [], "undecided": false, "changes": {}}
    ],
    "order": ["open-telemetry/api", "open-telemetry/sdk"],
    "constraint_warnings": [],
    "release": true,
    "completed": true
}
```

- `version` is null for a package that is not released.
- `undecided` is true for a package with changes that got no suggestion and no version. Without
  interaction it is not released, and the run still succeeds, so check for it before tagging.
- `changes` holds the Unreleased entries by section, breaking changes first, as written in the
  changelog. It is empty for a package with no entries or no `CHANGELOG.md`.
- `release` is true when anything is to be released.
- `completed` is false, and the exit status non-zero, when the prep failed. The reason is on stderr.
  `packages` and `order` are empty if it failed before the packages were assessed.

```shell
vendor/bin/otel release:prep -n --dry-run --format=json | jq '[.packages[].warnings[]] | length'
```

### Render a release dashboard issue

A pinned issue can show what the next release would hold, kept current by a workflow on each push to
the branch. `--format=dashboard` prints its body in markdown. It needs `--dry-run`, as it reports what
would be released rather than preparing it.

The body has:

- a table of the packages with changes: latest release, next version and a count of entries per section
- the tag order, and the packages with no changes or no `CHANGELOG.md`
- each package warning, such as a breaking entry, cut to its first sentence
- the constraint warnings, and the packages with no suggestion
- each package's entries, collapsed under `<details>`

References such as `[#12]` are made inline links, since the definitions at the foot of the changelog
don't come with the entries. A pull request or issue of the monorepo becomes a bare `#12`, which
GitHub links itself in that repository. If the body would be over the 65,536 characters an issue
holds, the entries are left out.

stdout holds the body and nothing else, and nothing at all when the prep fails. So a workflow can
replace the issue body only when the command succeeds, and never with a partial one.

```shell
vendor/bin/otel release:prep -n --dry-run --format=dashboard > body.md
gh issue edit "$ISSUE" --body-file body.md
```

## Tag a release

`release:run` tags each package that has changed since its latest release, and creates a GitHub
release with notes from its `CHANGELOG.md`. Merge the `release:prep` pull request first.

```shell
bin/otel release:run --path=../opentelemetry-php --dry-run
```

| Option | Meaning |
|---|---|
| `--path` | Monorepo checkout at the commit being released, with `composer install` run. Required. |
| `--token` | GitHub token (default: `GITHUB_TOKEN`). Required. |
| `--branch` | Branch to tag from (default: `main`) |
| `--sha` | Monorepo commit to release, which must be on `--branch` (default: the tip of `--branch`) |
| `--repo` | `core` or `contrib` (default: the one `--path` is a checkout of) |
| `--filter` | Only packages whose prefix matches |
| `--dry-run` | Make no changes |
| `--format` | `text` (default) or `json` |
| `-n`, `--no-interaction` | Release without asking |
| `-v`, `-vv`, `-vvv` | More output |

Each package's version is the newest dated section of its `CHANGELOG.md`, when that is newer than its
latest release. The release notes are the body of that section, followed by a compare link. A package
with changes must have a `CHANGELOG.md` with a section for the version released. Otherwise the run
stops, or warns with `--dry-run`.

Packages that need each other's new versions are tagged in the order `release:prep` prints, and the
order is printed. When a release makes a branch alias stale, the run ends with a reminder to run
[update:branch-alias](#update-branch-aliases).

### Prepare the checkout

The packages, their commits, their changelogs and their tag order are all read from the checkout.
The run stops before any GitHub call when `--path`:

- is missing, is not a directory, or is not a checkout of the repo being released
- is a shallow clone, as each package's commits are read back to its latest release
- has uncommitted changes to tracked files
- has untracked files in a package's directory, such as a new `CHANGELOG.md`, which would be read as
  if they were in the release
- has no `vendor/`

The checkout's `HEAD` must be the commit being released:

- Without `--sha`, that is the tip of `--branch` when the run starts, so pull first.
- With `--sha`, the commit must be on `--branch` on GitHub, and the checkout must have the tip of
  `--branch`, so fetch first.

With `--dry-run` these checks warn instead, except for a shallow clone. `actions/checkout` makes a
shallow clone unless told not to:

```yaml
- uses: actions/checkout@v5
  with:
    fetch-depth: 0
```

### How the split commit is found

gitsplit rewrites every commit it pushes to a split repository, so the split commit to tag is found by
content. For each package with changes, the run reads the tree of the package's directory in the
commit being released. Then it walks the split's `--branch` history, back to the latest release, for
the commit with the same tree. That commit is tagged.

The same tree means the split's `CHANGELOG.md` is the checkout's. The run also checks that the
split's commits since its latest release match the monorepo's, so nothing is released that was pushed
to the split directly.

Nothing reads the moving branch tip after the run starts. A merge during the run is neither tagged
nor a reason to stop.

gitsplit updates the split repositories some time after a merge. If no split commit matches yet, the
run stops: wait for gitsplit and run it again. A rerun with the same `--sha` skips the packages
already tagged, as their latest release is then their changelog version. With `--dry-run`, it warns
instead.

### Release interactively

For each package with changes, the run lists the changes and the latest release, then asks:

- the version. Blank accepts the version in its `CHANGELOG.md`, and `skip` skips the package.
- whether the release should be marked latest
- whether it should be a draft, so you can edit the notes before publishing

A failed release request stops the run, as later packages may need that one.

### Release without asking, as in CI

With `--no-interaction`, nothing is asked and nothing unexpected is let through:

- Each package with changes is released as its newest dated changelog version, as the latest release
  and not as a draft.
- A package with changes but no dated section newer than its latest release is skipped, and named,
  before its split is checked.
- A package with changes but no `CHANGELOG.md` stops the run before anything is tagged.
- Commits in a split that are not upstream stop the run before anything is tagged, as does a failed
  compare with the split.
- A `--branch` other than `main` is refused before any GitHub call. A backport must not take the
  latest badge, and its versions are checked against each split's latest release, which is main's.
  Run it interactively.

### Read the result as JSON

With `--format=json`, stdout holds one JSON object and nothing else. Progress, prompts, messages and
errors go to stderr. The object is printed however the run ends, so a workflow can always parse it.

```json
{
    "dry_run": false,
    "sha": "4f1c2e9",
    "releases": [
        {"repository": "opentelemetry-php/api", "version": "1.11.0", "target": "9b0d7a1", "latest": true, "draft": false, "url": "https://github.com/opentelemetry-php/api/releases/tag/1.11.0"}
    ],
    "skipped": [
        {"repository": "opentelemetry-php/context", "reason": "no CHANGELOG.md section newer than 1.4.0"}
    ],
    "completed": true
}
```

- `releases` are in the order they were made. With `--dry-run` they are the releases that would be
  made, and `url` is null.
- `target` is the split commit tagged.
- `skipped` holds the packages with changes that were not released, and why.
- `completed` is false, and the exit status non-zero, when the run failed, a failed request included.
  `releases` then holds those made before it. The reason is on stderr.
- `sha` is null when the run failed before choosing the commit, on the checkout say.

```shell
bin/otel release:run -n --path=../opentelemetry-php --sha="$SHA" --format=json | jq -r '.releases[].url'
```

## Update branch aliases

Each package declares a [composer branch alias](https://getcomposer.org/doc/articles/aliases.md) in
its `composer.json`, which tells composer what version line the development branch belongs to:

```json
{
    "extra": {
        "branch-alias": {
            "dev-main": "1.10.x-dev"
        }
    }
}
```

Composer only applies a branch alias to a dev branch. A tag takes its version from the tag, and
ignores the alias committed in it. So aliases go stale after tagging, and are updated afterwards.
`release:prep` sets the aliases of the packages it prepares, so after a prepared release there is
usually nothing left to update.

`update:branch-alias` updates the packages in the checkout's own `.gitsplit.yml`, so it only touches
the monorepo you run it in:

```shell
cd opentelemetry-php
vendor/bin/otel update:branch-alias --dry-run
```

| Option | Meaning |
|---|---|
| `--path` | Monorepo checkout (default: working directory) |
| `--token` | GitHub token (default: `GITHUB_TOKEN`, optional) |
| `--branch` | Branch the alias applies to (default: `main`, the `dev-main` alias) |
| `--filter` | Only packages whose prefix matches |
| `--dry-run` | Report what would change, without writing anything |

For each package it finds the latest release of the split repository and works out the alias for it,
such as `1.10.x-dev` for `1.10.0`. Then it rewrites that one value in the package's `composer.json`,
leaving the rest of the file untouched.

These are reported but never changed:

- a package with no `branch-alias`, as most contrib packages are
- a package whose alias is ahead of its releases, such as `1.0.x-dev` with a latest release of
  `0.0.5`. That alias is aspirational rather than stale, and lowering it would break constraints that
  resolve against the branch.
- a package whose alias cannot be safely found in the raw file

Review the changes with `git diff`, and open a pull request against the monorepo.

## Check SPI package dependencies

[tbachert/spi](https://github.com/Nevay/spi) only loads a service provider when the packages named in
its `#[PackageDependency]` attributes are installed at a matching version. Its
`composer spi:show-outdated-package-dependencies` checks each constraint against the latest release on
Packagist. In a monorepo that fails during a release: a provider that needs a split package's next
version cannot say so until that version is tagged.

`packages:spi:check` replaces it in a monorepo. For each `#[PackageDependency]` on a split package, it
checks the constraint against the package's `dev-main` alias, which `release:prep` sets to the version
about to be released. It also checks the root `replace` entry that satisfies it at runtime. A
constraint the latest release doesn't meet is fine, as long as the alias does. Dependencies on other
packages are passed to `composer spi:show-outdated-package-dependencies`, with the split packages
ignored.

```shell
cd opentelemetry-php
vendor/bin/otel packages:spi:check
```

| Option | Meaning |
|---|---|
| `--path` | Monorepo checkout (default: working directory) |

The checkout needs `composer install`, as the attributes are read by a script run under its own PHP
and autoloader. It prints a row per attribute. It exits non-zero when:

- an alias or `replace` entry does not satisfy a constraint
- a provider registered in `extra.spi` does not load
- the outdated check fails

## Validate each package's composer.json

`packages:composer:validate` runs `composer validate` on the `composer.json` of each package in
`.gitsplit.yml`, from the package's directory, and prints composer's output for any that fail. It runs
on the working directory.

```shell
cd opentelemetry-php
vendor/bin/otel packages:composer:validate
```

## List the latest releases

`release:list` prints a table of each split repository and its latest release.

```shell
vendor/bin/otel release:list --stable
```

| Option | Meaning |
|---|---|
| `--path` | Monorepo checkout (default: working directory) |
| `--token` | GitHub token (default: `GITHUB_TOKEN`, optional) |
| `--stable` | Only packages with a 1.0 release |
| `--unstable` | Only packages without a 1.0 release |

## Release the PECL extension

The opentelemetry extension is released to PECL from
[opentelemetry-php-instrumentation](https://github.com/open-telemetry/opentelemetry-php-instrumentation).
`release:pecl` writes the new `package.xml`, and `tag:pecl` tags the release.

`release:pecl` reads `ext/package.xml` and the commits since the latest release from a checkout. It
asks for the new version and its stability. Then it moves the current release into `<changelog>`, and
prints the updated XML.

It fails on a shallow clone, whose history may not reach the latest release. It also fails unless the
checkout is at the tip of `main` on GitHub.

```shell
bin/otel release:pecl --path=../opentelemetry-php-instrumentation
```

| Option | Meaning |
|---|---|
| `--path` | opentelemetry-php-instrumentation checkout, at the tip of `main` (default: working directory) |
| `--force` | Add a new version even with no changes |

Then:

1. Paste the XML into `ext/package.xml`, and check it in an editor for formatting and invalid XML.
2. Set `PHP_OPENTELEMETRY_VERSION` in `php_opentelemetry.h` to the new version.
3. Open a pull request with both files, and merge it once approved.
4. Tag the release with `bin/otel tag:pecl`. It asks for the tag, and tags the tip of `--branch`
   (default: `main`). It needs a [token](#create-a-github-token), and takes `--dry-run`.
5. Wait for the workflow to finish. It creates a draft release. Check it, then publish it.
6. Download and unzip the `opentelemetry-pecl` artifact from the release, which holds
   `opentelemetry-<version>.tar.gz`.
7. Upload `opentelemetry-<version>.tar.gz` at https://pecl.php.net/release-upload.php.
8. Check that it installs with `pecl install opentelemetry`.

## Develop dev-tools

The checks run in Docker, through the `Makefile`:

```shell
make install     # composer install
make all-checks  # style, phan, psalm, phpstan and the tests
make test        # unit and integration tests
make bash        # a shell in the PHP container
```

Psalm records existing issues in `psalm-baseline.xml`, so only new ones fail. After fixing one, drop
it from the baseline with `vendor-bin/psalm/vendor/bin/psalm --update-baseline`.
