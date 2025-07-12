# AGENT Guidelines

This repository uses PHP with Composer and PHPUnit. To ensure quality and consistency, follow these policies when contributing code or running QA reviews.

## Testing

- Install dependencies with `composer install` before running tests.
- Execute the full test suite using `composer test`.
- New or changed features should include appropriate tests under the `tests/` directory.

## Coding Standards

- Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding style (4-space indentation, braces on the next line, etc.).
- Include PHPDoc blocks for functions and classes when relevant.
- Keep functions small and focused. Avoid unrelated refactoring in a single PR.

## Pull Request Checklist

Before opening a PR:

1. Ensure all tests pass with `composer test`.
2. Run `php -l <file>` on changed PHP files to check for syntax errors.
3. Update or add documentation when necessary.
4. Provide a clear description of the change and reference related issues.
5. Keep the diff minimal, focusing only on the feature or fix.

These rules apply to all directories unless a more specific file overrides them.
