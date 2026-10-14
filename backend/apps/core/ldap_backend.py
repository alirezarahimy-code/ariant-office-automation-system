"""احراز هویت LDAP/Active Directory — پشت پرچم AUTH_LDAP_ENABLED.

با python-ldap کار می‌کند (بسته سیستمی لازم دارد؛ در docs/17 آمده).
وقتی پرچم خاموش است یا کتابخانه نیست، این بک‌اند بی‌صدا None می‌دهد
و ورود محلی Django دست‌نخورده می‌ماند.
"""
from __future__ import annotations

import logging

from django.conf import settings
from django.contrib.auth import get_user_model
from django.contrib.auth.backends import ModelBackend

logger = logging.getLogger(__name__)


class LDAPAuthBackend(ModelBackend):
    """بک‌اند ورود با دایرکتوری سازمانی — ورودی محلی را مختل نمی‌کند."""

    def authenticate(self, request, username=None, password=None, **kwargs):
        if not getattr(settings, 'AUTH_LDAP_ENABLED', False):
            return None
        if not username or not password:
            return None
        try:
            import ldap  # noqa: PLC0415 — واردسازی تنبل عمداً
        except ImportError:
            logger.warning('python-ldap نصب نیست — ورود LDAP نادیده گرفته شد.')
            return None

        uri = getattr(settings, 'LDAP_URI', '')
        base_dn = getattr(settings, 'LDAP_BASE_DN', '')
        user_dn_tpl = getattr(settings, 'LDAP_USER_DN', 'uid={username},ou=people,{base}')
        try:
            conn = ldap.initialize(uri)
            conn.set_option(ldap.OPT_PROTOCOL_VERSION, 3)
            user_dn = user_dn_tpl.format(username=username, base=base_dn)
            conn.simple_bind_s(user_dn, password)
            conn.unbind_s()
        except Exception:  # noqa: BLE001 — هر شکستی = ورود محلی
            logger.info('ورود LDAP ناموفق برای %s', username)
            return None

        User = get_user_model()
        user, _ = User.objects.get_or_create(
            username=username, defaults={'is_active': True})
        return user
