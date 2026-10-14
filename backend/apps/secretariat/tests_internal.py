"""آزمون‌های نامه داخلی، نامه‌های مرتبط، یادداشت، نامه مشابه و بازپس‌گیری."""
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse

from apps.core.models import Notification, OrgSettings
from apps.secretariat.models import (Letter, LetterNote, LetterRelation,
                                     Office, Referral)
from apps.organization.models import Department
from apps.secretariat.services import register_letter, visible_letters

User = get_user_model()


class InternalLetterTests(TestCase):
    def setUp(self):
        self.dept = Department.objects.create(title='فناوری اطلاعات', code='IT')
        self.office = Office.objects.create(name='دبیرخانه مرکزی', code='D1',
                                            department=self.dept)
        self.sender = User.objects.create_user('sender', password='x')
        self.recipient = User.objects.create_user('recipient', password='x')
        view_own = Permission.objects.get(codename='view_own_letter')
        self.sender.user_permissions.add(view_own)
        self.recipient.user_permissions.add(view_own)

    def test_register_internal_letter(self):
        letter = register_letter(
            self.office, Letter.LetterType.INTERNAL, 'جلسه هفتگی',
            created_by=self.sender, to_user=self.recipient,
            body='لطفاً در جلسه حاضر شوید.')
        self.assertEqual(letter.number_display.split('/')[1], 'د')
        self.assertEqual(letter.to_user, self.recipient)
        self.assertEqual(letter.status, Letter.Status.REGISTERED)

    def test_recipient_sees_letter(self):
        letter = register_letter(
            self.office, Letter.LetterType.INTERNAL, 'نامه داخلی',
            created_by=self.sender, to_user=self.recipient)
        self.assertIn(letter, visible_letters(self.recipient))
        self.assertNotIn(letter, visible_letters(
            User.objects.create_user('other', password='x')))

    def test_internal_form_view_and_post(self):
        self.client.force_login(self.sender)
        resp = self.client.get(reverse('secretariat:internal'))
        self.assertEqual(resp.status_code, 200)
        resp = self.client.post(reverse('secretariat:internal'), {
            'form': 'internal', 'int-office': self.office.pk,
            'int-subject': 'درخواست تجهیزات', 'int-body': 'متن نامه',
            'int-to_user': self.recipient.pk,
            'int-letter_date': '1404/07/24', 'int-deadline': '1404/07/30',
            'int-priority': 'normal', 'int-confidentiality': 'normal',
            'int-delivery_method': 'system'})
        self.assertEqual(resp.status_code, 302)
        letter = Letter.objects.get(subject='درخواست تجهیزات')
        self.assertEqual(letter.letter_type, Letter.LetterType.INTERNAL)
        # اعلان تحویل کارتابل
        self.assertTrue(Notification.objects.filter(
            user=self.recipient, title__contains='نامه داخلی').exists())

    def test_internal_form_requires_recipient(self):
        self.client.force_login(self.sender)
        resp = self.client.post(reverse('secretariat:internal'), {
            'form': 'internal', 'int-office': self.office.pk,
            'int-subject': 'بدون گیرنده', 'int-letter_date': '1404/07/24',
            'int-deadline': '', 'int-priority': 'normal',
            'int-confidentiality': 'normal', 'int-delivery_method': 'system'})
        self.assertEqual(Letter.objects.count(), 0)

    def test_kartable_shows_received_internal(self):
        register_letter(
            self.office, Letter.LetterType.INTERNAL, 'برای کارتابل',
            created_by=self.sender, to_user=self.recipient)
        self.client.force_login(self.recipient)
        resp = self.client.get(reverse('secretariat:kartable'))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'برای کارتابل')


class RelationAndNoteTests(TestCase):
    def setUp(self):
        self.dept = Department.objects.create(title='اداری', code='AD')
        self.office = Office.objects.create(name='دبیرخانه', code='D1',
                                            department=self.dept)
        self.user = User.objects.create_user('u1', password='x')
        self.user.user_permissions.add(
            Permission.objects.get(codename='view_own_letter'))
        self.letter_a = register_letter(
            self.office, Letter.LetterType.INTERNAL, 'نامه الف',
            created_by=self.user, to_user=self.user)
        self.letter_b = register_letter(
            self.office, Letter.LetterType.INTERNAL, 'نامه ب',
            created_by=self.user, to_user=self.user)

    def test_relation_add(self):
        self.client.force_login(self.user)
        resp = self.client.post(
            reverse('secretariat:letter_relation', args=[self.letter_a.pk]),
            {'related_pk': self.letter_b.pk, 'kind': 'ref'})
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(LetterRelation.objects.filter(
            letter=self.letter_a, related=self.letter_b, kind='ref').exists())

    def test_relation_self_rejected(self):
        self.client.force_login(self.user)
        self.client.post(
            reverse('secretariat:letter_relation', args=[self.letter_a.pk]),
            {'related_pk': self.letter_a.pk, 'kind': 'ref'})
        self.assertEqual(LetterRelation.objects.count(), 0)

    def test_note_add(self):
        self.client.force_login(self.user)
        self.client.post(
            reverse('secretariat:letter_note', args=[self.letter_a.pk]),
            {'text': 'پیگیری شود'})
        self.assertEqual(LetterNote.objects.count(), 1)

    def test_duplicate_creates_follow(self):
        self.client.force_login(self.user)
        resp = self.client.post(
            reverse('secretariat:letter_duplicate', args=[self.letter_a.pk]))
        self.assertEqual(resp.status_code, 302)
        duplicate = Letter.objects.get(status=Letter.Status.DRAFT)
        self.assertEqual(duplicate.status, Letter.Status.DRAFT)
        self.assertTrue(LetterRelation.objects.filter(
            letter=duplicate, related=self.letter_a, kind='follow').exists())

    def test_refer_cancel(self):
        referral = Referral.objects.create(
            letter=self.letter_a, from_user=self.user, to_user=self.user)
        self.client.force_login(self.user)
        resp = self.client.post(
            reverse('secretariat:refer_cancel', args=[self.letter_a.pk]),
            {'referral_id': referral.pk})
        self.assertEqual(resp.status_code, 302)
        referral.refresh_from_db()
        self.assertEqual(referral.status, Referral.Status.CANCELLED)

    def test_refer_cancel_only_pending(self):
        referral = Referral.objects.create(
            letter=self.letter_a, from_user=self.user, to_user=self.user,
            status=Referral.Status.ACTED)
        self.client.force_login(self.user)
        self.client.post(
            reverse('secretariat:refer_cancel', args=[self.letter_a.pk]),
            {'referral_id': referral.pk})
        referral.refresh_from_db()
        self.assertEqual(referral.status, Referral.Status.ACTED)

    def test_print_page_has_copies_and_relations(self):
        self.client.force_login(self.user)
        self.client.post(
            reverse('secretariat:letter_relation', args=[self.letter_a.pk]),
            {'related_pk': self.letter_b.pk, 'kind': 'follow'})
        resp = self.client.get(
            reverse('secretariat:letter_print', args=[self.letter_a.pk]))
        self.assertEqual(resp.status_code, 200)
        self.assertContains(resp, 'نامه‌های مرتبط')
