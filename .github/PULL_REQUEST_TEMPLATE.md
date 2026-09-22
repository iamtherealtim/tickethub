<!-- Thanks for contributing! Please read CONTRIBUTING.md first. -->

## Summary

What does this change and why? Link the issue it closes (`Closes #123`).

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Refactor / internal
- [ ] Documentation
- [ ] Dependency or tooling update

## Checklist

- [ ] `composer test` passes (feature tests need a `tickethub_test` database — see tests/README.md)
- [ ] `composer cs` reports no style issues
- [ ] New behaviour has tests (feature test for routes, unit test for helpers)
- [ ] Schema changes ship as a new file in `app/Database/Migrations/` with a working `down()`
- [ ] User-visible changes are documented (README / docs) and listed under **Unreleased** in CHANGELOG.md
- [ ] No secrets, hostnames or real customer data in code, fixtures or screenshots

## How to test

Steps a reviewer can follow to see the change working.

## Screenshots

For UI changes, before/after (light and dark mode if relevant).
