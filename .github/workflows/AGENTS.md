<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-04-20 | Last verified: 2026-04-20 -->

# AGENTS.md — .github/workflows

## Overview
GitHub Actions workflows for nr-vault. CI, releases, auto-merge, and community automation.

## Key Files
| File | Purpose |
|------|---------|
| `ci.yml` | Lint + PHPStan + PHPUnit (unit/functional/fuzz) matrix on PRs & main |
| `release.yml` | Tag-triggered TER publish + GitHub release assets |
| `auto-merge-deps.yml` | Auto-merge dependency PRs when CI green (Renovate/Dependabot) |
| `community.yml` | Community health: labeler, stale bot, welcome |
| `../labeler.yml` | PR auto-labeling rules |
| `../CODEOWNERS` | Code ownership |
| `../renovate.json` | Renovate configuration |

## Golden Samples
| Pattern | Reference |
|---------|-----------|
| TYPO3 extension matrix test | `.github/workflows/ci.yml` |
| TER release pipeline | `.github/workflows/release.yml` |
| Auto-merge with CI gate | `.github/workflows/auto-merge-deps.yml` |

## Setup
- Workflows run on GitHub-hosted runners (`ubuntu-latest`).
- Local validation: `actionlint` (via pre-commit or `gh act`).

## Build/Tests
| Task | Command |
|------|---------|
| Lint workflows | `actionlint .github/workflows/*.yml` |
| Local workflow run | `act -j <job>` (requires Docker) |
| Workflow status | `gh run list --limit 5` |
| Re-run failed run | `gh run rerun <run-id>` |
| Inspect annotations | `gh api repos/$REPO/check-runs/$ID/annotations` |

## Directory Structure
```
.github/
├── workflows/
│   ├── ci.yml
│   ├── release.yml
│   ├── auto-merge-deps.yml
│   └── community.yml
├── actions/                 # composite actions (if any)
├── labeler.yml
├── renovate.json
├── CODEOWNERS
└── pull_request_template.md (optional)
```

## Code Style
- **Pin every action to a full commit SHA** (not tags). Keep `# vX.Y.Z` comment next to the SHA.
- **Minimal permissions** on every workflow — declare `permissions: contents: read` by default, widen per-job only where needed.
- **Reusable workflows** live under `.github/workflows/reusable-*.yml` or are centrally hosted.
- **Naming**:
  - Workflow file: `<purpose>.yml`
  - Workflow name: Title Case (`CI`, `Release`)
  - Job id: `kebab-case`
  - Step name: Sentence case
  - Secret: `SCREAMING_SNAKE`
- **Caching**: `actions/setup-php` + `actions/cache` for composer, cache by `composer.json`.
- **Concurrency**: add `concurrency: { group: ${{ github.workflow }}-${{ github.ref }}, cancel-in-progress: true }` on CI.

## Security
- **Never use `permissions: write-all`** — declare minimal per-job.
- **Never use `secrets: inherit`** when calling reusable workflows — pass secrets explicitly by name:
  ```yaml
  secrets:
    TER_TOKEN: ${{ secrets.TER_TOKEN }}
  ```
- **Pin actions to commit SHAs** (not tags) — mitigates tag-hijack supply-chain attacks.
- **Mask dynamic values** with `::add-mask::` before logging.
- **Environment protection** for release/deploy — require reviewers.
- **OIDC** over long-lived credentials where possible.
- **Reviewdog / actionlint**: set `fail_level: error` so warnings block merges.

## Checklist
- [ ] `actionlint` clean
- [ ] Actions pinned to full SHA with version comment
- [ ] `permissions:` block explicit and minimal
- [ ] No `secrets: inherit` — explicit per-secret
- [ ] Cache key uses lockfile/composer.json hash
- [ ] Concurrency group set for long-running workflows
- [ ] CI annotations checked: `gh api repos/OWNER/REPO/check-runs/$ID/annotations`

## Examples
```yaml
# Pinned action with comment
- uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2

# Minimal permissions + concurrency
name: CI
on: [push, pull_request]
permissions:
  contents: read
concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683 # v4.2.2
      - uses: shivammathur/setup-php@c541c155eee45413f5b09a52248675b1a2575231 # v2.31.1
        with:
          php-version: '8.2'
          coverage: none
      - run: composer install --prefer-dist --no-progress
      - run: composer ci
```

## When Stuck
- Actions docs: <https://docs.github.com/en/actions>
- Workflow syntax: <https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions>
- Existing workflows in this repo are the best reference.
- Invoke skill: `github-project` for ruleset / merge-queue / branch protection
- Invoke skill: `git-workflow` for release orchestration
