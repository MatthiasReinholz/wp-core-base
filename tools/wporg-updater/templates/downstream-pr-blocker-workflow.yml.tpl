name: wp-core-base PR Blocker

on:
  pull_request_target:
    types:
      - opened
      - reopened
      - synchronize
      - edited
      - ready_for_review
  workflow_dispatch:
    inputs:
      pull_request_number:
        description: Optional pull request number to re-evaluate.
        required: false
        type: string
  schedule:
    - cron: '11 */8 * * *'

permissions:
  contents: read
  pull-requests: read
  issues: read

jobs:
  blocker:
    if: ${{ github.event_name == 'pull_request_target' }}
    runs-on: ubuntu-latest
    steps:
      - name: Check out repository
        uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683
        with:
          ref: ${{ github.event.pull_request.base.ref }}

      - name: Set up PHP
        uses: shivammathur/setup-php@accd6127cb78bee3e8082180cb391013d204ef9f
        with:
          php-version: '8.3'
          coverage: none

      - name: Evaluate blocker state
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
        run: __WPORG_BLOCKER_COMMAND__

  blocker-reconcile:
    if: ${{ github.event_name != 'pull_request_target' }}
    runs-on: ubuntu-latest
    steps:
      - name: Check out repository
        uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683
        with:
          fetch-depth: 0

      - name: Set up PHP
        uses: shivammathur/setup-php@accd6127cb78bee3e8082180cb391013d204ef9f
        with:
          php-version: '8.3'
          coverage: none

      - name: Evaluate blocker reconciliation scan
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
          PR_NUMBER: ${{ inputs.pull_request_number }}
        run: |
          if [ "${{ github.event_name }}" = "workflow_dispatch" ] && [ -n "${PR_NUMBER}" ]; then
            __WPORG_PHP_PATH__ pr-blocker --repo-root=. --pr-number="${PR_NUMBER}" --json
          else
            __WPORG_PHP_PATH__ pr-blocker-reconcile --repo-root=. --json
          fi
