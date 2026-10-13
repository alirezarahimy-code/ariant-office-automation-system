"""آزمون‌های دبیرخانه: شماره‌گذاری، ثبت، ارجاع، رونوشت، پیوست و مرئی‌پذیری."""
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.exceptions import ValidationError
from django.core.files.uploadedfile import SimpleUploadedFile
from django.db import IntegrityError, transaction
from django.test import TestCase, override_settings
from django.utils import timezone

from apps.core.persian import to_persian_digits
from apps.organization.models import Department, Employee
from apps.secretariat.models import (
    Attachment, Copy, Letter, LetterEvent, NumberSequence, Office, Referral,
)
from apps.secretariat.services import current_jalali_year, register_letter, visible_letters

User = get_user_model()


def make_office(code='HQ', name='دبیرخانه مرکزی', department=None):
    return Office.objects.create(name=name, code=code, department=department)


def give(user, *codenames):
    for code in codenames:
        user.user_permissions.add(
            Permission.objects.get(codename=code, content_type__app_label='secretariat'))
    return user


class NumberingTests(TestCase):
    def setUp(self):
        self.office = make_office()

    def test_serial_increments(self):
        one = register_letter(self.office, Letter.LetterType.INCOMING, 'نامه یک')
        two = register_letter(self.office, Letter.LetterType.INCOMING, 'نامه دو')
        self.assertEqual((one.serial, two.serial), (1, 2))

    def test_series_separate_per_type_and_year(self):
        register_letter(self.office, Letter.LetterType.INCOMING, 'وارده')
        outgoing = register_letter(self.office, Letter.LetterType.OUTGOING, 'صادره')
        self.assertEqual(outgoing.serial, 1)
        last_year = register_letter(
            self.office, Letter.LetterType.INCOMING, 'وارده پارسال',
            jalali_year=current_jalali_year() - 1)
        self.assertEqual(last_year.serial, 1)

    def test_fifty_letters_no_gap_no_duplicate(self):
        for i in range(50):
            register_letter(self.office, Letter.LetterType.INCOMING, f'نامه {i}')
        seq = NumberSequence.objects.get(
            office=self.office, letter_type='incoming', jalali_year=current_jalali_year())
        self.assertEqual(seq.last_serial, 50)
        self.assertEqual(Letter.objects.count(), 50)

    def test_manual_duplicate_number_rejected(self):
        register_letter(self.office, Letter.LetterType.INCOMING, 'اول')
        with self.assertRaises(IntegrityError):
            with transaction.atomic():
                Letter.objects.create(
                    office=self.office, letter_type='incoming',
                    jalali_year=current_jalali_year(), serial=1, subject='تکراری')

    def test_number_display_is_persian(self):
        letter = register_letter(self.office, Letter.LetterType.INCOMING, 'نمایش')
        self.assertEqual(
            letter.number_display, f'{to_persian_digits(letter.jalali_year)}/و/۰۰۰۱')


class RegisterLetterTests(TestCase):
    def setUp(self):
        self.office = make_office()

    def test_incoming_registered_with_event_and_receipt_time(self):
        dabir = User.objects.create_user('dabir', password='x')
        letter = register_letter(
            self.office, Letter.LetterType.INCOMING, 'درخواست همکاری',
            created_by=dabir, priority=Letter.Priority.URGENT)
        self.assertEqual(letter.status, Letter.Status.REGISTERED)
        self.assertIsNotNone(letter.received_at)
        event = letter.events.first()
        self.assertEqual(event.action, LetterEvent.Action.REGISTERED)
        self.assertEqual(event.actor, dabir)
        self.assertEqual(event.to_status, Letter.Status.REGISTERED)

    def test_outgoing_defaults_to_draft_without_receipt(self):
        letter = register_letter(self.office, Letter.LetterType.OUTGOING, 'پیش‌نویس')
        self.assertEqual(letter.status, Letter.Status.DRAFT)
        self.assertIsNone(letter.received_at)


class ReferralTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.letter = register_letter(self.office, Letter.LetterType.INCOMING, 'نامه ارجاعی')
        self.manager = User.objects.create_user('manager', password='x')
        self.expert = User.objects.create_user('expert', password='x')

    def test_chain_parent_child_and_acted(self):
        first = Referral.objects.create(
            letter=self.letter, from_user=self.manager, to_user=self.expert,
            note='بررسی شود', deadline=timezone.now().date())
        child = Referral.objects.create(
            letter=self.letter, parent=first, from_user=self.expert,
            to_user=self.manager, note='انجام و گزارش شد')
        first.status = Referral.Status.ACTED
        first.acted_at = timezone.now()
        first.save()
        first.refresh_from_db()
        self.assertEqual(first.status, Referral.Status.ACTED)
        self.assertEqual(child.parent, first)
        self.assertEqual(first.children.count(), 1)

    def test_requires_recipient(self):
        referral = Referral(letter=self.letter, from_user=self.manager, note='بدون گیرنده')
        with self.assertRaises(ValidationError):
            referral.full_clean()


class CopyTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.letter = register_letter(self.office, Letter.LetterType.INCOMING, 'نامه رونوشتی')

    def test_requires_recipient(self):
        copy = Copy(letter=self.letter, note='بدون گیرنده')
        with self.assertRaises(ValidationError):
            copy.full_clean()

    def test_valid_copy_to_user(self):
        user = User.objects.create_user('reader', password='x')
        copy = Copy(letter=self.letter, to_user=user)
        copy.full_clean()
        copy.save()
        self.assertIsNone(copy.viewed_at)


class AttachmentPolicyTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.letter = register_letter(self.office, Letter.LetterType.INCOMING, 'نامه پیوست‌دار')

    def test_accepts_pdf_and_records_metadata(self):
        attachment = Attachment(
            letter=self.letter,
            file=SimpleUploadedFile('letter-scan.pdf', b'%PDF-1.4 test', content_type='application/pdf'))
        attachment.full_clean()
        attachment.save()
        self.assertEqual(attachment.original_name, 'letter-scan.pdf')
        self.assertEqual(attachment.size_bytes, len(b'%PDF-1.4 test'))

    def test_rejects_disallowed_extension(self):
        attachment = Attachment(
            letter=self.letter,
            file=SimpleUploadedFile('malware.exe', b'MZ', content_type='application/octet-stream'))
        with self.assertRaises(ValidationError):
            attachment.full_clean()

    @override_settings(SECRETARIAT_ATTACHMENT_MAX_MB=1)
    def test_rejects_oversize(self):
        big = SimpleUploadedFile('big.pdf', b'x' * (2 * 1024 * 1024), content_type='application/pdf')
        attachment = Attachment(letter=self.letter, file=big)
        with self.assertRaises(ValidationError):
            attachment.full_clean()


class VisibilityTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.creator = User.objects.create_user('creator', password='x')
        self.stranger = User.objects.create_user('stranger', password='x')
        self.plain = register_letter(
            self.office, Letter.LetterType.INCOMING, 'عادی', created_by=self.creator)
        self.secret = register_letter(
            self.office, Letter.LetterType.INCOMING, 'محرمانه',
            created_by=self.creator, confidentiality=Letter.Confidentiality.CONFIDENTIAL)

    def test_no_permission_sees_nothing(self):
        self.assertEqual(visible_letters(self.stranger).count(), 0)

    def test_own_level_sees_only_own(self):
        give(self.creator, 'view_own_letter')
        give(self.stranger, 'view_own_letter')
        self.assertEqual(list(visible_letters(self.creator)), [self.plain])
        self.assertEqual(visible_letters(self.stranger).count(), 0)

    def test_all_level_sees_everything_except_confidential(self):
        viewer = give(User.objects.create_user('viewer', password='x'), 'view_all_letter')
        self.assertEqual(list(visible_letters(viewer)), [self.plain])

    def test_confidential_requires_explicit_permission(self):
        viewer = give(
            User.objects.create_user('boss', password='x'),
            'view_all_letter', 'view_confidential_letter')
        self.assertEqual(visible_letters(viewer).count(), 2)

    def test_unit_level_sees_subtree(self):
        parent = Department.objects.create(title='معاونت اداری', code='DEP-P')
        child = Department.objects.create(title='واحد دبیرخانه', code='DEP-C', parent=parent)
        office2 = make_office(code='BR', name='دبیرخانه شعبه', department=child)
        unit_letter = register_letter(office2, Letter.LetterType.INCOMING, 'نامه واحد زیرمجموعه')

        boss = User.objects.create_user('unitboss', password='x')
        Employee.objects.create(
            user=boss, first_name='مدیر', last_name='معاونت',
            personnel_code='E-1', department=parent)
        give(boss, 'view_unit_letter')

        visible = visible_letters(boss)
        self.assertIn(unit_letter, visible)
        self.assertNotIn(self.secret, visible)  # محرمانه همچنان پنهان است
