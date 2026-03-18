# Deployment Models

This guide explains how `wp-core-base` fits into different WordPress architectures.

The main distinction is simple:

- `wp-core-base` helps manage source code and update flow
- your deployment model determines how that code reaches a server

Those are related, but they are not the same thing.

## Model 1: GitHub As Source Of Truth, CI/CD Deploys To Hosting

Use this when:

- your team already deploys from GitHub
- you want the cleanest review and deployment chain
- you are comfortable with pull requests and automation

How it works:

1. Your project lives in GitHub.
2. `wp-core-base` is used as a base dependency or tooling dependency.
3. Update automation opens pull requests in GitHub.
4. After merge, your deployment workflow sends the code to the server.

This is the most integrated model.

## Model 2: GitHub As Source Of Truth, But Deployment Still Uses FTP Or SFTP

Use this when:

- your host still expects FTP or SFTP
- you want GitHub-based review and versioning without changing hosting immediately
- you want automated update pull requests but do not want to redesign deployment yet

How it works:

1. Your source code lives in GitHub.
2. The automation opens pull requests in GitHub.
3. You review and merge the changes there.
4. The merged code is then deployed to the server by FTP, SFTP, or another file-transfer step.

That deployment can happen:

- manually from a local workstation
- from a CI job
- from another release process your team already uses

This is often the best bridge for teams moving away from live-only or FTP-only workflows.

## Model 3: GitHub As Source Of Truth, Manual Deployment From Local Development

Use this when:

- your team is small
- you want versioning and pull requests first
- you are not ready to automate deployment yet

How it works:

1. Develop and review changes through GitHub.
2. After merge, pull the approved code locally.
3. Deploy the approved code to hosting manually.

This is simpler operationally, but it relies more on human process.

## Model 4: No GitHub, Manual Update Management

Use this when:

- you want the base code and release structure
- you are not using GitHub
- you are not yet ready for pull-request-based automation

How it works:

1. Use `wp-core-base` as a versioned WordPress baseline.
2. Pull or copy released versions manually.
3. Review and deploy changes with your own process.

This works, but you do not get the automated PR workflow because that part is GitHub-specific.

## FTP-Based Teams: What Changes And What Does Not

If your current deployment model is FTP-based, `wp-core-base` changes the source workflow more than the deployment workflow.

What changes:

- you keep WordPress code in Git
- you review updates as code changes
- you can use GitHub PRs if you move the repository to GitHub

What does not have to change immediately:

- your hosting provider
- your server layout
- the fact that files may still reach the server through FTP or SFTP

That makes `wp-core-base` a practical fit for gradual modernization.

## Local Development In Every Model

Local development is compatible with all of the above.

The normal flow is:

1. clone the project locally
2. run WordPress in your preferred local stack
3. make and test changes locally
4. commit the changes into Git
5. push them if the project uses GitHub

The local workflow does not depend on whether deployment later happens by CI, FTP, or manual upload.

## Recommendation Matrix

Good defaults:

- beginner team starting fresh: GitHub source of truth, manual deployment at first
- team with existing FTP hosting: GitHub source of truth plus FTP deployment
- mature engineering team: GitHub source of truth plus CI/CD deployment
- team not ready for GitHub: manual use of the base without PR automation

## What To Read Next

- First-time adoption: [getting-started.md](getting-started.md)
- Advanced dependency setup: [downstream-usage.md](downstream-usage.md)
