"""سرویس‌های وظایف: ایجاد با اعلان، به‌روزرسانی پیشرفت و وضعیت."""
from __future__ import annotations

from .models import Task, TaskEvent


def notify(user, title: str, body: str = '', url: str = '') -> None:
    """اعلان یکپارچه — واگذاری به سرویس مرکزی (اسپرینت ۵)."""
    from apps.core.services import send_notification
    send_notification(user, title, body, url)


def create_task(*, title: str, description: str = '', responsible, due_jalali=None,
                priority: str = Task.Priority.NORMAL, created_by=None,
                source_resolution_id: int | None = None, source_letter=None,
                collaborators=()) -> Task:
    """ایجاد وظیفه + رویداد + اعلان مسئول (نقطه ورود مشترک مصوبات/نامه‌ها)."""
    task = Task.objects.create(
        title=title, description=description, responsible=responsible,
        due_date=due_jalali, priority=priority, created_by=created_by,
        source_resolution_id=source_resolution_id, source_letter=source_letter)
    if collaborators:
        task.collaborators.set(collaborators)
    TaskEvent.objects.create(task=task, actor=created_by, action=TaskEvent.Action.CREATED,
                             note=f'وظیفه ایجاد شد — مسئول: {responsible}')
    notify(responsible, f'وظیفه تازه: {title}',
           description[:200], f'/tasks/{task.pk}/')
    return task


def update_progress(task: Task, user, percent: int, note: str = '') -> Task:
    """ثبت پیشرفت؛ ۱۰۰ → انجام‌شده، بالای صفر → در جریان."""
    percent = max(0, min(100, int(percent)))
    task.progress = percent
    if percent >= 100:
        task.status = Task.Status.DONE
    elif percent > 0 and task.status == Task.Status.OPEN:
        task.status = Task.Status.IN_PROGRESS
    task.save(update_fields=['progress', 'status', 'updated_at'])
    TaskEvent.objects.create(
        task=task, actor=user, action=TaskEvent.Action.PROGRESS,
        note=note or f'پیشرفت به {percent}٪ رسید')
    if percent >= 100 and task.created_by and task.created_by != user:
        notify(task.created_by, f'وظیفه انجام شد: {task.title}', note[:200],
               f'/tasks/{task.pk}/')
    return task


def change_status(task: Task, user, status: str, note: str = '') -> Task | None:
    if status not in Task.Status.values:
        return None
    old = task.status
    task.status = status
    if status == Task.Status.DONE:
        task.progress = 100
    task.save(update_fields=['status', 'progress', 'updated_at'])
    TaskEvent.objects.create(
        task=task, actor=user, action=TaskEvent.Action.STATUS,
        note=note or f'وضعیت از «{old}» به «{status}» تغییر کرد')
    return task


def add_comment(task: Task, user, note: str) -> TaskEvent:
    return TaskEvent.objects.create(
        task=task, actor=user, action=TaskEvent.Action.COMMENT, note=note[:1000])
