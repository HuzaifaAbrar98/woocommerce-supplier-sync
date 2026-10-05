# Deployment Checklist

1. Confirm PHP FTP extension is enabled.
2. Confirm the local CSV directory exists and is writable.
3. Confirm the WordPress timezone is set correctly.
4. Confirm the supplier connection works using **Supplier Sync > Dashboard > Test FTP Connection**.
5. Run a manual sync during a low-traffic period.
6. Review Last Sync statistics.
7. Configure a real server cron or WP-CLI trigger for reliable 8:30 AM execution.

The plugin intentionally skips:
- Blank SKUs
- SKUs not found on the website
- Invalid price values
- Invalid stock values

It updates only existing products and saves only when price or stock changes.
