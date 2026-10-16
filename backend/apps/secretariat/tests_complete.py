"""آزمون‌های تکمیل دبیرخانه — چاپ گروهی، دورنویس، بایگانی، تفویض امضا، الگوی شماره."""
from datetime import timedelta

from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse
from django.utils import timezone

from apps.core.models import Delegation
from apps.secretariat import services as svc
from apps.secretariat.models import (
    FaxLog, Letter, NumberFormat, PersonalArchive, Referral, RetentionRule,
    SignatureRequest, SignatureStep)
from apps.secretariat.services import register_letter

from apps.secretariat.tests_pro import ProBase


class BatchPrintReceiptTests(ProBase):
    def test_print_copy_labels_min_three(self):
        letter = self.make_letter()
        labels = svc.print_copy_labels(letter)
        self.assertGreaterEqual(len(labels), 3)
        self.assertIn('بایگانی کل', labels[1])

    def test_batch_print_page(self):
        self.make_letter()
        self.client.login(username='admin-pro', password='x')
        resp = self.client.get(reverse('secretariat:batch_print'))
        self.assertContains(resp, 'چاپ گروهی')

    def test_receipt_page(self):
        letter = self.make_letter()
        self.client.login(username='admin-pro', password='x')
        resp = self.client.get(reverse('secretariat:letter_receipt', args=[letter.pk]))
        self.assertContains(resp, 'رسید تحویل')

    def test_word_export(self):
        letter = self.make_letter()
        self.client.login(username='admin-pro', password='x')
        resp = self.client.get(reverse('secretariat:letter_word', args=[letter.pk]))
        self.assertEqual(resp.status_code, 200)
        self.assertEqual(resp['Content-Type'], 'application/msword')


class FaxBookTests(ProBase):
    def test_fax_to_letter(self):
        fax = FaxLog.objects.create(
            direction='in', fax_number='021123', correspondent='سازمان آب',
            sent_at=timezone.now(), created_by=self.admin)
        letter = svc.fax_to_letter(fax, self.admin)
        fax.refresh_from_db()
        self.assertEqual(fax.letter_id, letter.pk)
        self.assertEqual(letter.delivery_method, Letter.DeliveryMethod.FAX)

    def test_fax_list_view(self):
        self.client.login(username='admin-pro', password='x')
        resp = self.client.post(reverse('secretariat:fax_list'), {
            'direction': 'out', 'fax_number': '021999', 'pages': '2',
            'convert': '1'})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(FaxLog.objects.count(), 1)
        self.assertIsNotNone(FaxLog.objects.first().letter_id)


class PersonalArchiveRetentionTests(ProBase):
    def test_personal_archive_toggle(self):
        letter = self.make_letter()
        obj = svc.personal_archive_toggle(self.u1, letter)
        self.assertIsNotNone(obj)
        self.assertEqual(PersonalArchive.objects.filter(user=self.u1).count(), 1)
        svc.personal_archive_toggle(self.u1, letter)
        self.assertEqual(PersonalArchive.objects.filter(user=self.u1).count(), 0)

    def test_reopen_archived_letter(self):
        letter = self.make_letter()
        letter.status = Letter.Status.ARCHIVED
        letter.save()
        svc.reopen_letter(letter, self.admin, 'نیاز به پیگیری')
        letter.refresh_from_db()
        self.assertEqual(letter.status, Letter.Status.IN_PROGRESS)

    def test_retention_rule_crud(self):
        self.client.login(username='admin-pro', password='x')
        resp = self.client.post(reverse('secretariat:retention_rules'), {
            'name': 'نامه‌های عادی', 'years': '5', 'action': 'temporary',
            'description': ''})
        self.assertEqual(resp.status_code, 302)
        self.assertEqual(RetentionRule.objects.count(), 1)


class GroupReferralProxyTests(ProBase):
    def test_group_referral_creates_parallel(self):
        letter = self.make_letter()
        u3 = type(self.u1).objects.create_user('u3-pro', password='x1234')
        refs = svc.refer_group(letter, [self.u2, u3], self.u1, note='هم‌زمان')
        self.assertEqual(len(refs), 2)
        self.assertEqual(
            Referral.objects.filter(letter=letter, status=Referral.Status.PENDING).count(), 2)

    def test_sign_proxy_via_delegation(self):
        letter = self.make_letter()
        req = svc.create_signature_request(
            letter, [{'user': self.u1}], SignatureRequest.Mode.PARALLEL, self.admin)
        step = req.steps.first()
        # u2 تفویض فعال از u1 دارد
        Delegation.objects.create(
            from_user=self.u1, to_user=self.u2,
            start_date=timezone.now().date() - timedelta(days=1),
            end_date=timezone.now().date() + timedelta(days=1))
        self.assertTrue(svc.can_sign_for(self.u2, step))
        self.client.login(username='u2-pro', password='x1234')
        resp = self.client.post(
            reverse('secretariat:signature_decide', args=[step.pk]),
            {'decision': 'approved', 'comment': 'از طرف'})
        step.refresh_from_db()
        self.assertEqual(step.status, SignatureStep.Status.APPROVED)
        self.assertEqual(step.proxy_user_id, self.u2.pk)


class NumberFormatTests(ProBase):
    def test_custom_number_pattern(self):
        NumberFormat.objects.create(
            letter_type=Letter.LetterType.INTERNAL,
            pattern='ن-{serial:03}/{year}')
        letter = self.make_letter()
        self.assertTrue(letter.number_display.startswith('ن-'))
        self.assertIn('۰۰۱', letter.number_display)
