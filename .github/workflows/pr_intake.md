---
on:
  pull_request:
    types: [opened, synchronize]
permissions:
  contents: read
  pull-requests: write
safe-outputs:
  add-comment:
    max: 1
---

# PR Intake (Maintainer-Focused)

Review the pull request diff and leave one concise intake comment for maintainers.

Keep the comment short, practical, and focused on code quality in this repository.

Include exactly these sections:

## Change summary
- 2-4 bullets describing what changed and why it matters.

## Risky areas
- 1-3 bullets on potential regressions, compatibility concerns (especially legacy module compatibility), or files that deserve careful review.

## Missing tests/docs
- Identify missing validation or documentation updates, if any.
- Specifically check whether the PR appears to have covered:
  - `composer test`
  - `composer static`
  - `php -l` on changed PHP files
  - docs updates when behavior changed (`README.md`, `UPGRADING.md`, `docs/Deprecations.md`)

## Checklist reminders
- Short reminders aligned to repo standards (PSR-12, focused diff, tests/static checks, docs when behavior changes).

Constraints:
- Post only one comment.
- Keep output under ~220 words.
- Use maintainer-oriented language; avoid team-process overhead and generic boilerplate.
