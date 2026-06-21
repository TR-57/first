"""CLI entry point: verify the WooCommerce connection and print a summary.

Usage:
    python main.py            # connection test + store summary
    python main.py products   # list a few products
    python main.py orders     # list a few recent orders
"""
from __future__ import annotations

import sys

from connectors import WooCommerceClient


def test_connection(client: WooCommerceClient) -> None:
    print(f"Connecting to {client.config.url} ...")
    status = client.ping()
    env = status.get("environment", {}) if isinstance(status, dict) else {}
    print("Connection OK.")
    if env:
        print(f"  WooCommerce version: {env.get('version', 'unknown')}")
        print(f"  WordPress version:   {env.get('wp_version', 'unknown')}")
        print(f"  Home URL:            {env.get('home_url', client.config.url)}")


def show_products(client: WooCommerceClient) -> None:
    products = client.products(per_page=5)
    print(f"\nProducts ({len(products)} shown):")
    for p in products:
        print(f"  #{p['id']:<6} {p['name'][:40]:<40} {p.get('price', '')}")


def show_orders(client: WooCommerceClient) -> None:
    orders = client.orders(per_page=5, orderby="date", order="desc")
    print(f"\nOrders ({len(orders)} shown):")
    for o in orders:
        print(
            f"  #{o['id']:<6} {o.get('status', ''):<12} "
            f"{o.get('total', '')} {o.get('currency', '')}  {o.get('date_created', '')}"
        )


def main() -> int:
    command = sys.argv[1] if len(sys.argv) > 1 else "test"
    client = WooCommerceClient()

    try:
        test_connection(client)
        if command == "products":
            show_products(client)
        elif command == "orders":
            show_orders(client)
    except Exception as exc:  # noqa: BLE001 - surface a friendly message
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
