"""واردسازی تیکت‌های آینه‌شده (ExternalTicket) به زیرسامانه بومی — گذار بند ۴.

ایدِمپوتنت: کلید یکتا external_ref = «سامانه:شناسه» است؛ اجرای دوباره
همان تیکت‌ها را به‌روز می‌کند و تکراری نمی‌سازد.
"""
from django.contrib.auth import get_user_model
from django.core.management.base import BaseCommand

from apps.ticket_bridge.models import ExternalTicket, IntegrationUserMap
from apps.tickets.models import Ticket, TicketEvent

User = get_user_model()


class Command(BaseCommand):
    help = 'واردسازی/به‌روزرسانی تیکت‌های آینه PHP در زیرسامانه بومی'

    def add_arguments(self, parser):
        parser.add_argument('--system', default='', help='فقط این سامانه (پیش‌فرض: همه)')

    def handle(self, *args, **options):
        externals = ExternalTicket.objects.all()
        if options['system']:
            externals = externals.filter(system=options['system'])
        maps = {m.external_username: m.platform_user_id
                for m in IntegrationUserMap.objects.all()}
        usernames = {u.username: u for u in User.objects.all()}

        fresh = updated = 0
        for ext in externals:
            ref = f'{ext.system}:{ext.external_id}'
            requester_id = maps.get(ext.requester_username)
            requester = None
            if requester_id:
                requester = User.objects.filter(pk=requester_id).first()
            elif ext.requester_username in usernames:
                requester = usernames[ext.requester_username]
            defaults = {
                'subject': ext.subject,
                'status': Ticket.EXTERNAL_STATUS_MAP.get(ext.status, Ticket.Status.OPEN),
                'priority': ext.priority if ext.priority in Ticket.Priority.values
                            else Ticket.Priority.NORMAL,
                'service_group': ext.service_group,
                'requester': requester,
            }
            ticket, created = Ticket.objects.update_or_create(
                external_ref=ref, defaults=defaults)
            if created:
                TicketEvent.objects.create(
                    ticket=ticket, actor=None, action=TicketEvent.Action.IMPORTED,
                    note=f'ورود از آینه {ext.system} — شناسه {ext.external_id} '
                         f'(وضعیت مبدأ: {ext.status_fa})')
                fresh += 1
            else:
                updated += 1
        self.stdout.write(self.style.SUCCESS(
            f'واردسازی تیکت‌های آینه — تازه: {fresh}، به‌روز: {updated}'))
