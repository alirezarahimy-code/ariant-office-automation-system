"""آزمون گردش صادره: پیش‌نویس → بازبینی → امضا → ارسال + PDF و چاپ."""
import jdatetime
from django.contrib.auth import get_user_model
from django.test import TestCase
from django.urls import reverse

from apps.core.models import Notification
from apps.secretariat.models import Letter, LetterApproval
from apps.secretariat.services import register_letter
from apps.secretariat.tests import give, make_office

User = get_user_model()


class OutgoingFlowTests(TestCase):
    def setUp(self):
        self.office = make_office()
        self.author = give(User.objects.create_user('author', password='x'), 'view_own_letter')
        self.approver = give(
            User.objects.create_user('approver', password='x', first_name='رضا', last_name='امضا'),
            'view_all_letter', 'approve_letter')
        self.letter = register_letter(
            self.office, Letter.LetterType.OUTGOING, 'پاسخ به استعلام شماره ۴۲',
            created_by=self.author, body='احتراماً، در پاسخ به نامه شماره …',
            letter_date=jdatetime.date(1405, 7, 20))

    def _url(self, name):
        return reverse(f'secretariat:{name}', args=[self.letter.pk])

    def test_draft_to_review_by_author(self):
        self.client.force_login(self.author)
        self.client.post(self._url('letter_submit'))
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.IN_REVIEW)
        # بازبین اعلان می‌گیرد
        self.assertTrue(Notification.objects.filter(user=self.approver).exists())

    def test_stranger_cannot_submit(self):
        self.client.force_login(self.approver)
        self.client.post(self._url('letter_submit'))
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.DRAFT)

    def test_approve_signs_letter(self):
        self.letter.status = Letter.Status.IN_REVIEW
        self.letter.save()
        self.client.force_login(self.approver)
        self.client.post(self._url('letter_approve'), {'label': 'مدیرکل', 'note': 'بلامانع است'})
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.SIGNED)
        approval = LetterApproval.objects.get(letter=self.letter)
        self.assertEqual(approval.decision, LetterApproval.Decision.APPROVED)
        self.assertEqual(approval.approver_label, 'مدیرکل')
        self.assertTrue(Notification.objects.filter(user=self.author).exists())

    def test_reject_returns_to_draft(self):
        self.letter.status = Letter.Status.IN_REVIEW
        self.letter.save()
        self.client.force_login(self.approver)
        self.client.post(self._url('letter_reject'), {'note': 'لحن نامه اصلاح شود'})
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.DRAFT)
        self.assertEqual(
            LetterApproval.objects.get(letter=self.letter).decision,
            LetterApproval.Decision.REJECTED)

    def test_send_requires_signed(self):
        self.client.force_login(self.author)
        self.client.post(self._url('letter_send'))
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.DRAFT)  # هنوز پیش‌نویس

        self.letter.status = Letter.Status.SIGNED
        self.letter.save()
        self.client.post(self._url('letter_send'))
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.SENT)

    def test_author_cannot_approve_own_letter(self):
        self.letter.status = Letter.Status.IN_REVIEW
        self.letter.save()
        self.client.force_login(self.author)
        self.client.post(self._url('letter_approve'), {'label': ''})
        self.letter.refresh_from_db()
        self.assertEqual(self.letter.status, Letter.Status.IN_REVIEW)

    def test_pdf_response(self):
        self.client.force_login(self.author)
        response = self.client.get(self._url('letter_pdf'))
        self.assertEqual(response['Content-Type'], 'application/pdf')
        self.assertTrue(response.content.startswith(b'%PDF-'))
        self.assertGreater(len(response.content), 1200)

    def test_print_view_renders_sheet(self):
        self.client.force_login(self.author)
        response = self.client.get(self._url('letter_print'))
        self.assertEqual(response.status_code, 200)
        self.assertContains(response, 'بسمه تعالی')
        self.assertContains(response, self.letter.number_display)
