"""آزمون بایگانی الکترونیک — فاز ۲ اسپرینت ۲."""
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.management import call_command
from django.test import TestCase
from django.urls import reverse

from apps.archive.models import ArchiveItem, DisposalRequest
from apps.archive.services import (archive_object, decide_disposal,
                                   execute_disposal, ensure_box, ensure_folder,
                                   folder_for_letter, request_disposal)
from apps.secretariat.models import Letter
from apps.secretariat.services import register_letter
from apps.secretariat.tests import make_office

User = get_user_model()


def give_perm(user, app, *codenames):
    for code in codenames:
        user.user_permissions.add(
            Permission.objects.get(codename=code, content_type__app_label=app))
    return user


class ArchiveServiceTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.box = ensure_box()

    def test_folder_codes_are_sequential_and_unique(self):
        f1 = ensure_folder(self.box, 'مکاتبات الف')
        f2 = ensure_folder(self.box, 'مکاتبات ب')
        self.assertEqual(f1.code, 'GEN-001')
        self.assertEqual(f2.code, 'GEN-002')
        self.assertEqual(ensure_folder(self.box, 'مکاتبات الف').pk, f1.pk)

    def test_folder_for_letter_uses_office(self):
        letter = register_letter(self.office, 'incoming', 'نامه بایگانی')
        folder = folder_for_letter(letter)
        self.assertIn(self.office.name, folder.title)

    def test_archive_closed_letter_flips_status_and_logs(self):
        letter = register_letter(self.office, 'incoming', 'نامه بسته')
        letter.status = Letter.Status.CLOSED
        letter.save(update_fields=['status'])
        folder = folder_for_letter(letter)
        item, created = archive_object(letter, folder)
        self.assertTrue(created)
        letter.refresh_from_db()
        self.assertEqual(letter.status, Letter.Status.ARCHIVED)
        self.assertEqual(item.reference, f'{letter.jalali_year}/و/{letter.serial:04d}')
        self.assertTrue(letter.events.filter(action='archived').exists())
        # تکرار → مورد تازه ساخته نمی‌شود
        _, created_again = archive_object(letter, folder)
        self.assertFalse(created_again)

    def test_disposal_cycle_requires_approval(self):
        folder = ensure_folder(self.box, 'پوشه امحایی')
        boss = give_perm(User.objects.create_user('boss', password='x'),
                         'archive', 'approve_disposal')
        req = request_disposal(folder, boss, 'پایان دوره نگهداری')
        self.assertIsNotNone(req)
        # درخواست دوباره در حالت باز → None
        self.assertIsNone(request_disposal(folder, boss, 'دوباره'))
        # رد → پوشه فعال می‌ماند
        decide_disposal(req, boss, approve=False, note='فعلاً نه')
        req.refresh_from_db()
        folder.refresh_from_db()
        self.assertEqual(req.status, DisposalRequest.Status.REJECTED)
        self.assertTrue(folder.is_active)
        # چرخه تأیید + اجرا
        req2 = request_disposal(folder, boss, 'پایان دوره نگهداری — بار دوم')
        decide_disposal(req2, boss, approve=True)
        req2.refresh_from_db()
        self.assertEqual(req2.status, DisposalRequest.Status.APPROVED)
        execute_disposal(req2)
        req2.refresh_from_db()
        folder.refresh_from_db()
        self.assertEqual(req2.status, DisposalRequest.Status.EXECUTED)
        self.assertFalse(folder.is_active)


class ArchiveCommandTests(TestCase):
    def test_archive_closed_letters_command(self):
        office = make_office()
        closed = register_letter(office, 'incoming', 'بسته یک')
        closed.status = Letter.Status.CLOSED
        closed.save(update_fields=['status'])
        register_letter(office, 'incoming', 'باز — بایگانی نشود')
        call_command('archive_closed_letters')
        self.assertEqual(ArchiveItem.objects.count(), 1)
        closed.refresh_from_db()
        self.assertEqual(closed.status, Letter.Status.ARCHIVED)
        call_command('archive_closed_letters')  # idempotent
        self.assertEqual(ArchiveItem.objects.count(), 1)


class ArchiveViewTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.letter = register_letter(self.office, 'incoming', 'نامه نما')
        self.letter.status = Letter.Status.CLOSED
        self.letter.save(update_fields=['status'])
        self.manager = give_perm(
            User.objects.create_user('arch', password='x'), 'archive',
            'view_archive', 'manage_archive')
        give_perm(self.manager, 'secretariat', 'view_all_letter')
        self.boss = give_perm(User.objects.create_user('boss2', password='x'),
                              'archive', 'view_archive', 'approve_disposal')

    def test_pages_render_for_permitted_user(self):
        self.client.force_login(self.manager)
        response = self.client.get(reverse('archive:list'))
        self.assertContains(response, 'بایگانی الکترونیک')
        call_command('archive_closed_letters')
        folder = ArchiveItem.objects.first().folder
        response = self.client.get(reverse('archive:box_detail', args=[folder.box.pk]))
        self.assertContains(response, folder.code)
        response = self.client.get(reverse('archive:folder_detail', args=[folder.pk]))
        self.assertContains(response, 'نامه نما')

    def test_view_gated_without_permission(self):
        plain = User.objects.create_user('plain2', password='x')
        self.client.force_login(plain)
        response = self.client.get(reverse('archive:list'), follow=True)
        self.assertContains(response, 'مجوز مشاهده بایگانی را ندارید')

    def test_archive_letter_post_requires_manage(self):
        viewer = give_perm(User.objects.create_user('viewer', password='x'),
                           'archive', 'view_archive')
        give_perm(viewer, 'secretariat', 'view_all_letter')
        self.client.force_login(viewer)
        response = self.client.post(
            reverse('archive:archive_letter', args=[self.letter.pk]), follow=True)
        self.assertContains(response, 'مجوز مدیریت بایگانی را ندارید')
        self.assertEqual(ArchiveItem.objects.count(), 0)

    def test_archive_letter_post_then_search(self):
        self.client.force_login(self.manager)
        self.client.post(reverse('archive:archive_letter', args=[self.letter.pk]))
        item = ArchiveItem.objects.first()
        self.assertIsNotNone(item)
        response = self.client.get(reverse('archive:list'), {'q': item.reference})
        self.assertContains(response, 'نامه نما')

    def test_disposal_flow_via_views(self):
        self.client.force_login(self.manager)
        self.client.post(reverse('archive:archive_letter', args=[self.letter.pk]))
        folder = ArchiveItem.objects.first().folder
        self.client.post(reverse('archive:disposal_request', args=[folder.pk]),
                         {'reason': 'پایان دوره نگهداری طبق جدول'})
        req = DisposalRequest.objects.get(folder=folder)
        self.client.force_login(self.boss)
        self.client.post(reverse('archive:disposal_decide', args=[req.pk]),
                         {'approve': '1', 'note': 'بلامانع است'})
        req.refresh_from_db()
        self.assertEqual(req.status, DisposalRequest.Status.APPROVED)
        self.client.force_login(self.manager)
        self.client.post(reverse('archive:disposal_execute', args=[req.pk]))
        folder.refresh_from_db()
        self.assertFalse(folder.is_active)


class GroupSeedTests(TestCase):
    def test_groups_include_archive_permissions(self):
        call_command('setup_default_groups')
        from django.contrib.auth.models import Group
        dabir = Group.objects.get(name='دبیر')
        self.assertTrue(dabir.permissions.filter(codename='manage_archive').exists())
        boss = Group.objects.get(name='مدیر ارشد')
        self.assertTrue(boss.permissions.filter(codename='approve_disposal').exists())
