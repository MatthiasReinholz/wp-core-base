stages:
  - validate
  - automation

# Required CI/CD variable for GitLab-hosted automation:
# - GITLAB_TOKEN: masked project or group access token with API and write_repository access

.wp_core_base_setup: &wp_core_base_setup
  image: ubuntu:24.04
  before_script:
    - apt-get update
    - DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates git php-cli php-curl php-xml php-zip unzip
    - git config user.name "wp-core-base bot"
    - git config user.email "wp-core-base-bot@users.noreply.gitlab.com"
    - |
      if [ -z "${GITLAB_TOKEN:-}" ]; then
        echo "GITLAB_TOKEN is required for wp-core-base GitLab automation jobs." >&2
        echo "Add it as a masked CI/CD variable with API and write_repository access." >&2
        exit 1
      fi
      git remote set-url origin "https://oauth2:${GITLAB_TOKEN}@${CI_SERVER_HOST}/${CI_PROJECT_PATH}.git"
  variables:
    GITLAB_PROJECT_ID: "$CI_PROJECT_ID"
    WPORG_REPO_ROOT: "$CI_PROJECT_DIR"

wp_core_base_sync:
  stage: automation
  resource_group: wp-core-base-dependency-sync
  extends: .wp_core_base_setup
  rules:
    - if: '$CI_PIPELINE_SOURCE == "schedule"'
    - if: '$CI_PIPELINE_SOURCE == "web"'
    - if: '$CI_PIPELINE_SOURCE == "push" && $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH'
  script:
    - __WPORG_SYNC_COMMAND__ --report-json=.wp-core-base/build/sync-report.json --fail-on-source-errors
    - __WPORG_PHP_PATH__ render-sync-report --repo-root=. --report-json=.wp-core-base/build/sync-report.json --summary-path=.wp-core-base/build/sync-summary.md
    - __WPORG_PHP_PATH__ sync-report-issue --repo-root=. --report-json=.wp-core-base/build/sync-report.json
  artifacts:
    when: always
    paths:
      - .wp-core-base/build/sync-report.json
      - .wp-core-base/build/sync-summary.md

wp_core_base_pr_blocker:
  stage: validate
  extends: .wp_core_base_setup
  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
  script:
    - __WPORG_BLOCKER_COMMAND__ --repo-root=. --json

wp_core_base_pr_blocker_reconcile:
  stage: automation
  extends: .wp_core_base_setup
  rules:
    - if: '$CI_PIPELINE_SOURCE == "schedule"'
    - if: '$CI_PIPELINE_SOURCE == "web"'
  script:
    - __WPORG_PHP_PATH__ pr-blocker-reconcile --repo-root=. --json

__WPORG_VALIDATE_RUNTIME_JOB__

wp_core_base_framework_sync:
  stage: automation
  resource_group: wp-core-base-framework-update
  extends: .wp_core_base_setup
  rules:
    - if: '$CI_PIPELINE_SOURCE == "schedule"'
    - if: '$CI_PIPELINE_SOURCE == "web"'
  script:
    - __WPORG_FRAMEWORK_SYNC_COMMAND__
