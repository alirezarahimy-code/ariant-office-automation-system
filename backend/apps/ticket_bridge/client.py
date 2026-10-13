"""واکشی فقط‌خواندنی تیکت‌ها از سامانه PHP.

دو منبع:
- MySQLTicketSource: SELECT فقط‌خواندنی از DB زنده (نیازمند تنظیم TICKET_DB_*)
- FixtureTicketSource: فایل نمونه برای آزمون/نمایش تا زمان فراهم‌شدن دسترسی DB
"""
from __future__ import annotations

import json
import pathlib

import jdatetime
from django.conf import settings

from apps.core.persian import to_persian_digits


class BridgeNotConfigured(RuntimeError):
    """اتصال DB تیکتینگ پیکربندی نشده است."""


class FixtureTicketSource:
    def __init__(self, path: str | None = None):
        self.path = pathlib.Path(path or settings.TICKET_BRIDGE['fixture'])

    def fetch(self, usernames: list[str]) -> list[dict]:
        if not self.path.exists():
            return []
        rows = json.loads(self.path.read_text(encoding='utf-8'))
        wanted = set(usernames)
        return [row for row in rows if row.get('requester_username') in wanted]


class MySQLTicketSource:
    """فقط SELECT — هرگز نوشتن روی پایگاه‌داده سامانه مبدأ."""

    QUERY = (
        'SELECT t.id, t.ticket_no, t.subject, t.status, t.priority, t.service_group, '
        'u.username, DATE(t.created_at) AS created '
        'FROM tickets t JOIN users u ON u.id = t.requester_id '
        'WHERE u.username IN ({placeholders}) ORDER BY t.id DESC LIMIT 2000'
    )

    def fetch(self, usernames: list[str]) -> list[dict]:
        import pymysql
        conf = settings.TICKET_BRIDGE['db']
        if not conf.get('host') or not usernames:
            raise BridgeNotConfigured('میزبان پایگاه‌داده تیکتینگ تنظیم نشده است.')
        connection = pymysql.connect(
            host=conf['host'], port=int(conf.get('port', 3306)),
            user=conf['user'], password=conf['password'], database=conf['name'],
            charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=5, read_timeout=10)
        try:
            with connection.cursor() as cursor:
                placeholders = ', '.join(['%s'] * len(usernames))
                cursor.execute(self.QUERY.format(placeholders=placeholders), list(usernames))
                rows = cursor.fetchall()
        finally:
            connection.close()
        out = []
        for row in rows:
            created = row.get('created')
            label = ''
            if created:
                jdate = jdatetime.date.fromgregorian(date=created)
                label = to_persian_digits(jdate.strftime('%Y/%m/%d'))
            out.append({
                'system': settings.TICKET_BRIDGE['system'],
                'external_id': str(row.get('ticket_no') or row['id']),
                'subject': row['subject'],
                'status': row.get('status', ''),
                'priority': row.get('priority', ''),
                'service_group': row.get('service_group', ''),
                'requester_username': row['username'],
                'created_label': label,
                'payload': {'id': row['id']},
            })
        return out


def get_source():
    conf = settings.TICKET_BRIDGE
    if conf['db'].get('host'):
        return MySQLTicketSource()
    return FixtureTicketSource()


def sync_tickets(usernames: list[str]) -> dict:
    """واکشی و upsert آینه تیکت‌ها؛ گزارش شمارش‌ها."""
    from .models import ExternalTicket
    source = get_source()
    rows = source.fetch(usernames)
    created = updated = 0
    fields = ('subject', 'status', 'priority', 'service_group',
              'requester_username', 'created_label', 'payload')
    for row in rows:
        _, is_created = ExternalTicket.objects.update_or_create(
            system=row.get('system', settings.TICKET_BRIDGE['system']),
            external_id=row['external_id'],
            defaults={key: row.get(key, '' if key != 'payload' else {}) for key in fields})
        created += int(is_created)
        updated += int(not is_created)
    return {'fetched': len(rows), 'created': created, 'updated': updated}
