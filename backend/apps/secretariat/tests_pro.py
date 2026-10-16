"""آزمون‌های لایه حرفه‌ای دبیرخانه — پرونده، دفتر اندیکاتور، امضا، SLA، کلیدهای قابلیت."""
from datetime import timedelta

from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.test import TestCase
from django.urls import reverse
from django.utils import timezone

from apps.core.models import FEATURE_REGISTRY, FeatureToggle
from apps.core.services import clear_feature_cache, feature_enabled
from apps.organization.models import Position
from apps.secretariat import services as svc
from apps.secretariat.models import (
    CaseFile, Copy, Letter, LetterRelation, NumberReservation, Paraph,
    RegisterCheck, RegisterEntry, SignatureRequest, SignatureStep, SLARule)
from apps.secretariat.services import register_letter

User = get_user_model()


class ProBase(TestCase):
    @classmethod
    def setUpTestData(cls):
        cls.admin = User.objects.create_superuser('admin-pro', password='x')
        cls.u1 = User.objects.create_user('u1-pro', password='x1234')
        cls.u2 = User.objects.create_user('u2-pro', password='x1234')
        for u in (cls.u1, cls.u2):
            u.user_permissions.add(
                Permission.objects.get(codename='view_own_letter'),
                Permission.objects.get(codename='refer_letter'))
        from apps.secretariat.models import Office
        from apps.organization.models import Department
        dept = Department.objects.create(title='دبیرخانه مرکزی', code='D0')
        cls.office = Office.objects.create(
            name='دبیرخانه مرکزی', code='DR0', department=dept)

    def make_letter(self, **kw):
        defaults = dict(
            letter_type=Letter.LetterType.INTERNAL, subject='نامه آزمون',
            created_by=self.u1)
        defaults.update(kw)
        letter = register_letter(self.office, **defaults)
        letter.to_user = self.u2
        letter.save(update_fields=['to_user'])
        return letter


class FeatureToggleTests(ProBase):
    def test_setup_features_creates_registry_rows(self):
        from django.core.management import call_command
        call_command('setup_features')
        self.assertEqual(FeatureToggle.objects.count(), len(FEATURE_REGISTRY))

    def test_missing_toggle_means_enabled(self):
        self.assertTrue(feature_enabled('secretariat.casefiles'))

    def test_disable_toggle_blocks_view(self):
        clear_feature_cache()
        FeatureToggle.objects.create(
            code='secretariat.casefiles', title='x', is_enabled=False)
        clear_feature_cache()
        self.client.login(username='u1-pro', password='x1234')
        resp = self.client.get(reverse('secretariat:case_file_list'))
        self.assertContains(resp, 'غیرفعال')
        FeatureToggle.objects.all().delete()
        clear_feature_cache()

    def test_features_admin_only_superuser(self):
        self.client.login(username='u1-pro', password='x1234')
        resp = self.client.get(reverse('accounts:features'))
        self.assertEqual(resp.status_code, 302)


class CaseFileTests(ProBase):
    def test_open_case_file_numbering(self):
        cf1 = svc.open_case_file('پرونده اول', user=self.admin)
        cf2 = svc.open_case_file('پرونده دوم', user=self.admin)
        self.assertTrue(cf1.number.startswith('پ-'))
        self.assertNotEqual(cf1.number, cf2.number)

    def test_add_letter_and_close_blocks_add(self):
        letter = self.make_letter()
        cf = svc.open_case_file('پرونده', user=self.admin)
        svc.add_letter_to_case_file(letter, cf, user=self.admin)
        letter.refresh_from_db()
        self.assertEqual(letter.case_file_id, cf.pk)
        svc.set_case_file_status(cf, CaseFile.Status.CLOSED, user=self.admin)
        letter2 = self.make_letter(subject='دیگری')
        with self.assertRaises(ValueError):
            svc.add_letter_to_case_file(letter2, cf, user=self.admin)

    def test_case_file_detail_view(self):
        cf = svc.open_case_file('پرونده', user=self.admin)
        self.client.login(username='u1-pro', password='x1234')
        resp = self.client.get(reverse('secretariat:case_file_detail', args=[cf.pk]))
        self.assertContains(resp, cf.number)


