# unduplicator
Finds and fixes duplicates of sys_file entries pointing to the same file. Merges all references to point to the remaining sys_file entry.

Tested successfully with TYPO3 v11.

## Warning

Older versions for TYPO3 v8 may not consider identifiers with mixed case or sys_file
entries on several storages (sys_file.storage) correctly, see issue https://github.com/ElementareTeilchen/unduplicator/issues/2

## Usage

Use the following command to run it:

* Composer php vendor/bin/typo3
* non-Composer php typo3/sysext/core/bin/typo3


With dry-run (to not update database):

```
php vendor/bin/typo3 unduplicate:sysfile --dry-run
```

Make changes:

```
php vendor/bin/typo3 unduplicate:sysfile
```

Run only for specific identifier and storage:

```
php vendor/bin/typo3 unduplicate:sysfile --identifier <"identifier"> --storage <storage>

# For example:
vendor/bin/typo3 unduplicate:sysfile --identifier <"identifier"> --storage <storage>
```
