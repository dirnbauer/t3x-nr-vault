# Code of Conduct 3.0 Update & Contact Standardization Plan

**Date**: 2026-01-26
**Status**: Draft - Pending Approval

## Executive Summary

This plan addresses two related objectives:
1. Update the Code of Conduct from Contributor Covenant 2.1 to 3.0
2. Standardize contact methods across the project to use GitHub features instead of email addresses

## Background

### Current State

**Code of Conduct (v2.1)**:
- Simplified version with basic sections
- Uses `info@netresearch.de` for violation reports
- Missing: enforcement guidelines, repair procedures, detailed behavior lists

**Contact Methods**:
- Email `info@netresearch.de` used in 4 user-facing locations
- No GitHub Discussions enabled
- No issue templates configured
- No PR template

### Why Update?

**CoC 3.0 Improvements**:
- Clearer definition of encouraged vs restricted behaviors
- Detailed enforcement ladder (Warning → Limited Activities → Suspension → Ban)
- Repair and accountability guidance
- Better scope definition

**Contact Standardization Benefits**:
- Centralized issue tracking
- Public accountability and transparency
- Community involvement in discussions
- Consistent experience for contributors

---

## Part 1: Code of Conduct Update

### Changes Overview

| Section | v2.1 (Current) | v3.0 (Target) |
|---------|----------------|---------------|
| Our Pledge | Basic diversity statement | Expanded dignity/rights language |
| Our Standards | 5 good + 4 bad behaviors | Renamed to Encouraged (7) + Restricted (7+4) |
| Enforcement | Single paragraph | Full enforcement ladder (4 levels) |
| Addressing Harm | Not present | New section with repair guidance |
| Scope | Not present | New section defining applicability |
| Attribution | Reference to v2.1 | Reference to v3.0 + licensing info |

### New CODE_OF_CONDUCT.md

