"""آزمون‌های زیرسامانه بومی تیکت‌ها + فرمان واردسازی آینه."""
from django.contrib.auth import get_user_model
from django.contrib.auth.models import Permission
from django.core.management import call_command
from django.test import TestCase, override_settings
from django.urls import reverse

from apps.ticket_bridge.models import ExternalTicket, IntegrationUserMap
from apps.tickets.models import Ticket, TicketEvent
from apps.tickets.services import add_message, assign_ticket, change_status, create_ticket

User = get_user_model()


def make_user(username, **kw):
    return User.objects.create_user(username=username, password='pass1234!', **kw)


def give_perm(user, dotted):
    app, code = dotted.split('.')
    user.user_permissions.add(
        Permission.objects.get(codename=code, content_type__app_label=app))
    return user


class TicketServiceTests(TestCase):
    def setUp(self):
        self.user = make_user('requester', first_name='رها', last_name='کار')
        self.agent = make_user('agent', first_name='آرش', last_name='فن')

    def test_create_ticket_with_assignee_notifies(self):
        t = create_ticket(requester=self.user, subject='قطعی شبکه', assignee=self.agent)
        self.assertTrue(TicketEvent.objects.filter(
            ticket=t, action=TicketEvent.Action.CREATED).exists())
        self.assertTrue(self.agent.notifications.filter(title__contains='تیکت تازه').exists())

    def test_message_notifies_counterpart(self):
        t = create_ticket(requester=self.user, subject='ت', assignee=self.agent)
        add_message(t, self.user, 'پیگیری می‌کنید؟')
        self.assertTrue(self.agent.notifications.filter(title__contains='پیام تازه').exists())
        self.assertIsNone(add_message(t, self.user, '   '))

    def test_assign_and_status(self):
        t = create_ticket(requester=self.user, subject='ت')
        assign_ticket(t, self.user, self.agent)
        self.assertEqual(t.assignee, self.agent)
        self.assertEqual(change_status(t, self.agent, Ticket.Status.RESOLVED).status,
                         Ticket.Status.RESOLVED)
        self.assertIsNone(change_status(t, self.agent, 'bogus'))
        self.assertTrue(self.user.notifications.filter(title__contains='وضعیت تیکت').exists())

    def test_is_open_property(self):
        t = create_ticket(requester=self.user, subject='ت')
        self.assertTrue(t.is_open)
        change_status(t, self.user, Ticket.Status.CLOSED)
        self.assertFalse(t.is_open)


class ImportMirrorTests(TestCase):
    def setUp(self):
        self.user = make_user('m.rezaei')
        IntegrationUserMap.objects.create(
            platform_user=self.user, external_username='m.rezaei')
        ExternalTicket.objects.create(
            system='php-ticketing', external_id='IT-1042', subject='قطعی شبکه',
            status='in_progress', priority='urgent', service_group='it',
            requester_username='m.rezaei', created_label='۱۴۰۵/۰۷/۱۸')
        ExternalTicket.objects.create(
            system='php-ticketing', external_id='SP-220', subject='خرابی پرینتر',
            status='new', priority='weird', service_group='support',
            requester_username='unknown.user')

    def test_import_creates_native_tickets(self):
        call_command('import_mirror_tickets')
        t = Ticket.objects.get(external_ref='php-ticketing:IT-1042')
        self.assertEqual(t.status, Ticket.Status.IN_PROGRESS)
        self.assertEqual(t.priority, Ticket.Priority.URGENT)
        self.assertEqual(t.requester, self.user)  # از IntegrationUserMap
        self.assertTrue(TicketEvent.objects.filter(
            ticket=t, action=TicketEvent.Action.IMPORTED).exists())

    def test_import_maps_unknown_status_and_user(self):
        call_command('import_mirror_tickets')
        t = Ticket.objects.get(external_ref='php-ticketing:SP-220')
        self.assertEqual(t.status, Ticket.Status.OPEN)      # new → open
        self.assertEqual(t.priority, Ticket.Priority.NORMAL)  # اولویت ناشناخته → عادی
        self.assertIsNone(t.requester)

    def test_import_is_idempotent(self):
        call_command('import_mirror_tickets')
        call_command('import_mirror_tickets')
        self.assertEqual(Ticket.objects.count(), 2)
        self.assertEqual(TicketEvent.objects.filter(
            action=TicketEvent.Action.IMPORTED).count(), 2)

    def test_import_updates_changed_status(self):
        call_command('import_mirror_tickets')
        ExternalTicket.objects.filter(external_id='IT-1042').update(status='closed')
        call_command('import_mirror_tickets')
        self.assertEqual(Ticket.objects.get(
            external_ref='php-ticketing:IT-1042').status, Ticket.Status.CLOSED)


