---
on:
  pull_request:
    types: [opened, reopened, synchronize]
permissions:
  pull-requests: write
  contents: read
if: >
  github.event.pull_request.user.login == 'dependabot[bot]' ||
  startsWith(github.event.pull_request.head.ref, 'dependabot/')
safe-outputs:
  add-comment:
    max: 1
---

# Dependency PR Triage Assistant

When this workflow runs for a Dependabot Composer pull request, add a concise review comment that helps maintainers quickly triage risk.

## Output format
Use the following Markdown headings:

1. `## Dependency Change Summary`
   - State package name(s), from/to version(s), and whether updates are grouped.
2. `## Risk Review`
   - Classify risk as Low/Medium/High with 2-4 bullets covering:
     - Semver level (patch/minor/major)
     - Any framework/runtime implications visible from lockfile diff
     - Potential impact areas (DB, auth/session, rendering, async, tests)
3. `## Recommended Validation`
   - Provide concrete commands maintainers should run for this repo:
     - `composer install`
     - `composer test`
     - `composer static`
   - Add 1-3 focused checks if specific packages suggest extra risk.
4. `## Merge Guidance`
   - Give a short recommendation: merge now, merge after checks, or needs manual review.

## Constraints
- Keep total response under 250 words.
- Do not claim commands were executed.
- If details are uncertain, say "not obvious from PR metadata" rather than guessing.
