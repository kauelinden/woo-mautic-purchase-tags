# Purchase Tags for WooCommerce and Mautic

Assign Mautic tags to individual WooCommerce products. When a customer pays for an order containing a mapped product, the plugin adds the product tags, the store tag, and a payment-year tag to their Mautic contact. Customers can accumulate tags across stores that share a Mautic instance.

## Installation

1. Activate WooCommerce on a site running WordPress 5.3 or later and PHP 7.4 or later. Download the ZIP from **Code → Download ZIP** in this repository. In WordPress, select **Plugins → Add Plugin → Upload Plugin**, upload the ZIP, and activate the plugin.
2. Enable the REST API and HTTP Basic authentication in Mautic. Use a dedicated account with permission to view, create, and edit contacts. Configure email as a unique contact identifier, particularly if multiple stores share the instance.
3. In **Settings → Purchase Tags**, enter the HTTPS base URL, API username and password. Click **Save connection**, then **Test saved connection**. The test reads contacts. The administrator form requires PHP Sodium and stores the password encrypted with a key derived from the WordPress authentication salt. Re-enter the password if that salt changes.

Alternatively, use the server-managed configuration. If any WCMT constant is defined, the plugin uses the constants as a set. Set the following environment variables on the server, then add these definitions to wp-config.php before the final configuration line. WCMT_URL is the HTTPS URL of the Mautic installation, without the /api suffix. Each store can use its own credentials.

```php
define('WCMT_URL', getenv('WCMT_URL') ?: '');
define('WCMT_USER', getenv('WCMT_USER') ?: '');
define('WCMT_PASSWORD', getenv('WCMT_PASSWORD') ?: '');
```

## Product mappings

Open **Settings → Purchase Tags**. Select a product on the left and use **Link tag** next to a Mautic tag on the right. Both lists support search and pagination. **Product mapping** shows the current associations. **Unlink** removes the association for future orders. **Create a new tag** creates it in Mautic, then shows it in the catalog so you can link it. The API user needs permission to view and create tags. Variations use their parent product’s mappings.

## Configure tags

Under **Settings → Purchase Tags**, enter a store tag such as **Store - Example** and an annual tag pattern such as **Buyers {year}**. Leave either field blank to omit that tag. Existing saved patterns using `{ano}` are also supported.

In the classic product editor, open **Product data → General → Mautic tags**. Enter one or more comma-separated names, such as **Product - Photography Course, Interest - Photography**, and update the product. Variations inherit the parent product’s tags. Orders containing only unmapped products are skipped.

## Try a purchase in staging

Map a product to **Product - Photography**, set the store tag to **Store - Example**, and place a paid order using a test contact. For a payment made in 2026, look for **Product - Photography**, **Store - Example**, and **Buyers 2026** in Mautic. Repeat with an existing contact and check that their earlier tags are still present.

The annual tag uses the payment date in the store’s time zone. A paid renewal order that includes a mapped product also earns the tag for its payment year. A later refund leaves the purchase history in place. Track subscription status and marketing consent separately.

## Queue and troubleshooting

WooCommerce processes requests in the background with Action Scheduler. Keep the store’s queue runner active. To inspect queued work, go to **WooCommerce → Status → Scheduled Actions** and look for the **wc-mautic-purchase-tags** group. The plugin saves a snapshot of the calculated tags on the order and adds a private order note when Mautic confirms the sync.

Network failures and HTTP 408, 429, and 5xx responses can trigger up to four retries after 1, 5, 30, and 120 minutes. If synchronization stops, read the order note, correct the connection or contact details, and select **Mautic: retry pending purchase tags** from the order actions. Confirmed orders are skipped. If two contacts have the same email address, resolve the duplicate in Mautic before retrying.

The plugin sends the billing email to identify the contact and tag names to record the purchase. Restrict system access and choose tag names appropriate for your store. When replacing an existing integration, validate one product in staging before retiring the previous workflow.

## Development

Run `php -l woo-mautic-purchase-tags.php` and `php tests/test.php`. The tests use synthetic orders and HTTP responses. Validate checkout, scheduled actions, and API compatibility against your own staging installation.

When reporting an issue or submitting a pull request, use synthetic examples.

## License

GPL-2.0-or-later. See LICENSE.
