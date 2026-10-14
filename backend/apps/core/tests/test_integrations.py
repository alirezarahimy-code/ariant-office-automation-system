"""آزمون‌های یکپارچه‌سازی: کانال‌های بله/ایتا و ورود LDAP."""
import json
from io import BytesIO
from unittest import mock

from django.contrib.auth import authenticate, get_user_model
from django.test import TestCase, override_settings

from apps.core.notify_channels import (ConsoleChatBackend, RestChatBackend,
                                       send_chat)
from apps.core.services import send_notification

User = get_user_model()


class ChatChannelTests(TestCase):
    def test_console_backend_logs(self):
        self.assertTrue(ConsoleChatBackend().send('0912', 'سلام'))

    def test_rest_backend_without_url_is_false(self):
        with override_settings(CHAT_API_URL=''):
            self.assertFalse(RestChatBackend().send('0912', 'سلام'))

    @override_settings(CHAT_API_URL='https://chat.example/api/send',
                       CHAT_API_TOKEN='tok')
    def test_rest_backend_posts_json(self):
        backend = RestChatBackend()
        fake_response = BytesIO(b'ok')
        fake_response.status = 200
        with mock.patch('urllib.request.urlopen',
                        return_value=fake_response) as mocked:
            self.assertTrue(backend.send('0912', 'پیام'))
            request = mocked.call_args[0][0]
            self.assertEqual(request.full_url, 'https://chat.example/api/send')
            payload = json.loads(request.data.decode('utf-8'))
            self.assertEqual(payload['to'], '0912')

    @override_settings(CHAT_API_URL='https://chat.example/api/send')
    def test_rest_backend_network_error_returns_false(self):
        with mock.patch('urllib.request.urlopen',
                        side_effect=OSError('down')):
            self.assertFalse(RestChatBackend().send('0912', 'پیام'))

    def test_send_chat_unknown_backend_false(self):
        with override_settings(CHAT_BALE_BACKEND='does.not.Exist'):
            self.assertFalse(send_chat('CHAT_BALE_BACKEND', '0912', 'متن'))

    def test_send_notification_routes_to_chat(self):
        user = User.objects.create_user('u1', password='x')
        from apps.core.models import UserProfile
        UserProfile.for_user(user)
        user.profile.phone = '0912345'
        user.profile.save()
        with override_settings(
                NOTIFICATIONS_BALE_ENABLED=True,
                CHAT_BALE_BACKEND='apps.core.notify_channels.ConsoleChatBackend'):
            with mock.patch(
                    'apps.core.notify_channels.ConsoleChatBackend.send',
                    return_value=True) as mocked:
                send_notification(user, 'عنوان', 'بدنه')
                mocked.assert_called_once()


class LDAPBackendTests(TestCase):
    def test_disabled_flag_returns_none(self):
        user = authenticate(username='ali', password='secret')
        self.assertIsNone(user)

    @override_settings(AUTH_LDAP_ENABLED=True)
    def test_missing_ldap_library_returns_none(self):
        import sys
        sys.modules.pop('ldap', None)
        with mock.patch.dict(sys.modules, {'ldap': None}):
            # واردسازی ldap با مقدار None شکست می‌خورد → None برمی‌گردد
            user = authenticate(username='ali', password='secret')
        self.assertIsNone(user)

    @override_settings(AUTH_LDAP_ENABLED=True, LDAP_URI='ldap://dc.local',
                       LDAP_BASE_DN='dc=example,dc=local')
    def test_successful_bind_creates_user(self):
        import sys
        fake_ldap = mock.MagicMock()
        fake_ldap.OPT_PROTOCOL_VERSION = 3
        with mock.patch.dict(sys.modules, {'ldap': fake_ldap}):
            user = authenticate(username='ali', password='secret')
        self.assertIsNotNone(user)
        self.assertEqual(user.username, 'ali')
        self.assertTrue(User.objects.filter(username='ali').exists())

    @override_settings(AUTH_LDAP_ENABLED=True, LDAP_URI='ldap://dc.local',
                       LDAP_BASE_DN='dc=example,dc=local')
    def test_failed_bind_returns_none(self):
        import sys
        fake_ldap = mock.MagicMock()
        fake_ldap.OPT_PROTOCOL_VERSION = 3
        fake_ldap.initialize.return_value.simple_bind_s.side_effect = \
            Exception('invalid credentials')
        with mock.patch.dict(sys.modules, {'ldap': fake_ldap}):
            user = authenticate(username='ali', password='wrong')
        self.assertIsNone(user)
