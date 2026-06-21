"""Source connectors. Add new integrations (Shopify, Odoo, etc.) here."""

from .woocommerce import WooCommerceClient

__all__ = ["WooCommerceClient"]
