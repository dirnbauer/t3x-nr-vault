# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 0.x     | :white_check_mark: |

## Reporting a Vulnerability

We take security vulnerabilities in nr-vault seriously. If you discover a security issue, please report it responsibly.

### How to Report

**DO NOT** create a public GitHub issue for security vulnerabilities.

Use GitHub's private security reporting feature:

**[Report a vulnerability](https://github.com/netresearch/t3x-nr-vault/security/advisories/new)**

Include the following information:
- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### What to Expect

1. **Acknowledgment**: We will acknowledge receipt within 48 hours
2. **Assessment**: We will assess the vulnerability within 7 days
3. **Resolution**: Critical vulnerabilities will be patched within 30 days
4. **Disclosure**: We follow responsible disclosure practices

### Security Considerations

This extension handles sensitive data (API keys, credentials, secrets). Key security features:

- **Envelope Encryption**: AES-256-GCM with per-secret Data Encryption Keys
- **Master Key Protection**: Stored outside database (file, env, or derived)
- **Access Control**: Backend user group-based permissions
- **Audit Logging**: Tamper-evident hash chain for all operations
- **Memory Safety**: Sensitive data wiped with `sodium_memzero()`

### Security Best Practices

When using nr-vault:

1. **Master Key Storage**
   - Store master key outside webroot
   - Use file permissions 0400
   - Never commit to version control
   - Backup separately from database

2. **Access Control**
   - Restrict CLI access unless needed
   - Use context-based permission scoping
   - Review audit logs regularly

3. **Operations**
   - Rotate master key annually
   - Rotate secrets after personnel changes
   - Monitor for `access_denied` events

## Security Audit

This extension has not yet undergone a formal security audit. If you are interested in sponsoring a security audit, please contact info@netresearch.de.

## Acknowledgments

We appreciate responsible disclosure and will acknowledge security researchers who report valid vulnerabilities (unless they prefer to remain anonymous).
