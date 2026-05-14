# Storage Setup

Nucleus has two storage surfaces:

- Project resources use `resource_files` and `StorageManager`.
- Drive Storage uses `drive_files` / `drive_folders` and FTP helpers.

Project resources:

1. Set `FILE_STORAGE_DRIVER=local` for local disk or `ftp` for FTP.
2. For local storage, set `STORAGE_LOCAL_ROOT=storage/resources`.
3. For FTP storage, set `FTP_HOST`, `FTP_PORT`, `FTP_USERNAME`, `FTP_PASSWORD`, and `FTP_ROOT`.
4. Set upload and quota limits with `UPLOAD_MAX_BYTES`, `RESOURCE_PROJECT_QUOTA_BYTES`, `ADMIN_QUOTA_BYTES`, and `HANDLER_QUOTA_BYTES`.

Drive Storage:

1. Configure FTP credentials.
2. Admin and handler users can create folders, upload, download, rename, and delete their own files.
3. Visitor users are blocked from Drive Storage actions.

Security controls:

- PHP-like and script extensions are blocked on upload.
- Stored paths are normalized to prevent traversal.
- Downloads go through authenticated handlers.
- `storage/.htaccess` denies direct web access.
