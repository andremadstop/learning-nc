# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability** ([direct link](https://github.com/andremadstop/learning-nc/security/advisories/new)). Only the maintainer can see the report. The fix and an advisory are prepared there, and you are credited unless you prefer not to be.

If that button is not available, open an issue titled **"Security contact request"** that contains **no details** about the problem. The maintainer will reply with a private way to send them.

A helpful report contains:

- the app version, Nextcloud version and database
- the role needed to exploit it (anonymous visitor, learner, instructor, administrator)
- steps to reproduce or a proof of concept, and what an attacker gains
- whether AI features or public certificate verification must be enabled for it

Please test only on an instance you own, and do not access other people's data.

## Supported versions

Security fixes are made for the **latest release** in the [Nextcloud App Store](https://apps.nextcloud.com/apps/learning). Older versions are not patched; the fix is to update. The app supports Nextcloud 33 to 35.

| Version | Supported |
|---|---|
| Latest release | ✓ |
| Anything older | ✗ (please update) |

## What to expect

Learning is maintained by one person, not a security team. Realistically:

- an acknowledgement within about **a week**
- a first assessment (confirmed or not, how severe) as soon as it is reproduced
- a fix for a confirmed, serious issue in the next release, usually within a few weeks. Lower-severity issues may be bundled with a regular release.

If you have heard nothing after two weeks, a short reminder is welcome. Please give the fix time to reach the App Store before you publish details. A coordinated disclosure date can be agreed in the report.

## Scope

In scope: the code in this repository, which is the Nextcloud app `learning`. This includes the public certificate verification pages and the signed credentials.

Out of scope: Nextcloud server itself (report to [Nextcloud](https://hackerone.com/nextcloud)), the configuration of a particular instance, and third-party AI providers.
