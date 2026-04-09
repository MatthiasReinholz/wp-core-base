name: wp-core-base Updates

on:
  schedule:
    - cron: '17 */6 * * *'
  workflow_dispatch:

permissions:
  contents: write
  pull-requests: write
  issues: write

concurrency:
  group: wp-core-base-dependency-sync
  cancel-in-progress: false

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - name: Check out repository
        uses: actions/checkout@de0fac2e4500dabe0009e67214ff5f5447ce83dd
        with:
          fetch-depth: 0

      - name: Set up PHP
        uses: shivammathur/setup-php@accd6127cb78bee3e8082180cb391013d204ef9f
        with:
          php-version: '8.3'
          coverage: none

      - name: Configure Git identity
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

      - name: Run updater
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
          WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON: ${{ secrets.WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON }}
          WPORG_REPO_ROOT: ${{ github.workspace }}
        run: __WPORG_SYNC_COMMAND__ --report-json=.wp-core-base/build/sync-report.json --fail-on-source-errors

      - name: Publish sync summary
        if: ${{ always() }}
        env:
          WPORG_REPO_ROOT: ${{ github.workspace }}
        run: __WPORG_PHP_PATH__ render-sync-report --repo-root=. --report-json=.wp-core-base/build/sync-report.json --summary-path="${GITHUB_STEP_SUMMARY}"

      - name: Sync source-failure issue
        if: ${{ always() }}
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
          WPORG_REPO_ROOT: ${{ github.workspace }}
        run: __WPORG_PHP_PATH__ sync-report-issue --repo-root=. --report-json=.wp-core-base/build/sync-report.json
