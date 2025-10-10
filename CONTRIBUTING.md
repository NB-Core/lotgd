# Contributing Guide

Thank you for helping improve Legend of the Green Dragon! This guide summarises the standards and workflows that keep the project healthy. For the full community expectations, review the [Code of Conduct](CODE_OF_CONDUCT.md).

## Quick Expectations

- **Coding style:** Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) for all PHP changes. Avoid adding new files under `lib/`; use modern `Lotgd\...` namespaces instead.
- **Automated checks:** Run `composer test`, `composer static`, and `php -l <file>` on any modified PHP files before submitting a pull request.
- **Documentation:** Update README sections, in-repo docs, or `UPGRADING.md` when your change alters behaviour, setup steps, or upgrade notes.
- **Small, focused commits:** Keep diffs minimal and scoped to the task at hand.

## Development Workflow (GitHub Flow)

1. **Open an issue or join the discussion** – create a GitHub issue describing the bug or feature before starting large changes. Reference existing issues if the work continues an ongoing effort.
2. **Fork the repository and create a branch** – name branches descriptively (e.g., `feature/async-refresh-fix` or `bugfix/login-timeout`). Avoid working directly on the default branch.
3. **Implement the change** – follow the expectations above. Coordinate breaking changes through issues and keep commits conventional when possible (`feat:`, `fix:`, `docs:`, etc.).
4. **Prepare your pull request** – include:
   - A clear summary of what changed and why.
   - References to related issues using `Fixes #123` / `Refs #456` as appropriate.
   - Confirmation that you completed the checklist below.
5. **Submit the pull request** – ensure reviewers can reproduce your work by describing local test steps or linking to CI results. Update the PR when feedback arrives.

### Pull Request Checklist

Before opening (or updating) a PR, verify each item:

- [ ] `composer test`
- [ ] `php -l <changed files>`
- [ ] `composer static`
- [ ] Documentation updates where behaviour or setup changed
- [ ] Referenced related issues in the PR description
- [ ] Minimal, focused diff (no unrelated refactors)
- [ ] Updated `UPGRADING.md` and/or `docs/Deprecations.md` for behaviour changes

## Setup & Tooling

- Start with the [Getting Started instructions](README.md#getting-started) to install dependencies (`composer install`) and configure the game locally.
- Prefer containerised development? Follow [docs/Docker.md](docs/Docker.md) for environment setup.
- Use the lint helpers before pushing:
  - `composer lint` to check formatting and coding standards.
  - `composer lint:fix` to automatically fix fixable style issues.

Once your environment is ready, repeat the automated checks listed above before opening a pull request.

## Need Help?

Ask in GitHub issues or join the community Discord linked in the README. We’re happy to help clarify expectations or review early proposals.