class RegisterBookTests(ProBase):
    def test_register_entry_sequencing(self):
        l1 = self.make_letter()
        l2 = self.make_letter(subject='دوم')
        e1 = svc.create_register_entry(l1, user=self.admin)
        e2 = svc.create_register_entry(l2, user=self.admin)
        self.assertEqual(e1.row_number, 1)
        self.assertEqual(e2.row_number, 2)
        self.assertEqual(e2.prev_row_id, e1.pk)

    def test_register_check_fail_returns_draft(self):
        letter = self.make_letter()
        letter.status = Letter.Status.REGISTERED
        letter.save()
        obj = svc.register_check(letter, self.admin, RegisterCheck.Result.FAIL)
        letter.refresh_from_db()
        self.assertEqual(letter.status, Letter.Status.DRAFT)
        self.assertEqual(obj.result, 'fail')

    def test_register_check_pass_creates_entry(self):
        letter = self.make_letter()
        self.client.login(username='admin-pro', password='x')
        resp = self.client.post(
            reverse('secretariat:letter_register_check', args=[letter.pk]),
            {'result': 'pass', 'has_signature': '1', 'copies_count_ok': '1',
             'attachments_ok': '1', 'dates_ok': '1', 'addressee_ok': '1'})
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(RegisterEntry.objects.filter(letter=letter).exists())


class SignatureLockTests(ProBase):
    def test_sequential_sign_flow_locks_letter(self):
        letter = self.make_letter(letter_type=Letter.LetterType.OUTGOING)
        req = svc.create_signature_request(
            letter, [{'user': self.u1}, {'user': self.u2}],
            SignatureRequest.Mode.SEQUENTIAL, self.admin)
        s1, s2 = req.steps.order_by('order')
        svc.decide_signature_step(s1, self.u1, SignatureStep.Status.APPROVED)
        letter.refresh_from_db()
        self.assertEqual(letter.status, Letter.Status.IN_REVIEW)
        svc.decide_signature_step(s2, self.u2, SignatureStep.Status.APPROVED)
        letter.refresh_from_db()
        req.refresh_from_db()
        self.assertEqual(req.status, SignatureRequest.Status.COMPLETED)
        self.assertEqual(letter.status, Letter.Status.SIGNED)
        self.assertIsNotNone(letter.locked_at)

    def test_reject_step_rejects_request(self):
        letter = self.make_letter()
        req = svc.create_signature_request(
            letter, [{'user': self.u1}], SignatureRequest.Mode.PARALLEL, self.admin)
        step = req.steps.first()
        svc.decide_signature_step(step, self.u1, SignatureStep.Status.REJECTED, 'درست نیست')
        req.refresh_from_db()
        self.assertEqual(req.status, SignatureRequest.Status.REJECTED)

    def test_new_revision_increments_and_unlocks(self):
        letter = self.make_letter()
        svc.lock_letter(letter, self.admin)
        rev = svc.new_revision(letter, self.u1, 'اصلاح شماره')
        letter.refresh_from_db()
        self.assertEqual(letter.current_revision, 2)
        self.assertIsNone(letter.locked_at)
        self.assertEqual(rev.revision_no, 2)

    def test_signature_view_requires_signer(self):
        letter = self.make_letter()
        req = svc.create_signature_request(
            letter, [{'user': self.u1}], SignatureRequest.Mode.PARALLEL, self.admin)
        step = req.steps.first()
        self.client.login(username='u2-pro', password='x1234')
        resp = self.client.post(
            reverse('secretariat:signature_decide', args=[step.pk]),
            {'decision': 'approved'})
        step.refresh_from_db()
        self.assertEqual(step.status, SignatureStep.Status.PENDING)


