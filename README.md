# Development tools for OpenTelemetry PHP

![CI Build](https://github.com/opentelemetry-php/dev-tools/workflows/PHP%20QA/badge.svg)
[![codecov](https://codecov.io/gh/opentelemetry-php/dev-tools/branch/main/graph/badge.svg?token=DSL6OW6TGC)](https://codecov.io/gh/opentelemetry-php/dev-tools)

## Release Management

A tool to find unreleased changes for OpenTelemetry, create new releases with release notes.

### Requirements

You need to be an administrator/owner of [opentelemetry-php](https://github.com/opentelemetry-php) to actually create releases. A lower-privileged account
should be able to do everything else, but will fail if you try to create a release.

You need to [create a fine-grained github access token](https://github.com/settings/personal-access-tokens/new) to be able to create a release.

For everything under `opentelemetry-php` (almost everything, ie the gitsplit destination):
* resource owner: `opentelemetry-php`
* repository access: `all repositories`
* permissions: `contents:read-and-write`

For `opentelemetry-php-instrumentation` (the extension):
* resouce owner: `open-telemetry`
* repository access: (only selected) `open-telemetry/opentelemetry-php-instrumentation`
* permissions: `contents:read-and-write`

You can provide the token either via the `GITHUB_TOKEN` env var (preferred), or the `--token=` CLI option.

### Usage

```shell
export GITHUB_TOKEN=<fine-grain-access-token>
bin/otel release:run -[vvv] [--token=token] [--branch=main] [--dry-run] [--repo=<core|contrib>]
```

Options:
- `-v[vv]` - verbosity
- `--token=` - github token (can also be passed by `GITHUB_TOKEN`)
- `--branch=` - github branch to tag from
- `--dry-run` - do not make any changes
- `--repo=` - choose a single upstream repo to run against (default: all)
- `--filter=` - filter repositories by prefix

The script will then:
* fetch `.gitsplit.yaml` from source repositories
* process yaml to determine downstream (read-only) repositories and their path association in upstream (eg open-telemetry/opentelemetry-php:/src/API -> opentelemetry-php/api)
* find latest release in downstream
* find changes in upstream newer than the latest release, and their associated pull request
* retrieve diffs from downstream between latest tag and chosen branch (eg main)
* check that upstream changes match downstream diffs

Once all the info has been gathered, it will iterate over each repo with unreleased changes. For each repo:
* list the changes
* display the last release version, and ask for new version
* ask if new release should be latest
* ask if the new release should be a draft (eg so you can manually adjust the notes)
* generate release notes
* create the release (unless `--dry-run` was specified)

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
stale.

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
