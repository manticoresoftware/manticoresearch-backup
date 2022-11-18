# Manticore Backup

## How to use

Read the [official documentation](https://manual.manticoresearch.com/dev/Securing_and_compacting_an_index/Backup_and_restore) for all the information about using the tool.

## Developer documentation

## Structure

| Folder | Description |
|-|-|
| bin | This directory contains various binaries to execute and also build script |
| bin/run | This script is used for run the backup script in development and use it for debug purpose |
| bin/build | It buildes and prepare single binary in PHP Phar format to be ready to ship |
| build | This directory is ignored by git but built binary goes there |
| src | All sources code goes here |
| src/lib | Library independed components |
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

We are collecting metrics by default. If you want to disable it, you should run the tool with the flag `--disable-metric`.

We respect privacy. That's why all metrics are anonymous.

Here are all metrics that we collect.

| Metric | Description |
|-|-|
| os_name | Name of the operating system |
| machine_id | The identified of the machine (the content of /etc/machine-id in Linux)
| arg_* | Usage of arguments that you pass to the script on backing up your data |
| backup_store_versions_fails | Indicates that we failed to store current versions of manticore when backing up |
| backup_table_size | Single table size |
| backup_no_permissions | Failed to backup due to no permissions to destination dir |
| backup_total_size | The total size of full backup |
| backup_time | How long did it take to backup |
| restore_searchd_running | Failed to run restoring process due to searchd being running already |
| restore_no_config_file | No config file in original backup when we try to restore |
| restore_time | How long did it take to restore |
| fscync_time | Timings for sync command |
| restore_target_exists | It occurs when we have a folder or index in the destination folder to restore to |
| terminations | In case of the process was terminated |
| signal_* | What signal was used to terminate the process |
| tables | How many tables manticore holds |
| config_unreachable | Passed config does not exist |
| config_data_dir_missing | Failed to parse data_dir from a passed config file |
| config_data_dir_is_relative | data_dir parameter in the config of the manticore is relative |
