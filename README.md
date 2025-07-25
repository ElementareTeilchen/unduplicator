# unduplicator
Finds and fixes duplicates of sys_file entries pointing to the same file. Merges all references to point to the remaining sys_file entry.

Tested successfully with TYPO3 v12.

## Warning

Older versions for TYPO3 v8 may not consider identifiers with mixed case or sys_file
entries on several storages (sys_file.storage) correctly, see issue https://github.com/ElementareTeilchen/unduplicator/issues/2

## Portabilty (database)

In order to test for duplicates, a database command like this is used:

```sql
SELECT COUNT(*), MAX(identifier) AS identifier, storage FROM `sys_file` GROUP BY MD5(identifier), storage HAVING COUNT(*) > 1;
```

Therefore, it is necessary, that the underlying database engines support MAX and MD5. This command was tested with the following:

* MariaDB
* MySQL
* Postgres

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
### Options

| Option | Alias | Description                                                                                                                           |
| --- | --- |---------------------------------------------------------------------------------------------------------------------------------------|
| `--dry-run` | `-d` | Only shows the duplicates, but does not update the database.                                                                          |
| `--identifier` | `-i` | Only consider duplicates with the given identifier.                                                                                   |
| `--storage` | `-s` | Only consider duplicates in the given storage.                                                                                        |
| `--force` | `-f` | Enforce keeping or overwriting of metadata of the master record in case of conflict. Possible values: keep, keep-nonempty, overwrite. |
| `--keep-oldest` | `-o` | Use the oldest record as master instead of the newest.                                                                                |
| `--meta-fields` | `-m` | Check for conflicting metadata fields, see below.                                                                                     |
| `--update-refindex` | `-u` | Update the reference index before the operation.                                                                                      |
| `--no-interaction` | `-n` | Do not ask for the update of the reference index.                                                                                     |

Run another reference index update when you are done:
```
php vendor/bin/typo3 referenceindex:update
```

### Examples

```bash
# run for a specific storage and identifier
php vendor/bin/typo3 unduplicate:sysfile --storage 1 --identifier "/user_upload/duplicate.jpg"

# `--keep-oldest` can be used in conjunction with `--force keep` to keep the oldest entries as they are and delete all others
php vendor/bin/typo3 unduplicate:sysfile -n --force keep --keep-oldest
```

> **Note**
> For non-composer-mode the path to the CLI script is `typo3/sysext/core/bin/typo3`

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
