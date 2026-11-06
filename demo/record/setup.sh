#!/usr/bin/env bash
# Resets the sandbox to the state the recording starts from.
# Run from the project root: demo/record/setup.sh
set -eu
ddev drush sql:drop --yes
ddev drush site:install standard --yes --account-name=admin --account-pass=admin --site-name="Canvas demo"
ddev drush pm:enable canvas canvas_pattern_components canvas_demo --yes
# The status report would add a security-updates error to the admin screens.
ddev drush pm:uninstall update --yes
ddev drush cache:rebuild
ddev drush php:script web/modules/custom/canvas_demo/record/baseline.php
ddev drush cache:rebuild
ddev drush sql:dump --gzip --result-file=/var/www/html/.ddev/db_snapshots/baseline.sql
