"""Narrow integration boundary for the vendor-owned T5 license mechanism.

No local key generation, signature bypass, state mutation, or license fallback is
provided. An operator may configure a trusted implementation via the
``T5_LICENSE_CHECKER`` Django setting after obtaining the compatible vendor
service/key workflow.
"""
from dataclasses import dataclass
from typing import Protocol

from django.conf import settings
from django.utils.module_loading import import_string


class LicenseProviderUnavailable(RuntimeError):
    """Raised when no trusted vendor-compatible license provider is configured."""


@dataclass(frozen=True)
class LicenseCheckResult:
    """Structured response from a trusted provider; never inferred from text."""

    accepted: bool
    message: str


class LicenseChecker(Protocol):
    """Provider contract; implementation must use the legitimate vendor flow."""

    def check(self, change_type: str, code: str) -> LicenseCheckResult:
        """Validate/apply a code and return an explicit accepted/rejected result."""
        ...


def configured_license_checker() -> LicenseChecker | None:
    """Return the configured checker instance, or ``None`` when unavailable.

    The configured class must expose ``check(change_type, code)`` and be
    responsible for authenticating and applying a license using the legitimate
    vendor mechanism. Submitted codes are intentionally not persisted here.
    """
    provider_path = getattr(settings, "T5_LICENSE_CHECKER", "")
    if not provider_path:
        return None
    provider_class = import_string(provider_path)
    return provider_class()
