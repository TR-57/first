# Store Connectors

A small, extensible Python toolkit for connecting to external store/data APIs.
First integration: **WooCommerce** (via the REST API). Designed so additional
sources (Shopify, Odoo, etc.) can be added under `connectors/` later.

## Setup

```bash
python -m venv .venv
source .venv/bin/activate        # Windows: .venv\Scripts\activate
pip install -r requirements.txt
```

Create your `.env` from the template and fill in your credentials:

```bash
cp .env.example .env
# then edit .env
```

Generate the WooCommerce key in your store admin:
**WooCommerce → Settings → Advanced → REST API → Add key**
(Read permission is enough for read-only use.)

> `.env` is gitignored — your keys are never committed.

## Usage

```bash
python main.py            # test connection + store summary
python main.py products   # list a few products
python main.py orders     # list a few recent orders
```

## Use it in your own code

```python
from connectors import WooCommerceClient

wc = WooCommerceClient()

# Connection / auth check
print(wc.ping()["environment"]["version"])

# Convenience methods
products = wc.products(per_page=10)
orders = wc.orders(status="processing")

# Generic requests for any endpoint
single = wc.get("products/123")
wc.put("products/123", {"regular_price": "19.99"})  # needs a Read/Write key

# Iterate every record across all pages
for order in wc.paginate("orders", status="completed"):
    ...
```

## Project layout

```
config.py              # loads + validates configuration from .env
connectors/
  __init__.py
  woocommerce.py       # WooCommerceClient
main.py                # CLI demo / connection test
.env.example           # template (safe to commit)
.env                   # your real secrets (gitignored)
```

## Notes

- WooCommerce REST API auth uses the Consumer Key/Secret over HTTPS.
- For write operations (create/update products, orders, etc.) generate a
  **Read/Write** key.