```markdown
# Contributor Covenant Code of Conduct

## Our Pledge

We pledge to make our community welcoming, safe, and equitable for all.

We are committed to fostering an environment that respects and promotes the dignity, rights, and contributions of all individuals, regardless of characteristics including race, ethnicity, caste, color, age, physical characteristics, neurodiversity, disability, sex or gender, gender identity or expression, sexual orientation, language, philosophy or religion, national or social origin, socio-economic position, level of education, or other status. The same privileges of participation are extended to everyone who participates in good faith and in accordance with this Covenant.

## Encouraged Behaviors

While acknowledging differences in social norms, we all strive to meet our community's expectations for positive behavior. We also understand that our words and actions may be interpreted differently than we intend based on culture, background, or native language.

With these considerations in mind, we agree to behave mindfully toward each other and act in ways that center our shared values, including:

1. Respecting the **purpose of our community**, our activities, and our ways of gathering.
2. Engaging **kindly and honestly** with others.
3. Respecting **different viewpoints** and experiences.
4. **Taking responsibility** for our actions and contributions.
5. Gracefully giving and accepting **constructive feedback**.
6. Committing to **repairing harm** when it occurs.
7. Behaving in other ways that promote and sustain the **well-being of our community**.

## Restricted Behaviors

We agree to restrict the following behaviors in our community. Instances, threats, and promotion of these behaviors are violations of this Code of Conduct.

1. **Harassment.** Violating explicitly expressed boundaries or engaging in unnecessary personal attention after any clear request to stop.
2. **Character attacks.** Making insulting, demeaning, or pejorative comments directed at a community member or group of people.
3. **Stereotyping or discrimination.** Characterizing anyone's personality or behavior on the basis of immutable identities or traits.
4. **Sexualization.** Behaving in a way that would generally be considered inappropriately intimate in the context or purpose of the community.
5. **Violating confidentiality**. Sharing or acting on someone's personal or private information without their permission.
6. **Endangerment.** Causing, encouraging, or threatening violence or other harm toward any person or group.
7. Behaving in other ways that **threaten the well-being** of our community.

### Other Restrictions

1. **Misleading identity.** Impersonating someone else for any reason, or pretending to be someone else to evade enforcement actions.
2. **Failing to credit sources.** Not properly crediting the sources of content you contribute.
3. **Promotional materials**. Sharing marketing or other commercial content in a way that is outside the norms of the community.
4. **Irresponsible communication.** Failing to responsibly present content which includes, links or describes any other restricted behaviors.

## Reporting an Issue

Tensions can occur between community members even when they are trying their best to collaborate. Not every conflict represents a code of conduct violation, and this Code of Conduct reinforces encouraged behaviors and norms that can help avoid conflicts and minimize harm.

When an incident does occur, report it promptly through one of these channels:

- **GitHub Issues**: [Open a Code of Conduct report](https://github.com/netresearch/t3x-nr-vault/issues/new?template=code-of-conduct-report.yml)
- **Private reports**: Use [GitHub Security Advisories](https://github.com/netresearch/t3x-nr-vault/security/advisories/new) for sensitive matters requiring confidentiality

Community Moderators take reports of violations seriously and will make every effort to respond in a timely manner. They will investigate all reports of code of conduct violations, reviewing messages, logs, and recordings, or interviewing witnesses and other participants. Community Moderators will keep investigation and enforcement actions as transparent as possible while prioritizing safety and confidentiality.

## Addressing and Repairing Harm

If an investigation by the Community Moderators finds that this Code of Conduct has been violated, the following enforcement ladder may be used to determine how best to repair harm, based on the incident's impact on the individuals involved and the community as a whole.

### 1. Warning

- **Event**: A violation involving a single incident or series of incidents.
- **Consequence**: A private, written warning from the Community Moderators.
- **Repair**: Examples include a private written apology, acknowledgement of responsibility, and seeking clarification on expectations.

### 2. Temporarily Limited Activities

- **Event**: A repeated incidence of a violation that previously resulted in a warning, or the first incidence of a more serious violation.
- **Consequence**: A private, written warning with a time-limited cooldown period. The cooldown period may be limited to particular communication channels or interactions with particular community members.
- **Repair**: Examples include making an apology, using the cooldown period to reflect on actions and impact.

### 3. Temporary Suspension

- **Event**: A pattern of repeated violation which the Community Moderators have tried to address with warnings, or a single serious violation.
- **Consequence**: A private written warning with conditions for return from suspension.
- **Repair**: Examples include respecting the spirit of the suspension and meeting the specified conditions for return.

### 4. Permanent Ban

- **Event**: A pattern of repeated code of conduct violations that other steps on the ladder have failed to resolve, or a violation so serious that the Community Moderators determine there is no way to keep the community safe.
- **Consequence**: Access to all community spaces, tools, and communication channels is removed.
- **Repair**: There is no possible repair in cases of this severity.

## Scope

This Code of Conduct applies within all community spaces, and also applies when an individual is officially representing the community in public or other spaces. Examples of representing our community include using an official project address, posting via an official social media account, or acting as an appointed representative at an online or offline event.

## Attribution

This Code of Conduct is adapted from the [Contributor Covenant](https://www.contributor-covenant.org), version 3.0, available at https://www.contributor-covenant.org/version/3/0/

Contributor Covenant is stewarded by the Organization for Ethical Source and licensed under [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/).
```

---

## Part 2: Contact Standardization

### File-by-File Analysis

| File | Current Contact | Change Required | Rationale |
|------|-----------------|-----------------|-----------|
| `CODE_OF_CONDUCT.md` | `info@netresearch.de` | GitHub Issues + Security Advisories | Centralized, trackable |
| `SECURITY.md:66` | `info@netresearch.de` | GitHub Discussions | Security audit sponsorship inquiry |
| `composer.json:18` | `info@netresearch.de` | **Keep** | Standard package metadata |
| `Documentation/guides.xml:19` | `mailto:info@netresearch.de` | GitHub project URL | TYPO3 docs standard |
| `ext_emconf.php:8` | `info@netresearch.de` | **Keep** | TYPO3 extension metadata |
| PHP file headers | `info@netresearch.de` | **Keep** | Copyright notice, not contact |
| `CONTRIBUTING.md:167` | "contact the maintainers" | GitHub Discussions | Clear contact path |

