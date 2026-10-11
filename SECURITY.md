# Security policy

LapFund stores personal data of children, parents and sponsors. Reports of security problems are very welcome.

## Supported versions

Security fixes are made for the latest release. Please update to it before reporting.

| Version | Supported |
| --- | --- |
| 1.x (latest) | yes |
| 0.x | no |

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately instead:

1. On GitHub, open the **Security** tab of this repository and choose **Report a vulnerability**.
2. Describe the problem, the affected version and how to reproduce it. A proof of concept helps, but please do not access data that is not yours.

You will get an answer within a week. Once a fix is released, the report is published as a security advisory, with credit if you like.

## For operators

- Install only release ZIPs from the [releases](../../releases) page and keep LapFund up to date.
- Use HTTPS and point the web root to `public/`.
- Keep `config/config.php` (it holds the encryption key) and database exports in a safe place.
- Every admin uses their own account with two-factor authentication; deactivate accounts that are no longer needed.
