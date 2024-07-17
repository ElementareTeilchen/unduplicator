# unduplicator
Finds and fixes duplicates of sys_file entries pointing to the same file. Merges all references to point to the remaining sys_file entry.

Tested successfully with TYPO3 v12.

## Warning
Older versions for TYPO3 v8 may not consider identifiers with mixed case or sys_file
entries on several storages (sys_file.storage) correctly, see issue https://github.com/ElementareTeilchen/unduplicator/issues/2

## Usage
We strongly recommend to run the **reference index update** (before and after):
If not run before or the references are out of date, some references may be overlooked and a sys_file entry deleted which has references.
Additionally you should consider to run the **scheduler task "File Abstraction Layer: Update storage index"** (before and after):
This makes sense to recalculate the hash or other information. Also, the scheduler may create further duplicates. (If we do not run it now and the files are indexed later, we have new duplicates which are not taken care of).
You can run the scheduler task in the scheduler backend module or via CLI, see ` vendor/bin/typo3 help scheduler:run` for details.

Use the following commands:

```
php vendor/bin/typo3 referenceindex:update
# create the beforementioned scheduler task and use the UID in the next command
#php vendor/bin/typo3 scheduler:run --task=<UID>

# dry-run (just show found duplicates, does not update database):
php vendor/bin/typo3 unduplicate:sysfile --dry-run
# for real
php vendor/bin/typo3 unduplicate:sysfile

php vendor/bin/typo3 referenceindex:update
```
> **Note**
> For non-composer-mode the path to the CLI script is `typo3/sysext/core/bin/typo3`
>
>
If you want to run the unduplicator only for a specific storage or even identifier (found by dry-run):

```
php vendor/bin/typo3 unduplicate:sysfile --storage <storage> --identifier <"identifier">

# For example:
php vendor/bin/typo3 unduplicate:sysfile  --storage 1 --identifier "/user_upload/duplicate.jpg"
```

## How the duplicate check is done

* check if sys_file.storage and sys_file.identifier is the same, but sys_file.uid is different
* we must make sure comparing identifier is done case-sensitively, this may not be the case for DB queries. In order to keep DB queries portable across different DB servers, we do an additional check in PHP.

## Run the functional Tests
```sh
./Build/Scripts/runTests.sh -s functional -d mariadb -p 8.3
```
