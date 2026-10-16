"""آزمون‌های صیقل نهایی دبیرخانه — دفتر پیک، خروجی Excel، نسخه فیزیکی، فیلتر تاریخ."""
from django.test import TestCase
from django.urls import reverse
from django.utils import timezone

from apps.secretariat.models import Attachment, CourierLog, Letter, RegisterEntry
from apps.secretariat import services as svc

from apps.secretariat.tests_pro import ProBase


class CourierBookTests(ProBase):
    def test_register_and_deliver(self):
        letter = self.make_letter()
        self.client.login(username='admin-pro', password='x')
        resp = self.client.post(reverse('secretariat:courier_list'), {
            'letter_pk': letter.pk, 'courier_name': 'علی پیک',
            'receiver_name': 'مدیر دفتر'})
        self.assertEqual(resp.status_code, 302)
        log = CourierLog.objects.get(letter=letter)
        self.assertEqual(log.status, 'sent')
        resp2 = self.client.post(
            reverse('secretariat:courier_update', args=[log.pk]),
            {'action': 'deliver', 'receiver_name': 'تحویل‌گیرنده'})
        self.assertEqual(resp2.status_code, 302)
        log.refresh_from_db()
        self.assertEqual(log.status, 'delivered')
        self.assertIsNotNone(log.delivered_at)

    def test_return_action(self):
        letter = self.make_letter()
        log = CourierLog.objects.create(letter=letter, sent_at=timezone.now())
        self.client.login(username='admin-pro', password='x')
        self.client.post(
            reverse('secretariat:courier_update', args=[log.pk]), {'action': 'return'})
        log.refresh_from_db()
        self.assertEqual(log.status, 'returned')


class RegisterExcelTests(ProBase):
    def test_excel_export(self):
        letter = self.make_letter()
        svc.create_register_entry(letter, user=self.admin)
        self.client.login(username='admin-pro', password='x')
        resp = self.client.get(reverse('secretariat:register_book_excel'))
        self.assertEqual(resp.status_code, 200)
        self.assertIn('spreadsheetml', resp['Content-Type'])


class PhysicalCopyKindTests(ProBase):
    def test_form_sets_copy_kind_and_count(self):
        from apps.secretariat.forms import OutgoingDraftForm
        form = OutgoingDraftForm(data={
            'office': self.office.pk, 'subject': 'نامه صادره آزمون',
            'summary': '', 'body': 'متن', 'letter_date': '1404/07/25',
            'priority': 'normal', 'confidentiality': 'normal',
            'copy_kind': 'duplicate', 'physical_count': '4'})
        self.assertTrue(form.is_valid(), form.errors)
        letter = form.save(self.admin)
        self.assertEqual(letter.copy_kind, 'duplicate')
        self.assertEqual(letter.physical_count, 4)

    def test_incoming_attachment_kind_scan(self):
        from apps.secretariat.forms import IncomingLetterForm
        from django.core.files.uploadedfile import SimpleUploadedFile
        from django.utils.datastructures import MultiValueDict
        f = SimpleUploadedFile('scan.pdf', b'%PDF-1.4 test', content_type='application/pdf')
        form = IncomingLetterForm(data={
            'office': self.office.pk, 'subject': 'وارده با اسکن',
            'summary': '', 'sender_name': 'x', 'sender_org': 'y',
            'letter_date': '1404/07/25', 'priority': 'normal',
            'confidentiality': 'normal', 'deadline': '1404/08/01',
            'copy_kind': 'original', 'physical_count': '1'},
            files=MultiValueDict({'attachments': [f]}))
        self.assertTrue(form.is_valid(), form.errors)
        letter = form.save(self.admin)
        att = Attachment.objects.get(letter=letter)
        self.assertEqual(att.kind, 'scan')


class DateFilterTests(ProBase):
    def test_letter_list_date_range(self):
        l1 = self.make_letter(subject='قدیمی')
        l2 = self.make_letter(subject='جدید')
        l1.letter_date = __import__('datetime').date(2025, 1, 1)
        l1.save(update_fields=['letter_date'])
        l2.letter_date = __import__('datetime').date(2026, 6, 1)
        l2.save(update_fields=['letter_date'])
        self.client.login(username='admin-pro', password='x')
        resp = self.client.get(
            reverse('secretariat:letter_list'),
            {'date_from': '1404/01/01', 'date_to': '1404/12/29'})
        self.assertEqual(resp.status_code, 200)
