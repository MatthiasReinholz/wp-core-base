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
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1

      - name: Set up PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240
        with:
          php-version: '8.3'
          coverage: none

      - name: Run doctor
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          GITHUB_API_URL: ${{ github.api_url }}
          WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON: ${{ secrets.WP_CORE_BASE_PREMIUM_CREDENTIALS_JSON }}
        run: __WPORG_DOCTOR_COMMAND__

      - name: Stage runtime payload
        run: __WPORG_STAGE_RUNTIME_COMMAND__
