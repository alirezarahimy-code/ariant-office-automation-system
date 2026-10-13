"""آزمون پل تیکتینگ: توکن SSO، همگام‌سازی فقط‌خواندنی، نماها و پرش یکپارچه."""
import time

from django.contrib.auth import get_user_model
from django.core.management import call_command
from django.test import TestCase, override_settings
from django.urls import reverse

from apps.ticket_bridge.client import sync_tickets
from apps.ticket_bridge.models import ExternalTicket, IntegrationUserMap
from apps.ticket_bridge.sso import build_sso_token, verify_sso_token

User = get_user_model()
SECRET = 'test-secret-key'


class SsoTokenTests(TestCase):
    def test_round_trip(self):
        token = build_sso_token(42, 'admin', 'it', SECRET, ttl=60)
        payload = verify_sso_token(token, SECRET)
        self.assertIsNotNone(payload)
        self.assertEqual(payload['user_id'], 42)
        self.assertEqual(payload['role'], 'admin')
        self.assertEqual(payload['service_group'], 'it')
        self.assertGreater(payload['exp'], time.time())

    def test_tampered_signature_rejected(self):
        token = build_sso_token(1, 'user', 'it', SECRET)
        body, _, sig = token.partition('.')
        forged = body + '.' + ('A' * len(sig))
        self.assertIsNone(verify_sso_token(forged, SECRET))
        self.assertIsNone(verify_sso_token(token, 'wrong-secret'))

    def test_expired_rejected(self):
        token = build_sso_token(1, 'user', 'it', SECRET, ttl=-10)
        self.assertIsNone(verify_sso_token(token, SECRET))

    def test_malformed_rejected(self):
        self.assertIsNone(verify_sso_token('not-a-token', SECRET))
        self.assertIsNone(verify_sso_token('', SECRET))


class SyncTicketsTests(TestCase):
    def test_sync_from_fixture(self):
        result = sync_tickets(['m.rezaei'])
        self.assertEqual(result['fetched'], 2)
        self.assertEqual(result['created'], 2)
        ticket = ExternalTicket.objects.get(external_id='IT-1042')
        self.assertEqual(ticket.subject, 'قطعی شبکه طبقه دوم')
        self.assertEqual(ticket.status_fa, 'در جریان')
        # اجرای دوم به‌روز می‌کند نه تکرار
        result = sync_tickets(['m.rezaei'])
        self.assertEqual(result['created'], 0)
        self.assertEqual(result['updated'], 2)

    def test_command_with_usernames(self):
        call_command('sync_tickets', '--usernames=s.karimi')
        self.assertEqual(ExternalTicket.objects.count(), 1)
        self.assertEqual(ExternalTicket.objects.get().external_id, 'SP-220')


class BridgeViewTests(TestCase):
    def setUp(self):
        self.user = User.objects.create_user('rezaei', password='x')
        IntegrationUserMap.objects.create(
            platform_user=self.user, external_username='m.rezaei', external_user_id=77)
        sync_tickets(['m.rezaei'])

    def test_my_tickets_page(self):
        self.client.force_login(self.user)
        response = self.client.get(reverse('bridge:my_tickets'))
        self.assertContains(response, 'قطعی شبکه طبقه دوم')
        self.assertContains(response, 'IT-1042')

    def test_kartable_tickets_tab(self):
        self.client.force_login(self.user)
        response = self.client.get(reverse('secretariat:kartable'), {'tab': 'tickets'})
        self.assertEqual(len(list(response.context['tickets'])), 2)

    def test_unmapped_user_sees_nothing(self):
        other = User.objects.create_user('other', password='x')
        self.client.force_login(other)
        response = self.client.get(reverse('bridge:my_tickets'))
        self.assertNotContains(response, 'IT-1042')

    @override_settings(TICKET_BRIDGE={
        'system': 'php-ticketing',
        'base_url': 'https://ticket.example.local',
        'sso_key': SECRET,
        'fixture': '',
        'db': {'host': '', 'port': 3306, 'name': '', 'user': '', 'password': ''},
    })
    def test_jump_redirects_with_valid_token(self):
        self.client.force_login(self.user)
        response = self.client.get(reverse('bridge:jump'))
        self.assertEqual(response.status_code, 302)
        location = response['Location']
        self.assertTrue(location.startswith('https://ticket.example.local/?sso='))
        token = location.split('sso=', 1)[1]
        payload = verify_sso_token(token, SECRET)
        self.assertIsNotNone(payload)
        self.assertEqual(payload['user_id'], 77)  # شناسه بیرونی از نگاشت
        self.assertEqual(payload['role'], 'user')

    @override_settings(TICKET_BRIDGE={
        'system': 'php-ticketing', 'base_url': '', 'sso_key': SECRET,
        'fixture': '', 'db': {'host': '', 'port': 3306, 'name': '', 'user': '', 'password': ''},
    })
    def test_jump_without_base_url_warns(self):
        self.client.force_login(self.user)
        response = self.client.get(reverse('bridge:jump'), follow=True)
        self.assertContains(response, 'تنظیم نشده')
