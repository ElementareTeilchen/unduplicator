# unduplicator
Finds and fixes duplicates of sys_file entries pointing to the same file. Merges all references to point to the remaining sys_file entry.  
Testet successfully with TYPO3 8.  
Use the following command to run it:
```
./typo3/sysext/core/bin/typo3 unduplicate:sysfile
```
