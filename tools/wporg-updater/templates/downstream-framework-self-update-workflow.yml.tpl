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
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1
        with:
          fetch-depth: 0

      - name: Set up PHP
        uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962
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