### Files to Modify

#### SECURITY.md (line 66)

**Current**:
```markdown
This extension has not yet undergone a formal security audit. If you are interested in sponsoring a security audit, please contact info@netresearch.de.
```

**Proposed**:
```markdown
This extension has not yet undergone a formal security audit. If you are interested in sponsoring a security audit, please [open a discussion](https://github.com/netresearch/t3x-nr-vault/discussions) or reach out through the [GitHub project](https://github.com/netresearch/t3x-nr-vault).
```

#### Documentation/guides.xml (line 19)

**Current**:
```xml
project-contact="mailto:info@netresearch.de"
```

**Proposed**:
```xml
project-contact="https://github.com/netresearch/t3x-nr-vault"
```

#### CONTRIBUTING.md (line 167)

**Current**:
```markdown
If you have questions about contributing, please open a discussion or contact the maintainers.
```

**Proposed**:
```markdown
If you have questions about contributing, please [open a discussion](https://github.com/netresearch/t3x-nr-vault/discussions) on GitHub.
```

---

## Part 3: GitHub Project Configuration

### Required: Issue Templates

Create `.github/ISSUE_TEMPLATE/` directory with:

#### 1. Bug Report (`bug-report.yml`)

```yaml
name: Bug Report
description: Report a bug in nr-vault
title: "[Bug]: "
labels: ["bug", "triage"]
body:
  - type: markdown
    attributes:
      value: |
        Thanks for taking the time to fill out this bug report!
  - type: input
    id: typo3-version
    attributes:
      label: TYPO3 Version
      placeholder: "14.0.0"
    validations:
      required: true
  - type: input
    id: php-version
    attributes:
      label: PHP Version
      placeholder: "8.5.0"
    validations:
      required: true
  - type: input
    id: extension-version
    attributes:
      label: nr-vault Version
      placeholder: "0.1.0"
    validations:
      required: true
  - type: textarea
    id: description
    attributes:
      label: What happened?
      description: A clear and concise description of the bug.
    validations:
      required: true
  - type: textarea
    id: reproduction
    attributes:
      label: Steps to reproduce
      description: Steps to reproduce the behavior.
      placeholder: |
        1. Go to '...'
        2. Click on '...'
        3. See error
    validations:
      required: true
  - type: textarea
    id: expected
    attributes:
      label: Expected behavior
      description: What did you expect to happen?
    validations:
      required: true
  - type: textarea
    id: logs
    attributes:
      label: Relevant log output
      description: Please copy and paste any relevant log output.
      render: shell
```

#### 2. Feature Request (`feature-request.yml`)

```yaml
name: Feature Request
description: Suggest a new feature for nr-vault
title: "[Feature]: "
labels: ["enhancement"]
body:
  - type: markdown
    attributes:
      value: |
        Thanks for suggesting a feature! Please describe what you'd like to see.
  - type: textarea
    id: problem
    attributes:
      label: Problem or use case
      description: What problem are you trying to solve?
    validations:
      required: true
  - type: textarea
    id: solution
    attributes:
      label: Proposed solution
      description: Describe the solution you'd like.
    validations:
      required: true
  - type: textarea
    id: alternatives
    attributes:
      label: Alternatives considered
      description: Any alternative solutions or features you've considered?
```

#### 3. Code of Conduct Report (`code-of-conduct-report.yml`)

```yaml
name: Code of Conduct Report
description: Report a Code of Conduct violation
title: "[CoC]: "
labels: ["code-of-conduct"]
body:
  - type: markdown
    attributes:
      value: |
        Thank you for reporting a Code of Conduct concern. Reports are taken seriously.

        For **private or sensitive matters**, please use [GitHub Security Advisories](https://github.com/netresearch/t3x-nr-vault/security/advisories/new) instead.
  - type: textarea
    id: description
    attributes:
      label: Description
      description: Please describe what happened.
    validations:
      required: true
  - type: textarea
    id: location
    attributes:
      label: Where did this occur?
      description: Link to issue, PR, discussion, or describe the context.
    validations:
      required: true
  - type: textarea
    id: additional
    attributes:
      label: Additional context
      description: Any other information that might be helpful.
```

