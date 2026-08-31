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
        uses: actions/checkout@de0fac2e4500dabe0009e67214ff5f5447ce83dd

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