class TicketViewTests(TestCase):
    def setUp(self):
        self.user = make_user('viewer')
        self.agent = give_perm(make_user('agent'), 'tickets.assign_ticket')
        self.manager = give_perm(make_user('mgr'), 'tickets.view_all_ticket')
        self.stranger = make_user('stranger')
        self.ticket = create_ticket(requester=self.user, subject='تیکت من',
                                    assignee=self.agent)

    @override_settings(TICKET_MODE='bridge')
    def test_bridge_mode_shows_mirror(self):
        ExternalTicket.objects.create(
            system='php-ticketing', external_id='X-1', subject='آینه',
            requester_username='viewer')
        self.client.force_login(self.user)
        resp = self.client.get(reverse('tickets:list'))
        self.assertContains(resp, 'آینه')
        self.assertNotContains(resp, 'تیکت من')  # تیکت بومی در حالت bridge پنهان است

    @override_settings(TICKET_MODE='bridge')
    def test_bridge_mode_new_redirects_to_jump(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('tickets:new'))
        self.assertEqual(resp.status_code, 302)
        self.assertIn('/bridge/jump/', resp.url)

    @override_settings(TICKET_MODE='native')
    def test_native_list_tabs(self):
        self.client.force_login(self.user)
        resp = self.client.get(reverse('tickets:list') + '?tab=mine')
        self.assertContains(resp, 'تیکت من')
        self.client.force_login(self.agent)
        resp = self.client.get(reverse('tickets:list') + '?tab=assigned')
        self.assertContains(resp, 'تیکت من')

    @override_settings(TICKET_MODE='native')
    def test_stranger_blocked_from_detail(self):
        self.client.force_login(self.stranger)
        resp = self.client.get(reverse('tickets:detail', args=[self.ticket.pk]))
        self.assertEqual(resp.status_code, 302)

    @override_settings(TICKET_MODE='native')
    def test_manager_sees_all(self):
        self.client.force_login(self.manager)
        resp = self.client.get(reverse('tickets:detail', args=[self.ticket.pk]))
        self.assertEqual(resp.status_code, 200)

    @override_settings(TICKET_MODE='native')
    def test_message_and_status_flow(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('tickets:message', args=[self.ticket.pk]),
                                {'body': 'سلام، رسیدگی شد؟'})
        self.assertEqual(resp.status_code, 302)
        self.assertTrue(self.ticket.messages.filter(body__contains='رسیدگی شد').exists())
        self.client.force_login(self.agent)
        resp = self.client.post(reverse('tickets:status', args=[self.ticket.pk]),
                                {'status': 'resolved'})
        self.assertEqual(resp.status_code, 302)
        self.ticket.refresh_from_db()
        self.assertEqual(self.ticket.status, Ticket.Status.RESOLVED)

    @override_settings(TICKET_MODE='native')
    def test_assign_requires_perm(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('tickets:assign', args=[self.ticket.pk]),
                                {'assignee_id': self.stranger.pk})
        self.assertEqual(resp.status_code, 302)
        self.ticket.refresh_from_db()
        self.assertEqual(self.ticket.assignee, self.agent)  # تغییر نکرد
        self.client.force_login(self.agent)
        resp = self.client.post(reverse('tickets:assign', args=[self.ticket.pk]),
                                {'assignee_id': self.stranger.pk})
        self.ticket.refresh_from_db()
        self.assertEqual(self.ticket.assignee, self.stranger)

    @override_settings(TICKET_MODE='native')
    def test_create_new_ticket(self):
        self.client.force_login(self.user)
        resp = self.client.post(reverse('tickets:new'), {
            'subject': 'پرینتر خراب است', 'body': 'طبقه ۲', 'priority': 'high',
            'service_group': 'support'})
        self.assertEqual(resp.status_code, 302)
        t = Ticket.objects.get(subject='پرینتر خراب است')
        self.assertEqual(t.requester, self.user)
        self.assertEqual(t.priority, Ticket.Priority.HIGH)