#### 4. Config File (`config.yml`)

```yaml
blank_issues_enabled: false
contact_links:
  - name: Security Vulnerability
    url: https://github.com/netresearch/t3x-nr-vault/security/advisories/new
    about: Report security vulnerabilities privately
  - name: Questions & Discussions
    url: https://github.com/netresearch/t3x-nr-vault/discussions
    about: Ask questions or start a discussion
```

### Required: Pull Request Template

Create `.github/PULL_REQUEST_TEMPLATE.md`:

```markdown
## Summary

<!-- Brief description of changes -->

## Related Issues

<!-- Link to related issues: Fixes #123, Relates to #456 -->

## Changes

<!-- List the main changes -->

-

## Checklist

- [ ] Tests pass locally (`composer test`)
- [ ] PHPStan passes (`composer stan`)
- [ ] Code style is correct (`composer lint`)
- [ ] Documentation updated (if applicable)
- [ ] CHANGELOG.md updated

## Test Plan

<!-- How can reviewers test this? -->
```

### Recommended: Enable GitHub Discussions

Go to repository Settings → Features → Enable Discussions with these categories:
- Announcements (maintainers only)
- General
- Ideas
- Q&A
- Show and tell

---

## Part 4: README Badge Update

**Current** (line 13):
```markdown
[![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa.svg)](CODE_OF_CONDUCT.md)
```

**Proposed**:
```markdown
[![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-3.0-4baaaa.svg)](CODE_OF_CONDUCT.md)
```

---

## Self-Compliance Checklist

After implementation, nr-vault will comply with CoC 3.0 expectations:

| Requirement | Status |
|-------------|--------|
| Clear reporting mechanism | ✅ GitHub Issues + Security Advisories |
| Enforcement process documented | ✅ 4-level ladder in CoC |
| Scope defined | ✅ Community spaces + representation |
| Issue templates for structured reports | ✅ Bug, Feature, CoC templates |
| PR template for contributions | ✅ Standard checklist |
| Discussion channel for questions | ✅ GitHub Discussions |
| No direct email dependencies | ✅ All contact via GitHub |

---

## Implementation Order

1. **Create issue templates** (`.github/ISSUE_TEMPLATE/*.yml`)
2. **Create PR template** (`.github/PULL_REQUEST_TEMPLATE.md`)
3. **Update CODE_OF_CONDUCT.md** (full v3.0 with GitHub reporting)
4. **Update SECURITY.md** (remove email for audit sponsorship)
5. **Update Documentation/guides.xml** (remove email)
6. **Update CONTRIBUTING.md** (link to discussions)
7. **Update README.md** (badge version)
8. **Enable GitHub Discussions** (manual step in repository settings)
9. **Single commit**: `chore: update Code of Conduct to v3.0 and standardize contact methods`

---

## Files Created/Modified

| Action | File |
|--------|------|
| Create | `.github/ISSUE_TEMPLATE/bug-report.yml` |
| Create | `.github/ISSUE_TEMPLATE/feature-request.yml` |
| Create | `.github/ISSUE_TEMPLATE/code-of-conduct-report.yml` |
| Create | `.github/ISSUE_TEMPLATE/config.yml` |
| Create | `.github/PULL_REQUEST_TEMPLATE.md` |
| Replace | `CODE_OF_CONDUCT.md` |
| Modify | `SECURITY.md` |
| Modify | `Documentation/guides.xml` |
| Modify | `CONTRIBUTING.md` |
| Modify | `README.md` |

---

## Manual Steps Required

1. **Enable GitHub Discussions** in repository settings (cannot be done via git)

---

## Approval

- [ ] Plan reviewed
- [ ] Ready to implement
