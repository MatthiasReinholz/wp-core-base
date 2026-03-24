name: wp-core-base Runtime Validation

on:
  pull_request:
  workflow_dispatch:

permissions:
  contents: read

jobs:
  validate:
    runs-on: ubuntu-latest
    steps:
      - name: Check out repository
        uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683

      - name: Set up PHP
        uses: shivammathur/setup-php@accd6127cb78bee3e8082180cb391013d204ef9f
        with:
          php-version: '8.3'
          coverage: none

      - name: Run doctor
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
        run: __WPORG_DOCTOR_COMMAND__

      - name: Stage runtime payload
        run: __WPORG_STAGE_RUNTIME_COMMAND__
