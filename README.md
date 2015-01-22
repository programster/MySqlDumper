
This is a PHP Package to make the dumping of MySQL databases incredibly easy.
You can use this class to dump only specified databases by using addDatabase method, but by default it will dump all databases.

By default, this will place everything into a single dump file. However, use the useSeparateFiles() method to have the data split into separate files.

NOTE: This will use "--lock-all-tables" (unless you run useSingleTransaction) which will "Lock all tables across all databases" , which will result in databases that aren't involved still being read locked whilst the dump is taking place.

### Filenames
Whilst splitting into multiple files may be convinient, this simply executes extra steps to "break up" the dump file rather than doing anything clever, so this will always take longer and will be dependent on your CPU.

## Snapshotting/Consistency.
The aim of this class is to enable "snapshotting" across multiple databases so that there is a consistent "image" which is necessary if you have a system spread across databases. If you find that this has too much of an impact on performance and dont need consistency between databases, then simply create multiple of these objects, one for each database group that need to be consistent with each other.


## Importing split dumps
Splitting the dump into multiple files is useful for if you want to view the contents or only import certain tables etc, but you also want to know how to import them all again. The solution is to use cat:
```
cat *.sql | mysql -u [username] -p [database name here]
```

### Limitations:
* This cannot handle databases spread across different hosts.
* This cannot handle cases where the user provided does not have access to all of the databases.

## Example Usage:
```
$dumper = new MySqlDumper('/tmp', 'localhost', 'root', 'hickory2000');
$dumper->useSingleFile('my_dump');
$dumper->run();
```

## Testing
Tested to work on Ubuntu Server 12.04 LTS
