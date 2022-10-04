# Manticore Backup Script

## How to use

First, you must be sure that you run the script on the same machine where you have `searchd` launched.

Second, we recommend running it under the `root` user so the script will transfer ownership of files in that case. Otherwise, the backup will be made but with no ownership transfer. Anyway, you should remember that script must have access to the data dir of the manticore.

You can start with `manticore_backup --config=path/to/manticore.conf --target-dir=backupdir` to backup all tables to `backupdir` and use `path/to/manticore.conf` config of manticore. You can omit the *--config* argument; in that case, the script will find out the config path by using the `searchd --status` call.

If you want to backup only some tables feel free to use the `--tables=table1,table1` flag that will backup only required tables and skip all others.

## Arguments

| Argument | Description | Required |
|-|-|-|
| --target-dir=path | This is the path to the target directory where a backup is stored. The direction must be created. The argument is required to pass, and it has no default value. On each backup run, the script will create a backup-[datetime] directory and copy all required tables to it. So target-dir represents the container of all your backups, and it's safe to run the script multiple times.| + |
| --config=path | Path to manticore config. This is optional and in case if it's not passed we use default one for your platform. It's used to get the host and port to talk with the Manticore daemon. | optional |
| --tables=table1,table1,... | A semicolon-separated list of tables is required to backup. If you want to backup all, just pass skip passing this argument to the script. You cannot give unexisting tables in your database to this argument. | optional |
| --compress | Should we compress our indexers or not. The default – no. We use zstd for compression. | optional |
| --unlock | In case if something went wrong or tables are still in lock state we can run the script with this argument to unlock it all. | command |
| --version | Show the current backup script version. | command |
| --help | Show this help. | command |

## Get more help?

Just run `manticore_backup --help` or `manticore_backup -h` to display full help.

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

## Phylosophy

1. Keep the script as small and lightweight as possible with no/minimum external dependencies.
2. Try to write the code so that we can use it in different OSes (like Windows, Linux, and any other where PHP can be used).
3. We can use external binaries like `rsync` or any others, but we should maintain the native behavior so that we can still use the script when there is no such dependency.
4. Tests should cover every new feature or extension to the script.


## Backup structure

The directory with name `backup-%date%` is created in the  *--target-dir* folder. The target created directory has the following structure:
| Folder | Description |
|-|-|
| data | The path to store all files (tables) from the searchd data dir |
| external | All external files are going here with preserving root paths |
| config | The directory is for saving configs, mainly manticore.json and manticore.conf |
| state | The searchd state files backup dir |
| versions.json | This file contains versions of manticore where current backup was made |

## Building

To build the final single script entrypoint you should to run `bin/build`. The final script can be found in build directory under the `build/manticore_backup`.

We recommend installing [manticore-executor](https://github.com/manticoresoftware/executor), and in that case, the script will use a custom-built PHP binary with all required extensions to run.

The final script is a PHP Phar archive that can be run with [PHP](https://php.net) version of `8.1.11` that contains the next extensions:

- zstd
- Phar
- Posix

## Developing

To develop and run the system without building process you should use `build/run` script that do all the magic.

## Tests

All tests are located in the `test` directory.

We use PHPUnit for testing.

There are two types of tests: unit tests of used components and integrated tests of the whole script behavior.
