.PHONY: help doctor doctor-github verify sync-dry-run

help:
	@printf "Available targets:\n"
	@printf "  make doctor         Run local environment and config checks.\n"
	@printf "  make doctor-github  Run doctor with GitHub env requirements enabled.\n"
	@printf "  make verify         Run the updater fixture tests.\n"
	@printf "  make sync-dry-run   Run sync mode without mutating GitHub or Git (requires GitHub env).\n"

doctor:
	php tools/wporg-updater/bin/wporg-updater.php doctor

doctor-github:
	php tools/wporg-updater/bin/wporg-updater.php doctor --github

verify:
	php tools/wporg-updater/tests/run.php

sync-dry-run:
	WPORG_UPDATE_DRY_RUN=1 php tools/wporg-updater/bin/wporg-updater.php sync
