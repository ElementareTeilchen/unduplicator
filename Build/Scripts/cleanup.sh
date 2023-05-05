#!/bin/bash

# convenience script for cleaning up after running test suite locally

composer config --unset platform.php
composer config --unset platform

rm -rf .Build
rm -f composer.lock
rm -f Build/testing-docker/.env
