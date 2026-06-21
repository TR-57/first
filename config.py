"""Central configuration loaded from environment / .env file."""
from __future__ import annotations

import os
from dataclasses import dataclass

from dotenv import load_dotenv

# Load variables from a local .env file if present (no-op in production envs
# that inject real environment variables).
load_dotenv()


class ConfigError(RuntimeError):
    """Raised when required configuration is missing."""


def _require(name: str) -> str:
    value = os.getenv(name)
    if not value:
        raise ConfigError(
            f"Missing required environment variable: {name}. "
            f"Copy .env.example to .env and fill it in."
        )
    return value


@dataclass(frozen=True)
class WooCommerceConfig:
    url: str
    consumer_key: str
    consumer_secret: str
    api_version: str = "wc/v3"
    timeout: int = 30

    @classmethod
    def from_env(cls) -> "WooCommerceConfig":
        return cls(
            url=_require("WOOCOMMERCE_URL").rstrip("/"),
            consumer_key=_require("WOOCOMMERCE_CONSUMER_KEY"),
            consumer_secret=_require("WOOCOMMERCE_CONSUMER_SECRET"),
            api_version=os.getenv("WOOCOMMERCE_API_VERSION", "wc/v3"),
            timeout=int(os.getenv("HTTP_TIMEOUT", "30")),
        )
