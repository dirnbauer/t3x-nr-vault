# Contributing to nr-vault

Thank you for your interest in contributing to nr-vault! This document provides guidelines and information for contributors.

## Code of Conduct

Please be respectful and constructive in all interactions. We welcome contributions from everyone.

## Getting Started

### Prerequisites

- PHP 8.5 or higher
- Composer 2.x
- TYPO3 v14
- Docker and DDEV (recommended for local development)

### Development Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/netresearch/nr-vault.git
   cd nr-vault
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Start the development environment (if using DDEV):
   ```bash
   ddev start
   ddev composer install
   ```

## Development Workflow

### Branch Naming

Use descriptive branch names with prefixes:
- `feature/` - New features
- `fix/` - Bug fixes
- `docs/` - Documentation updates
- `refactor/` - Code refactoring
- `test/` - Test additions or fixes

### Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add secret rotation support
fix: resolve memory leak in encryption service
docs: update installation instructions
test: add unit tests for VaultService
refactor: simplify access control logic
```

### Code Style

This project follows PER-CS 2.0 coding standards. Run the fixer before committing:

```bash
composer cs-fix
```

Check for code style issues:

```bash
composer cs-check
```

### Static Analysis

We use PHPStan at level 8. Run analysis:

```bash
composer phpstan
```

### Testing

Run all tests:

```bash
composer test
```

Run specific test suites:

```bash
# Unit tests
composer test:unit

# Functional tests
composer test:functional
```

## Pull Request Process

1. **Fork and branch**: Create a feature branch from `main`

2. **Make changes**: Implement your changes following the coding standards

3. **Test**: Ensure all tests pass and add new tests for your changes

4. **Commit**: Use conventional commit messages

5. **Push**: Push your branch to your fork

6. **Open PR**: Create a pull request with:
   - Clear description of changes
   - Link to any related issues
   - Screenshots for UI changes

### PR Requirements

- [ ] All tests pass
- [ ] PHPStan reports no errors
- [ ] Code style is correct
- [ ] Documentation is updated (if applicable)
- [ ] CHANGELOG.md is updated

## Reporting Issues

### Bug Reports

When reporting bugs, please include:

- TYPO3 version
- PHP version
- Steps to reproduce
- Expected behavior
- Actual behavior
- Error messages (if any)

### Feature Requests

For feature requests, please describe:

- The problem you're trying to solve
- Your proposed solution
- Alternative solutions considered

## Security Vulnerabilities

**DO NOT** create public issues for security vulnerabilities.

Please report security issues to: **security@netresearch.de**

See [SECURITY.md](SECURITY.md) for details.

## Documentation

- Update documentation for any user-facing changes
- Use RST format in `Documentation/` directory
- Keep README.md synchronized with documentation

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.

## Questions?

If you have questions about contributing, please open a discussion or contact the maintainers.

---

Thank you for contributing to nr-vault!
