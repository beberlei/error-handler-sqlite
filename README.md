# Storing Errors in SQLite database

This error handler stores all errors in an SQLite database.

Using WAL-mode concurrent writes are possible with SQLite so requests do not block each other.

Errors can then be queried and grouped so that they can be evaluated by impact and fixed in batch.
