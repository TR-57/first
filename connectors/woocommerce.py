"""A small, dependency-light WooCommerce REST API client.

Docs: https://woocommerce.github.io/woocommerce-rest-api-docs/
Authentication uses the Consumer Key / Consumer Secret over HTTPS Basic Auth.
"""
from __future__ import annotations

from typing import Any, Dict, Iterator, List, Optional

import requests

from config import WooCommerceConfig


class WooCommerceError(RuntimeError):
    """Raised when the WooCommerce API returns an error response."""


class WooCommerceClient:
    def __init__(self, config: Optional[WooCommerceConfig] = None):
        self.config = config or WooCommerceConfig.from_env()
        self._session = requests.Session()
        self._session.auth = (
            self.config.consumer_key,
            self.config.consumer_secret,
        )
        self._session.headers.update({"Accept": "application/json"})

    @property
    def base_url(self) -> str:
        return f"{self.config.url}/wp-json/{self.config.api_version}"

    # --- low-level request ------------------------------------------------
    def request(
        self,
        method: str,
        endpoint: str,
        params: Optional[Dict[str, Any]] = None,
        json: Optional[Dict[str, Any]] = None,
    ) -> Any:
        url = f"{self.base_url}/{endpoint.lstrip('/')}"
        resp = self._session.request(
            method,
            url,
            params=params,
            json=json,
            timeout=self.config.timeout,
        )
        if not resp.ok:
            raise WooCommerceError(
                f"{method} {url} -> {resp.status_code}: {resp.text[:500]}"
            )
        if resp.content:
            return resp.json()
        return None

    def get(self, endpoint: str, **params: Any) -> Any:
        return self.request("GET", endpoint, params=params or None)

    def post(self, endpoint: str, data: Dict[str, Any]) -> Any:
        return self.request("POST", endpoint, json=data)

    def put(self, endpoint: str, data: Dict[str, Any]) -> Any:
        return self.request("PUT", endpoint, json=data)

    # --- pagination helper ------------------------------------------------
    def paginate(self, endpoint: str, per_page: int = 100, **params: Any) -> Iterator[Dict[str, Any]]:
        """Yield every record across all pages for a list endpoint."""
        page = 1
        while True:
            batch: List[Dict[str, Any]] = self.get(
                endpoint, per_page=per_page, page=page, **params
            )
            if not batch:
                break
            for item in batch:
                yield item
            if len(batch) < per_page:
                break
            page += 1

    # --- convenience methods ---------------------------------------------
    def ping(self) -> Dict[str, Any]:
        """Lightweight connectivity/auth check. Returns store environment info."""
        return self.get("system_status")

    def products(self, **params: Any) -> List[Dict[str, Any]]:
        return self.get("products", **params)

    def orders(self, **params: Any) -> List[Dict[str, Any]]:
        return self.get("orders", **params)

    def customers(self, **params: Any) -> List[Dict[str, Any]]:
        return self.get("customers", **params)
