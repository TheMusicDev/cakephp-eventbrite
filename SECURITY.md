# Security policy

This plugin handles Eventbrite access tokens and receives unauthenticated webhook requests, so please report
security problems privately.

## Reporting a vulnerability

[Open a private security advisory](https://github.com/TheMusicDev/cakephp-eventbrite/security/advisories/new). Do not
open a public issue, and do not include real tokens in the report.

## Supported versions

Only the latest release gets security fixes. While the plugin is on 0.x, a fix may need a new minor version, which
`^0.x` constraints do not pick up on their own, so check the release notes.

## Reports for the underlying client

Problems in the API client itself, including OAuth state signing, belong to
[`themusicdev/eventbrite`](https://github.com/TheMusicDev/eventbrite/security/advisories/new). If you're not sure which
side a problem is on, report it here.
