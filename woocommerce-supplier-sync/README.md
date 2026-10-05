# WooCommerce Supplier Sync v0.3.0

## Added in this phase

- Server-side FTP/FTPS connection.
- FTP connection test button.
- Manual "Run Sync Now" button.
- Daily scheduled run at 8:30 AM according to the WordPress website timezone.
- Temporary CSV download using a `.part` file before finalizing.
- Existing WooCommerce SKU lookup loaded once into memory.
- Unknown SKUs are skipped immediately without loading a WooCommerce product.
- Only changed regular price and stock values are saved.
- Last-run statistics displayed in the WordPress dashboard.

## Required settings

- Connection Type: FTP or FTPS
- Host
- Port, normally 21 for FTP
- Username
- Password
- Remote CSV Path: `/Products.csv`
- Local CSV Storage Path: absolute writable server directory

## Important behavior

The current worker updates the WooCommerce regular price using
`Price`.

It enables stock management and updates stock quantity/status using
`1stCheapestDistributorStock`.

Before production use, test with a small controlled dataset and confirm
the supplier price should replace the regular price rather than another
custom pricing field.

## Server requirements

- PHP 8.1+
- WooCommerce active
- PHP FTP extension enabled
- Writable local CSV directory
- WordPress cron or a real server cron triggering WordPress scheduled events

For a 55k+ product store, a real server cron/WP-CLI execution is recommended
instead of relying only on visitor-triggered WP-Cron.

## Optional Skip-SKU CSV

You can configure a second small CSV on the supplier FTP server containing SKUs that must not be updated by the main supplier CSV. Set **Skip-SKU CSV Remote Path** in Settings, for example:

```text
/SkipSKUs.csv
```

The file must contain a `SKU` header. Each SKU listed in that file is skipped before the WooCommerce product is loaded, so its price and stock are not changed by the sync. The feature is disabled when the setting is blank.

Example:

```csv
SKU
ABC-1001
ABC-1002
ABC-1003
```

The exclusion file is downloaded separately and stored locally as `supplier-skip-skus.csv`. During a resumed sync, the exact exclusion list from the original run is reused from the checkpoint. If a valid `supplier-skip-skus.csv` already exists locally (for example, uploaded from the dashboard), it takes precedence over the FTP exclusion path so the main supplier CSV can still be downloaded from FTP while exclusions come from the dashboard upload.


## v0.8.3 dashboard uploads

The Supplier Sync dashboard can upload both local CSV files without FTP:

- Main supplier file: `supplier-latest.csv`
- Optional exclusion file: `supplier-skip-skus.csv`

After uploading, use **Process Existing Local CSV**. The main file is validated for the required supplier headers before replacing the existing local file. The Skip-SKU file is validated for a `SKU` header.
