# WooCommerce QR Checkout

QR code checkout functionality for WooCommerce products.

## What it does

Generates QR codes that link to checkout with products auto-added to cart. Useful for physical product displays, printed materials, etc.

## Features

- Generate QR codes for any WooCommerce product
- Scan QR → product added to cart → checkout page
- Bulk QR generation for all products
- Coupon code generator (ABC-1234-XYZ format)
- Download QR codes as SVG
- Product metabox showing QR code
- Central management page

## Installation

1. Upload to `/wp-content/plugins/woocommerce-qr-checkout/`
2. Activate through WordPress admin
3. Go to "QR Checkout" menu to manage codes

## Usage

### Generate QR Codes
- Go to WooCommerce → QR Checkout
- Click "Generate QR Codes for All Products"
- Or click "Generate QR" for individual products

### Access QR Manager
Individual product QR codes can be managed from:
- Product edit page (metabox on right side)
- QR Checkout → click "Manage" button

### How it works
QR codes contain checkout URL with SKU parameter:
```
https://yoursite.com/checkout/?sku=PRODUCT_SKU
```

When scanned, the plugin:
1. Detects SKU parameter on checkout page
2. Clears cart
3. Adds product to cart
4. Shows checkout page

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- Products must have SKU set

## Notes

This was split out from the MP3 Music Player Extended plugin. Old QR codes from that plugin will still work and can be managed here.

The QR code table in the MP3 plugin now links to this plugin's management pages.

## Storage

QR codes saved as SVG in:
```
/wp-content/uploads/sonaar-extended/
```

## Changelog

### 1.0.0
- Initial release
- Extracted from MP3 Music Player Extended plugin
- Added pagination and sorting to manager page
- Added bulk generation
- Individual regenerate buttons
