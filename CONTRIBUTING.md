# Contributing to HashtagCMS Migration Tool

Thank you for considering contributing! This document outlines how to get started and what to keep in mind.

---

## Code of Conduct

Be respectful. Constructive criticism is welcome; hostility is not.

---

## Getting Started

1. Fork the repository.
2. Clone your fork locally.
3. Install dependencies: `composer install`
4. Create a feature branch: `git checkout -b feature/your-feature-name`

---

## Making Changes

- Follow PSR-12 coding standards.
- Namespace: `HashtagCms\MigrationTool`.
- Casing convention: `HashtagCms` (capital C in Cms).
- New migration steps must implement `MigrationStepInterface`.
- All pivot syncs must use `updateOrInsert` for idempotency.
- Add logging for any step that modifies data.

---

## Submitting a PR

1. Ensure your branch is up-to-date with `main`.
2. Write a clear PR description explaining what changed and why.
3. Update `CHANGELOG.md` under `[Unreleased]`.
4. Update relevant docs in the `docs/` folder.

---

## Reporting Issues

Use GitHub Issues. Include:
- PHP and Laravel versions.
- Source/target DB type and version.
- The exact error message or unexpected behavior.
- Steps to reproduce.

---

## License

By contributing, you agree your contributions will be licensed under the MIT License.
