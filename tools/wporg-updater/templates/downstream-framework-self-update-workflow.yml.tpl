# This file belongs in the downstream repository, not in wp-core-base itself.
# It opens scheduled PRs when a newer wp-core-base framework release is available.

name: wp-core-base Self-Update

on:
  workflow_dispatch:
  schedule:
    - cron: '11 6 * * 1'

permissions:
  contents: write
  pull-requests: write
  issues: write

concurrency:
  group: wp-core-base-framework-update
  cancel-in-progress: false

jobs:
  framework-sync:
    runs-on: ubuntu-latest
    steps:
      - name: Check out downstream project
        uses: actions/checkout@de0fac2e4500dabe0009e67214ff5f5447ce83dd
        with:
          fetch-depth: 0

      - name: Set up PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240
        with:
          php-version: '8.3'
          coverage: none

      - name: Configure Git identity
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

      - name: Run framework self-update
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
        run: __WPORG_FRAMEWORK_SYNC_COMMAND__
