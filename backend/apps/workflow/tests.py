"""آزمون موتور گردش کار — فاز ۲ اسپرینت ۱."""
from django.contrib.auth import get_user_model
from django.core.management import call_command
from django.test import TestCase
from django.urls import reverse

from apps.secretariat.models import Letter
from apps.secretariat.services import register_letter
from apps.secretariat.tests import give, make_office
from apps.workflow.models import Transition, Workflow, WorkflowAction
from apps.workflow.services import (apply_transition, available_transitions,
                                    bind_workflow, instance_for)

User = get_user_model()


class WorkflowEngineTests(TestCase):
    @classmethod
    def setUpTestData(cls):
        call_command('setup_default_workflow')
        cls.workflow = Workflow.objects.get(code='letter-default')
        cls.office = make_office()

    def setUp(self):
        self.letter = register_letter(self.office, 'incoming', 'نامه گردش‌دار')
        self.expert = give(User.objects.create_user('expert', password='x'),
                           'view_all_letter', 'refer_letter')
        self.plain = give(User.objects.create_user('plain', password='x'), 'view_all_letter')

    def test_seed_command_is_idempotent(self):
        call_command('setup_default_workflow')
        self.assertEqual(Workflow.objects.filter(code='letter-default').count(), 1)
        self.assertEqual(self.workflow.stages.count(), 5)

    def test_bind_places_instance_at_start(self):
        instance = bind_workflow(self.letter, self.workflow, self.plain)
        self.assertEqual(instance.current_stage.key, 'registered')
        self.assertTrue(WorkflowAction.objects.filter(instance=instance).exists())
        # بار دوم نمونه جدید نمی‌سازد
        again = bind_workflow(self.letter, self.workflow, self.plain)
        self.assertEqual(again.pk, instance.pk)

    def test_transitions_gated_by_permission(self):
        instance = bind_workflow(self.letter, self.workflow)
        titles = [t.title for t in available_transitions(instance, self.expert)]
        self.assertIn('ارجاع برای بررسی', titles)
        plain_titles = [t.title for t in available_transitions(instance, self.plain)]
        self.assertNotIn('ارجاع برای بررسی', plain_titles)

    def test_conditional_transition_only_for_immediate(self):
        instance = bind_workflow(self.letter, self.workflow)
        urgent_titles = [t.title for t in available_transitions(instance, self.expert)]
        self.assertNotIn('رسیدگی فوری', urgent_titles)
        self.letter.priority = Letter.Priority.IMMEDIATE
        self.letter.save(update_fields=['priority'])
        # نمونه تازه: کش content_object نباید اولویت قدیمی را نگه دارد
        instance = instance_for(self.letter)
        urgent_titles = [t.title for t in available_transitions(instance, self.expert)]
        self.assertIn('رسیدگی فوری', urgent_titles)

    def test_apply_moves_stage_and_logs(self):
        instance = bind_workflow(self.letter, self.workflow)
        transition = Transition.objects.get(
            workflow=self.workflow, from_stage__key='registered', to_stage__key='in_review',
            condition_field='')
        result = apply_transition(instance, transition, self.expert, note='بررسی شود')
        self.assertIsNotNone(result)
        self.assertEqual(result.current_stage.key, 'in_review')
        action = WorkflowAction.objects.filter(instance=instance).first()
        self.assertEqual(action.to_stage.key, 'in_review')
        self.assertEqual(action.note, 'بررسی شود')

    def test_apply_rejects_unauthorized(self):
        instance = bind_workflow(self.letter, self.workflow)
        transition = Transition.objects.get(
            workflow=self.workflow, from_stage__key='registered', to_stage__key='in_review',
            condition_field='')
        self.assertIsNone(apply_transition(instance, transition, self.plain))
        instance.refresh_from_db()
        self.assertEqual(instance.current_stage.key, 'registered')

    def test_full_happy_path_to_closed(self):
        closer = give(User.objects.create_user('boss', password='x'),
                      'view_all_letter', 'answer_letter', 'close_letter')
        instance = bind_workflow(self.letter, self.workflow)
        for from_key, to_key in [('registered', 'in_review'), ('in_review', 'in_progress'),
                                 ('in_progress', 'answered'), ('answered', 'closed')]:
            transition = Transition.objects.filter(
                workflow=self.workflow, from_stage__key=from_key, to_stage__key=to_key).first()
            user = self.expert if to_key in ('in_review', 'in_progress') else closer
            self.assertIsNotNone(apply_transition(instance, transition, user))
        instance.refresh_from_db()
        self.assertEqual(instance.current_stage.key, 'closed')
        self.assertTrue(instance.current_stage.is_final)


class WorkflowViewTests(TestCase):
    @classmethod
    def setUpTestData(cls):
        call_command('setup_default_workflow')
        cls.workflow = Workflow.objects.get(code='letter-default')
        cls.office = make_office()

    def test_list_and_detail_pages(self):
        user = give(User.objects.create_user('u', password='x'), 'view_all_letter')
        self.client.force_login(user)
        response = self.client.get(reverse('workflow:list'))
        self.assertContains(response, 'گردش استاندارد نامه')
        response = self.client.get(reverse('workflow:detail', args=[self.workflow.pk]))
        self.assertContains(response, 'بررسی/ارجاع')

    def test_bind_and_apply_via_letter_card(self):
        letter = register_letter(self.office, 'incoming', 'نامه رابط')
        dabir = give(User.objects.create_user('dabir', password='x'),
                     'view_all_letter', 'manage_secretariat', 'refer_letter')
        self.client.force_login(dabir)
        self.client.post(reverse('workflow:bind_letter', args=[letter.pk]))
        instance = instance_for(letter)
        self.assertIsNotNone(instance)
        transition = Transition.objects.get(
            workflow=self.workflow, from_stage__key='registered', to_stage__key='in_review',
            condition_field='')
        response = self.client.post(
            reverse('workflow:instance_apply', args=[instance.pk]),
            {'transition': transition.pk, 'note': 'به بررسی برود'}, follow=True)
        instance.refresh_from_db()
        self.assertEqual(instance.current_stage.key, 'in_review')
        self.assertContains(response, 'گردش به مرحله')
