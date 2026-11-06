# Development tools for OpenTelemetry PHP

![CI Build](https://github.com/opentelemetry-php/dev-tools/workflows/PHP%20QA/badge.svg)
[![codecov](https://codecov.io/gh/opentelemetry-php/dev-tools/branch/main/graph/badge.svg?token=DSL6OW6TGC)](https://codecov.io/gh/opentelemetry-php/dev-tools)

## Release Management

A tool to find unreleased changes for OpenTelemetry, create new releases with release notes.

### Requirements

You need to be an administrator/owner of [opentelemetry-php](https://github.com/opentelemetry-php) to actually create releases. A lower-privileged account
should be able to do everything else, but will fail if you try to create a release.

You need to [create a fine-grained github access token](https://github.com/settings/personal-access-tokens/new) to be able to create a release.

For everything under https://github.com/opentelemetry-php (API, SDK, contrib, etc):
* resource owner: `opentelemetry-php`
* repository access: `all repositories`
* permissions: `contents:read-and-write`

For https://github.com/open-telemetry/opentelemetry-php-instrumentation (the extension):
* resouce owner: `open-telemetry`
* repository access: (only selected) `open-telemetry/opentelemetry-php-instrumentation`
* permissions: `contents:read-and-write`

You can provide the token either via the `GITHUB_TOKEN` env var (preferred), or the `--token=` CLI option.

### Usage

```shell
export GITHUB_TOKEN=<fine-grain-access-token>
bin/otel release:run -[vvv] --path=../opentelemetry-php [--token=token] [--branch=main] [--sha=<commit>] [--dry-run] [--repo=<core|contrib>] [--no-interaction] [--format=json]
```

Options:
- `-v[vv]` - verbosity
- `--token=` - github token (can also be passed by `GITHUB_TOKEN`)
- `--path=` - a checkout of the monorepo being released, at the commit being tagged, with `composer install`
  run. Required.
- `--branch=` - github branch to tag from (default: `main`)
- `--sha=` - the monorepo commit to release, which must be on `--branch` (default: the tip of `--branch`)
- `--dry-run` - do not make any changes
- `--repo=` - the monorepo being released (default: the one `--path` is a checkout of)
- `--filter=` - filter repositories by prefix
- `-n`, `--no-interaction` - release without asking, see below
- `--format=` - `text` (default) or `json`, see below

#### Check the checkout

The packages, their changelogs and their tag order are all read from the checkout. Core's service
providers declare `#[PackageDependency]` on packages released in the same run, and the order those need
can only be read from a checkout. The command fails before any GitHub call when `--path` is missing, is
not a checkout of the repo being released, has uncommitted changes to tracked files, or has no `vendor/`.

The checkout's `HEAD` must be the commit being released. That is `--sha`, which must be on `--branch` on
GitHub. Without `--sha`, it is the tip of `--branch` as the run starts, so pull before releasing. With
`--dry-run`, these checks warn instead.

#### Tag the split commit that matches

gitsplit rewrites every commit it pushes to a split repository, so the split commit to tag is found by
content. For each package with changes, the command reads the tree of the package's directory in the
commit being released, then walks the split's `--branch` history back to its latest release, at most
100 commits, for the commit with the same tree. That commit is tagged, and the downstream diffs and the
split's `CHANGELOG.md` are read at it.

Nothing reads the moving branch tip after the run starts. A merge during the run is neither tagged nor a
reason to stop.

gitsplit updates the split repositories some time after a merge. If no split commit matches yet, the run
stops: wait for gitsplit and run it again. A rerun with the same `--sha` skips the packages already
tagged, as their latest release is then their changelog version. With `--dry-run`, it warns instead.

The script will then:
* read `.gitsplit.yml` from the checkout
* process yaml to determine downstream (read-only) repositories and their path association in upstream (eg open-telemetry/opentelemetry-php:/src/API -> opentelemetry-php/api)
* find latest release in downstream
* find changes in upstream newer than the latest release, up to the commit being released, and their associated pull request
* retrieve diffs from downstream between latest tag and the split commit
* check that upstream changes match downstream diffs

The packages that need each other's new versions are put in tag order, as `release:prep` prints it, and
the order is printed. The new version of each is taken from its changelog.

Once all the info has been gathered, it will iterate over each repo with unreleased changes. For each repo:
* list the changes
* display the last release version, and ask for new version (blank accepts the version in the package's `CHANGELOG.md`, `skip` skips)
* ask if new release should be latest
* ask if the new release should be a draft (eg so you can manually adjust the notes)
* generate release notes
* create the release (unless `--dry-run` was specified)

The default version is the newest dated section (`## 1.45.0 - 2026-11-03`) of the package's `CHANGELOG.md` in
the checkout, when it is newer than the latest release. That is the section `release:prep` writes,
so merge the `release:prep` pull request before running `release:run`.

The release notes are the body of that section, followed by a compare link. A package with no `CHANGELOG.md`, or
no section for the version you enter, gets the list of merged pull requests instead.

#### Release without asking

With `--no-interaction`, as in CI, nothing is asked and nothing unexpected is let through:

* each package with changes is released as its newest dated changelog version, not as a draft
* a package with changes but no dated section newer than its latest release is skipped, and named
* the release is the latest only when `--branch` is `main`, so a backport doesn't take the badge
* commits in a split that are not upstream stop the run before anything is tagged
* a failed release request stops the run, as later packages may need that one
* `--force` is refused, as it needs someone to choose the versions

The token must then come from `GITHUB_TOKEN` or `--token`. A failed release request stops the run in
interactive mode too.

#### Read the result as JSON

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

* `releases` are in the order they were made. With `--dry-run` they are the releases that would be
  made, and `url` is null.
* `target` is the split commit tagged.
* `skipped` holds the packages with changes that were not released, and why.
* `completed` is false, and the exit status non-zero, when the run failed. `releases` then holds those
  made before it. The reason is on stderr.
* `sha` is null when the run failed before choosing the commit, on the checkout say.

```shell
bin/otel release:run -n --path=../opentelemetry-php --sha="$SHA" --format=json | jq -r '.releases[].url'
```

## Prepare a release

`release:prep` picks the next version of each package from its `CHANGELOG.md`, and prepares the
checkout for tagging: the changelogs, the branch aliases and the root `replace`. Run it before
`release:run`, so the tag carries the dated changelog.

Each changelog keeps its pending changes under `## Unreleased`, in
[Keep a Changelog](https://keepachangelog.com) sections. The sections decide the version:

| Unreleased contains | Suggested version |
|---|---|
| Nothing | No release |
| Only `Fixed` or `Security` | Patch |
| `Added`, `Changed` or `Deprecated` | Minor |
| `Breaking changes` or `Removed` | Minor, and the entries are listed as warnings |

A major is never suggested. Some breaking changes ship in 1.x minors, so the maintainer decides. Force
one with `--set`, or type it at the prompt. A heading under Unreleased that is not one of the sections
above is an error.

Two packages take their version from a file in the checkout:
* `open-telemetry/sem-conv` takes the version the generator wrote to
  `script/semantic-conventions/templates/registry/php/version.txt`. It is released
  only when that is newer than the latest tag.
* `open-telemetry/gen-otlp-protobuf` mirrors `proto/otel/VERSION` exactly, patch included, so proto
  `v1.11.1` is package `1.11.1`. It is released only when that is newer than the latest tag.

This command works on a local monorepo checkout. It reads each package's `CHANGELOG.md` and
`composer.json` there, takes the package list from `.gitsplit.yml`, and rewrites the files. Only the
latest-release lookups go over the GitHub API. Even `--dry-run` needs the checkout, with
`composer install` run, as the `#[PackageDependency]` attributes are read through its autoloader.

Run it from the monorepo, where `dev-tools` is a dev dependency:

```shell
cd opentelemetry-php
vendor/bin/otel release:prep --dry-run
vendor/bin/otel release:prep --set=open-telemetry/sdk=2.0.0
```

Or run it from a `dev-tools` checkout and point `--path` at the monorepo:

```shell
bin/otel release:prep --path=../opentelemetry-php --dry-run
```

### Prepare from an up-to-date checkout

A checkout behind the upstream branch would date each release without the changelog entries it is
missing. Those entries would stay under the new `## Unreleased`, and the tag would not carry them. So
before reading anything, `release:prep` looks up the tip of `--branch` on GitHub and checks that the
checkout's `HEAD` includes it. Commits on top of the branch and uncommitted changes are fine, since they
are the prep.

The tip is looked up on GitHub rather than through a local remote, because `origin` may be a fork. When
the check fails, fetch the branch from the upstream repository and rebase onto it:

```shell
git fetch https://github.com/open-telemetry/opentelemetry-php main
git rebase FETCH_HEAD
```

`--dry-run` warns instead of failing.

Options:
- `--path=` - path to a monorepo checkout (default: working directory)
- `--branch=` - upstream branch the checkout must include (default: `main`)
- `--token=` - github token (optional, can also be passed by `GITHUB_TOKEN`)
- `--filter=` - filter packages by prefix
- `--dry-run` - report the suggestions without writing anything
- `--date=` - release date as `YYYY-MM-DD` (default: today)
- `--set=<package>=<version|skip>` - release a package as this version, or not at all, without prompting.
  Repeatable. The package is the composer name or the split repository name. A skipped package keeps its
  changelog and alias, and is reported as "skipped by --set".
- `--format=` - `text`, `markdown` or `json`. Markdown suits a pull request description. See below for JSON.
- `--no-interaction` - accept every suggestion without prompting

For each package it will:
* skip it when it has no `CHANGELOG.md`
* find the latest release of the split repository
* print the latest version, the suggestion and the reason, then list any breaking entries
* ask for the version (blank accepts the suggestion, `skip` skips, a typed version overrides)
* rename `## Unreleased` to `## X.Y.Z - YYYY-MM-DD`, and add an empty `## Unreleased` above it
* move its `dev-main` branch alias to the new version's line, eg `1.10.x-dev` to `1.11.x-dev`, unless the
  alias already covers it (`1.x-dev` covers 1.11.0)

A forced major warns when Unreleased records no breaking changes.

The version is a decision, so an alias ahead of it is lowered rather than kept: sdk-configuration at
`1.0.x-dev`, released as 0.10.0, becomes `0.10.x-dev`. A lowered alias is printed as a warning.

Then it sets the root `composer.json` `replace` entry of every split package to that package's alias,
whether or not it is released this round. Inside the monorepo, composer resolves a split package to its
`replace` entry, so that is the version a `#[PackageDependency]` is checked against at runtime. Entries
with no split are left alone. As with the aliases, only the values are rewritten.

Packages in a monorepo depend on each other's new versions, so after the versions are chosen it also
checks the constraints between them: `require` and `conflict` in each package's `composer.json`, and the
`#[PackageDependency]` attributes on the service providers it registers.
* when a package needs another's new version (sdk `^1.16` needs api `1.11`, say), it prints the order to
  tag them in
* when a `require` or `#[PackageDependency]` excludes another package's new version, or a `conflict`
  matches it, it names the package. This is how a forced major shows up: the dependents that need their
  constraint widened.

Only packages in the run are checked. Packages outside it, such as the contrib packages that require the
sdk, are not.

`--dry-run` reports the alias and `replace` changes too, without writing them.

### Read the suggestions as JSON

With `--format=json`, stdout holds one JSON object and nothing else. The table, prompts, messages and
errors go to stderr. The object holds each package's latest release, the version chosen (null when it
is not released) with the reason, and its breaking-change warnings. It also holds the tag order, the
constraint warnings, and whether anything is to be released.

```json
{
    "packages": [
        {"package": "open-telemetry/api", "repository": "opentelemetry-php/api", "latest": "1.10.1", "version": "1.11.0", "reason": "new features", "warnings": []},
        {"package": "open-telemetry/sdk", "repository": "opentelemetry-php/sdk", "latest": "1.15.0", "version": "1.16.0", "reason": "breaking changes recorded", "warnings": ["Dropped X."]},
        {"package": "open-telemetry/context", "repository": "opentelemetry-php/context", "latest": "1.4.0", "version": null, "reason": "no unreleased changes", "warnings": []}
    ],
    "order": ["open-telemetry/api", "open-telemetry/sdk"],
    "constraint_warnings": [],
    "release": true
}
```

```shell
vendor/bin/otel release:prep -n --dry-run --format=json | jq '[.packages[].warnings[]] | length'
```

Then review the changes with `git diff`, open a pull request, and once it is merged run `release:run`.

## Check SPI package dependencies in a monorepo

[tbachert/spi](https://github.com/Nevay/spi) only loads a service provider when the packages named in its
`#[PackageDependency]` attributes are installed at a matching version. Its
`composer spi:show-outdated-package-dependencies` checks each constraint against the latest release on
Packagist. In a monorepo that fails during a release: a provider that needs a split package's next
version cannot say so until that version is tagged.

`packages:spi:check` replaces it in a monorepo. For each `#[PackageDependency]` on a split package, it
checks the constraint against the package's `dev-main` alias, which `release:prep` sets to the version
about to be released, and against the root `replace` entry that satisfies it at runtime. A constraint the
latest release doesn't meet is fine, as long as the alias does. Dependencies on other packages are passed
to `composer spi:show-outdated-package-dependencies`, with the split packages ignored.

```shell
cd opentelemetry-php
vendor/bin/otel packages:spi:check
```

Options:
- `--path=` - path to a monorepo checkout (default: working directory)

The checkout needs `composer install`: the attributes are read by a script run under its own PHP and
autoloader. It prints a row per attribute, and exits non-zero when an alias or `replace` entry does not
satisfy a constraint, or the outdated check fails. A provider registered in `extra.spi` that does not load
is warned about.

## Branch aliases

Each package in a monorepo declares a [composer branch alias](https://getcomposer.org/doc/articles/aliases.md)
in its own `composer.json`, which tells composer what version line the development branch belongs to:

```json
{
    "extra": {
        "branch-alias": {
            "dev-main": "1.10.x-dev"
        }
    }
}
```

These aliases go stale as packages are released. Composer only applies a branch alias to a dev branch (a
tagged release takes its version from the tag, and the alias committed in that tag is ignored), so the
alias is updated **after** tagging, not before it. `release:run` will remind you when a release makes one
stale. `release:prep` sets the aliases of the packages it prepares, so after a prepared release there is
usually nothing left to update.

`dev-tools` is a dev dependency of both monorepos, so run the command from a monorepo checkout. It takes
the packages to update from that checkout's own `.gitsplit.yml`, so it only ever touches the monorepo you
are standing in:

```shell
cd opentelemetry-php
vendor/bin/otel update:branch-alias [--dry-run] [-v]
```

Options:
- `-v[vv]` - verbosity
- `--path=` - path to a monorepo checkout (default: working directory)
- `--token=` - github token (can also be passed by `GITHUB_TOKEN`)
- `--branch=` - branch the alias applies to (default: `main`, ie the `dev-main` alias)
- `--filter=` - filter packages by prefix
- `--dry-run` - report what would change, without writing anything

For each package of the monorepo it will:
* find the latest release of the split (downstream) repository
* work out the alias for that version, eg `1.10.0` becomes `1.10.x-dev`
* rewrite that one value in the package's `composer.json`, leaving the rest of the file untouched

The following are reported but never modified:
* a package that does not declare a `branch-alias` at all (most of the contrib packages)
* a package whose alias is ahead of its releases, eg an alias of `1.0.x-dev` where the latest release is
  `0.0.5`: that alias is aspirational rather than stale, and lowering it would break constraints that
  currently resolve against the branch
* a package whose alias cannot be safely identified in the raw file

Then review the changes with `git diff`, and open a pull request against the monorepo.

## PECL release tool

### Generate updated `package.xml`
A tool to fetch and update package.xml, for a new version of the opentelemetry extension on PECL.

```shell
bin/otel release:pecl
```

Options:
- `-v[vv]` - verbosity
- `--force` - add a new version even if no changes detected

The script will then:
* fetch latest release
* fetch all commits newer than last release
* fetch `package.xml`
* prompt for next version number
* move existing version details into a new `release` in `<changelog>`
* update XML with new version details
* write updated `package.xml` to console

Manual steps:
1. copy/paste XML into `package.xml`
2. open in IDE to check for/fix formatting and invalid XML (invalid chars should have been converted)
3. update `php_opentelemetry.h` version info to match new version# (look for `PHP_OPENTELEMETRY_VERSION`)
4. submit a PR (`package.xml` + `php_opentelemetry.h`) back to [opentelemetry-php-instrumentation](https://github.com/open-telemetry/opentelemetry-php-instrumentation)
5. get approval and merge PR
6. tag next release: `bin/otel tag:pecl`
7. wait for github workflow to run to completion. it will create a draft release: check that it looks ok, then publish it
8. download and unzip the `opentelemetry-pecl` artifact from the release (containing `opentelemetry-<version>.tar.gz`)
9. upload `opentelemetry-<version>.tar.gz` to pecl: https://pecl.php.net/release-upload.php
10. verify (install via pecl)
