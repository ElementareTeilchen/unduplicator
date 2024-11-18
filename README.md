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

Create the beforementioned scheduler task and use the UID in the next command:
```sh
php vendor/bin/typo3 scheduler:run --task=<UID>
```
Then use the following commands:
```sh
# dry-run:
php vendor/bin/typo3 unduplicate:sysfile --update-refindex --dry-run
# for real
php vendor/bin/typo3 unduplicate:sysfile -n
```
Options:
* `--dry-run` | `-d` only shows the duplicates, but does not update the database.
* `--storage` | `-s` only consider duplicates in the given storage.
* `--identifier` | `-i` only consider duplicates with the given identifier.
* `--meta-fields` | `-m` check for conflicting metadata fields, see below.
* `--no-interaction` | `-n` do not ask for the update of the reference index.
* `--update-refindex` | `-u` update the reference index before the operation.

Run another reference index update when you are done:
```
php vendor/bin/typo3 referenceindex:update
```

Example: if you want to run the unduplicator only for a specific storage or even identifier (found by dry-run):

```
php vendor/bin/typo3 unduplicate:sysfile --storage <storage> --identifier <"identifier">

# For example:
php vendor/bin/typo3 unduplicate:sysfile  --storage 1 --identifier "/user_upload/duplicate.jpg"
```

> **Note**
> For non-composer-mode the path to the CLI script is `typo3/sysext/core/bin/typo3`
>
>

## How the duplicate check is done

* check if sys_file.storage and sys_file.identifier is the same, but sys_file.uid is different
* we must make sure comparing identifier is done case-sensitively, this may not be the case for DB queries. In order to keep DB queries portable across different DB servers, we do an additional check in PHP.


## Check for conflicting metadata
You can specify meta data fields to check for conflicts.
* If a conflict is found, the script will not delete the old record but show a warning.
* If the data is the same or the old has none, the old is deleted.
* If the data in the new is empty, the data from the old will be copied, if present.
```
php vendor/bin/typo3 unduplicate:sysfile --meta-fields "description, caption"
```
If the extension filemetadata is installed, the default check is on description, copyright and caption.
Otherwise only description is checked.

## Run the functional Tests
```sh
composer install
./Build/Scripts/runTests.sh -s functional -d mariadb -p 8.3
```
