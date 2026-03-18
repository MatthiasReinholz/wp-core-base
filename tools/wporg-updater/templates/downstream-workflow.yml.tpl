# This file belongs in the downstream repository, not in wp-core-base itself.
# It opens update pull requests in GitHub.
# Deployment is a separate concern and can still happen by FTP, SFTP, or another process.

name: Sync Upstream Base

on:
  workflow_dispatch:
  schedule:
    - cron: '23 5 * * *'

permissions:
  contents: write
  pull-requests: write
  issues: write

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - name: Check out downstream project
        uses: actions/checkout@v4
        with:
          # Remove this line if your downstream repository does not use submodules.
          submodules: recursive
          fetch-depth: 0

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Configure Git identity
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"

      - name: Run upstream updater against downstream repo
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
          WPORG_REPO_ROOT: ${{ github.workspace }}
          # For an initial rollout, you can temporarily add:
          # WPORG_UPDATE_DRY_RUN: 1
        run: __WPORG_SYNC_COMMAND__
