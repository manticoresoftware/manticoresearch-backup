# Release flow

There are two ways how release and/or deploy works.

## Steps

1. You push to the main branch and it's automated deployed to various repositories to use as latest release version with hash commit.
2. You push new tag so it means you want to create release, but the flow of deployment is almost the same as you push to the main.

## Version

You should to update current version in the file `APP_VERSION` before creating the release.

Please, remember that odd numbers are for development and even is for releases.

0.3.1 or 0.5.15 – pre-release or dev version
0.3.0 or 0.5.10 – release version