class CopyReceiptParaphTests(ProBase):
    def test_action_copy_and_done(self):
        letter = self.make_letter()
        c = Copy.objects.create(
            letter=letter, to_user=self.u2, kind='action', action_note='اقدام کن')
        self.client.login(username='u2-pro', password='x1234')
        resp = self.client.post(reverse('secretariat:copy_done', args=[c.pk]))
        c.refresh_from_db()
        self.assertIsNotNone(c.done_at)

    def test_read_receipt_unique(self):
        from apps.secretariat.models import ReadReceipt
        letter = self.make_letter()
        svc.record_read_receipt(letter, self.u1, 'direct')
        svc.record_read_receipt(letter, self.u1, 'direct')
        self.assertEqual(
            ReadReceipt.objects.filter(letter=letter, user=self.u1).count(), 1)

    def test_paraph(self):
        letter = self.make_letter()
        p = svc.add_paraph(letter, self.u1, 'دیده شد')
        self.assertEqual(Paraph.objects.filter(letter=letter).count(), 1)
        self.assertEqual(p.text, 'دیده شد')


class SLAReservationTests(ProBase):
    def test_apply_sla_and_overdue(self):
        letter = self.make_letter()
        SLARule.objects.create(
            name='عادی داخلی', letter_type='internal', priority='normal',
            action_hours=24, warn_hours_before=12)
        ref = svc.visible_referrals  # noqa — فقط اطمینان از import
        from apps.secretariat.models import Referral
        r = Referral.objects.create(letter=letter, from_user=self.u1, to_user=self.u2)
        svc.apply_sla(r)
        r.refresh_from_db()
        self.assertIsNotNone(r.sla_due_at)
        # عبور از مهلت
        r.sla_due_at = timezone.now() - timedelta(hours=1)
        r.save(update_fields=['sla_due_at'])
        warned, overdue = svc.check_sla_overdue()
        self.assertEqual(len(overdue), 1)
        r.refresh_from_db()
        self.assertIsNotNone(r.overdue_at)

    def test_reserve_number(self):
        obj = svc.reserve_number(self.office, 'outgoing', self.u1, 'برای نامه مدیر')
        self.assertEqual(obj.serial, 1)
        self.assertEqual(NumberReservation.objects.count(), 1)


class SearchPositionMemoTests(ProBase):
    def test_search_all_scoped(self):
        letter = self.make_letter(subject='قرارداد پیمانکاری')
        res = svc.search_all(self.u1, 'پیمانکاری')
        self.assertEqual(len(res['letters']), 1)
        res2 = svc.search_all(self.u2, 'پیمانکاری')
        # u2 به‌عنوان گیرنده باید ببیند
        self.assertEqual(len(res2['letters']), 1)

    def test_position_referral_visible_in_kartable(self):
        letter = self.make_letter()
        pos = Position.objects.create(title='مدیر مالی', holder=self.u2)
        from apps.secretariat.models import Referral
        Referral.objects.create(
            letter=letter, from_user=self.u1, target_position=pos,
            target_kind='position')
        refs = svc.visible_referrals(self.u2)
        self.assertEqual(refs.count(), 1)
        refs_u1 = svc.visible_referrals(self.u1)
        self.assertEqual(refs_u1.count(), 0)

    def test_memo_letter_numbering(self):
        self.client.login(username='admin-pro', password='x')
        resp = self.client.post(reverse('secretariat:memo'), {
            'subject': 'یادداشت جلسه', 'body': 'متن یادداشت',
            'to_user': self.u2.pk, 'priority': 'normal'})
        self.assertEqual(resp.status_code, 302)
        letter = Letter.objects.get(subject='یادداشت جلسه')
        self.assertEqual(letter.letter_type, Letter.LetterType.MEMO)
        self.assertIn('ی', letter.number_display)

    def test_pair_answer(self):
        l1 = self.make_letter(letter_type=Letter.LetterType.INCOMING)
        l2 = self.make_letter(letter_type=Letter.LetterType.OUTGOING, subject='پاسخ')
        svc.pair_answer(l2, l1, self.admin)
        rel = LetterRelation.objects.get(letter=l2, related=l1)
        self.assertEqual(rel.kind, LetterRelation.Kind.ANSWER)
