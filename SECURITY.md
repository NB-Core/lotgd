# Security Policy

## Reporting a Vulnerability

The preferred channel for confidential reports is GitHub’s **Private vulnerability reporting** feature. Open a private advisory from the repository’s Security tab and include:

- Affected version or commit SHA
- Environment details (PHP version, database, browser, etc.)
- Reproduction steps and expected vs. actual behavior
- Impact assessment and any proof-of-concept
- Whether details are already public and any disclosure timing requests

If the advisory system is unavailable, please fall back to the alternate contact listed in the Security settings.

## Response Expectations

- We aim to acknowledge new reports within **7 calendar days**.
- Status updates are provided at least every **30 days** while an issue is under investigation.
- Once confirmed, we work to deliver a fix or mitigation as quickly as possible, prioritizing critical issues. Coordinated disclosure timing will be agreed with the reporter before public release.

These timelines reflect a volunteer-run project; we’ll communicate sooner whenever we can.

## Supported Versions

| Version | Supported |
|---------|-----------|
| Latest release (see [CHANGELOG](CHANGELOG.md)) | ✅ |
| Older releases | ❌ — may receive critical security patches at our discretion |

## Disclosure Policy and Safe Harbor

Good-faith security research is welcome. Please avoid impacting production players, respect rate limits, and do not access other users’ data. We will not pursue legal action for vulnerability testing performed within these bounds. Coordinate public disclosure with us so we can prepare a fix and notify the community.

## Recognition

With your permission, verified reporters are thanked in the release notes. The project does not operate a bug bounty or provide monetary rewards.
