# Manticore Backup

## How to use

Read the [official documentation](https://manual.manticoresearch.com/Securing_and_compacting_a_table/Backup_and_restore) for all the information about using the tool.

## Developer documentation

## Structure

| Folder | Description |
|-|-|
| bin | This directory contains various binaries to execute and also build script |
| bin/run | This script is used for run the backup script in development and use it for debug purpose |
| build | This directory is ignored by git but built binary goes there |
| src | All sources code goes here |
| src/lib | Library independent components |
| src/lib/func.php | All helper functions that required to use the script are here |
| src/main.php | This is the main entrypoint for starting the logic |
| test | All tests are here |

## Philosophy

1. Keep the tool as small and lightweight as possible with no/minimum external dependencies.
2. Try to write the code so that we can use it in different OSes (Windows, Linux, and any other where PHP can be used).
3. We can use external binaries like `rsync` or any others, but we should maintain the native behavior so that we can still use the script when there is no such dependency.
4. Tests should cover every new feature or extension to the script.

## Backup structure

The directory with name `backup-%date%` is created in the  *--backup-dir* folder. The target created directory has the following structure:
| Folder | Description |
|-|-|
| data | The path to store all files (tables) from the searchd data dir |
| config | The directory is for saving configs, mainly manticore.json and manticore.conf |
| state | The searchd state files backup dir |
| versions.json | This file contains versions of manticore where current backup was made |

## Building

To build the final executable you need to to run `bin/build`. The executable can be found then in the `./build` directory under `build/manticore-backup`.

We recommend using [manticore-executor](https://github.com/manticoresoftware/executor). In this case, the script will use the custom-built PHP binary with all required extensions to run the tool. If you are adding a new functionality which requires a specific PHP module make sure you update [manticore-executor](https://github.com/manticoresoftware/executor) as well.

The final script is a PHP Phar archive that can be run with [PHP](https://php.net) version of `8.1.11` that contains the next extensions:

- zstd
- Phar
- Posix

## Developing

To develop and run the system without building process you should use `bin/run` script that does all the magic.

## Tests

All tests are located in the `test` directory.

We use PHPUnit for testing.

There are two tests: unit tests of used components and integrated tests of the whole script behavior.

## Metrics

The backup tool by default sends your anonymized metrics to Manticore metrics server. It helps maintainers a lot with improving the product. We respect your privacy and you can be sure that the metrics are anonymous and no sensitive info is sent out, but if you still want to disable the telemetry, please make sure you run the tool with the flag `--disable-metric` or use the environment variable `TELEMETRY=0`.

Here are all metrics that we collect:

| Metric | Description |
|-|-|
| collector | 🏷 backup. Means this metric comes from the backup tool |
| os_name | 🏷️ Name of the operating system |
| machine_id | 🏷 Server identifier (the content of `/etc/machine-id` in Linux)
| invocation | Sent when backup was invoked. Boolean |
| failed | Sent in case the backup was failed. Boolean |
| done | Sent when the backup/restore was successful. Boolean |
| arg_* | What arguments you used to run the tool (skipping all your index names etc.) |
| backup_store_versions_fails | Indicates that it failed to save your Manticore version in the backup |
| backup_table_count | Total count of backed up tables |
| backup_no_permissions | Failed to backup due to no permissions to destination dir |
| backup_total_size | Total size of the full backup |
| backup_time | How long it took to backup |
| restore_searchd_running | Failed to run restoring process due to searchd being running already |
| restore_no_config_file | No config file in the backup on restore |
| restore_time | How long it took to restore |
| fsync_time | How long it took to fsync |
| restore_target_exists | It occurs when there's a folder or index in the destination folder to restore to |
| terminations | In case the process was terminated |
| signal_* | What signal was used to terminate the process |
| tables | How many tables Manticore holds |
| config_unreachable | Passed configuration file does not exist |
| config_data_dir_missing | Failed to parse data_dir from the passed configuration file |
| config_data_dir_is_relative | data_dir path in the configuration file of the Manticore instance is relative |
