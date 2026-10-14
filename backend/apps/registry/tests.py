"""آزمون‌های ثبت بستر — manifest و دروازه دسترسی."""
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.management import call_command
from django.test import TestCase
from django.urls import reverse

from apps.registry.models import Subsystem

User = get_user_model()


def give_perm(user, dotted):
    app, code = dotted.split('.')
    user.user_permissions.add(
        Permission.objects.get(codename=code, content_type__app_label=app))
    return user


class ManifestSeedTests(TestCase):
    def test_seed_creates_11_rows(self):
        call_command('setup_platform_manifest')
        self.assertEqual(Subsystem.objects.count(), 11)

    def test_seed_is_idempotent(self):
        call_command('setup_platform_manifest')
        call_command('setup_platform_manifest')
        self.assertEqual(Subsystem.objects.count(), 11)

    def test_ticketing_is_migrating_with_menu(self):
        call_command('setup_platform_manifest')
        t = Subsystem.objects.get(key='ticketing')
        self.assertEqual(t.status, Subsystem.Status.MIGRATING)
        self.assertEqual(t.app_label, 'tickets')
        self.assertEqual(t.menu_url, '/tickets/')

    def test_planned_rows_at_step_zero(self):
        call_command('setup_platform_manifest')
        planned = Subsystem.objects.filter(status=Subsystem.Status.PLANNED)
        self.assertEqual(planned.count(), 1)
        self.assertTrue(all(s.step == 0 for s in planned))
        native = Subsystem.objects.filter(status=Subsystem.Status.NATIVE)
        self.assertEqual(native.count(), 9)
        self.assertTrue(all(s.step == 3 for s in native))


class PlatformViewTests(TestCase):
    @classmethod
    def setUpTestData(cls):
        call_command('setup_platform_manifest')

    def setUp(self):
        self.plain = User.objects.create_user('plain', password='pass1234!')
        self.manager = give_perm(
            User.objects.create_user('platform-mgr', password='pass1234!'),
            'registry.manage_platform')
        self.dabir = give_perm(
            User.objects.create_user('dabir', password='pass1234!'),
            'secretariat.view_all_letter')

    def test_anonymous_redirected(self):
        resp = self.client.get(reverse('registry:platform'))
        self.assertEqual(resp.status_code, 302)

    def test_plain_user_redirected_to_kartable(self):
        self.client.force_login(self.plain)
        resp = self.client.get(reverse('registry:platform'))
        self.assertEqual(resp.status_code, 302)
        self.assertIn('/kartable/', resp.url)

    def test_platform_manager_sees_manifest(self):
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('registry:platform'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'یک سامانه، چند زیرسامانه')
        self.assertEqual(len(resp.context['subsystems']), 11)

    def test_view_all_letter_user_allowed(self):
        self.client.force_login(self.dabir)
        resp = self.client.get(reverse('registry:platform'))
        self.assertEqual(resp.status_code, 200)
        self.assertFalse(resp.context['can_manage'])
