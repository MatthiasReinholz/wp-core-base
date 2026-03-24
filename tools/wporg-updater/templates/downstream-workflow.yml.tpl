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
  group: wp-core-base-updates
  cancel-in-progress: false

jobs:
  sync:
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

      - name: Configure Git identity
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

      - name: Run updater
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
          WPORG_REPO_ROOT: ${{ github.workspace }}
        run: __WPORG_SYNC_COMMAND__
