"""فرم‌های دبیرخانه با ورودی تاریخ شمسی."""
from __future__ import annotations

import jdatetime
from django import forms
from django.contrib.auth import get_user_model
from django.core.exceptions import ValidationError as DjangoValidationError

from apps.core.models import Delegation, Notification
from apps.core.persian import to_english_digits
from apps.organization.models import Department
from .models import Attachment, Letter, LetterEvent, Office
from .services import register_letter

User = get_user_model()


class JalaliDateField(forms.CharField):
    """ورودی تاریخ شمسی «۱۴۰۵/۰۷/۲۰» (ارقام فارسی یا لاتین) → jdatetime.date"""

    def __init__(self, *args, **kwargs):
        kwargs.setdefault('required', False)
        super().__init__(*args, **kwargs)
        self.widget.attrs.update({'placeholder': '۱۴۰۵/۰۷/۲۰', 'inputmode': 'numeric', 'dir': 'ltr'})

    def clean(self, value):
        value = super().clean(value)
        if not value:
            return None
        value = to_english_digits(value).strip().replace('-', '/').replace('.', '/')
        parts = [p for p in value.split('/') if p != '']
        if len(parts) != 3:
            raise forms.ValidationError('تاریخ شمسی را به شکل ۱۴۰۵/۰۷/۲۰ وارد کنید.')
        try:
            y, m, d = (int(p) for p in parts)
            return jdatetime.date(y, m, d)
        except ValueError:
            raise forms.ValidationError('تاریخ شمسی معتبر نیست.')


def jalali_to_gregorian(jdate_value):
    return jdate_value.togregorian() if jdate_value else None


class _StyledForm(forms.Form):
    """کلاس CSS مشترک ویجت‌ها."""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        for field in self.fields.values():
            if isinstance(field.widget, (forms.TextInput, forms.Textarea, forms.Select,
                                         forms.NumberInput, forms.ClearableFileInput)):
                field.widget.attrs.setdefault('class', 'input')


class MultipleFileInput(forms.ClearableFileInput):
    """ویجت چندفایلی (الگوی رسمی مستندات جنگو)."""
    allow_multiple_selected = True


class MultipleFileField(forms.FileField):
    """فیلد پیوست چندگانه — پاک‌سازی روی تک‌تک فایل‌ها."""

    def __init__(self, *args, **kwargs):
        kwargs.setdefault('widget', MultipleFileInput(attrs={'multiple': True}))
        super().__init__(*args, **kwargs)

    def clean(self, data, initial=None):
        if isinstance(data, (list, tuple)):
            return [super(MultipleFileField, self).clean(item, initial) for item in data]
        cleaned = super().clean(data, initial)
        return [cleaned] if cleaned else []


class IncomingLetterForm(_StyledForm):
    """ثبت نامه وارده — P0 (docs/10 بند ۱٫۱)."""

    office = forms.ModelChoiceField(
        label='دبیرخانه', queryset=Office.objects.filter(is_active=True), empty_label='— انتخاب کنید —')
    subject = forms.CharField(label='موضوع', max_length=300)
    summary = forms.CharField(label='خلاصه', required=False, widget=forms.Textarea)
    sender_name = forms.CharField(label='فرستنده (شخص)', max_length=150, required=False)
    sender_org = forms.CharField(label='سازمان فرستنده', max_length=200, required=False)
    letter_date = JalaliDateField(label='تاریخ نامه')
    priority = forms.ChoiceField(label='درجه فوریت', choices=Letter.Priority.choices, initial='normal')
    confidentiality = forms.ChoiceField(
        label='سطح محرمانگی', choices=Letter.Confidentiality.choices, initial='normal')
    deadline = JalaliDateField(label='مهلت پاسخ')
    attachments = MultipleFileField(label='پیوست‌ها (اسکن نامه)', required=False)

    invalid_attachments: list[str] = []

    def clean_subject(self):
        subject = self.cleaned_data['subject'].strip()
        if len(subject) < 3:
            raise forms.ValidationError('موضوع نامه را کامل‌تر وارد کنید.')
        return subject

    def save(self, user) -> Letter:
        d = self.cleaned_data
        letter = register_letter(
            d['office'], Letter.LetterType.INCOMING, d['subject'], created_by=user,
            summary=d['summary'], sender_name=d['sender_name'], sender_org=d['sender_org'],
            letter_date=d['letter_date'], priority=d['priority'],
            confidentiality=d['confidentiality'], deadline=d['deadline'])
        self.invalid_attachments = []
        for f in self.files.getlist('attachments'):
            attachment = Attachment(letter=letter, file=f, uploaded_by=user)
            try:
                attachment.full_clean()
                attachment.save()
                LetterEvent.objects.create(
                    letter=letter, actor=user, action=LetterEvent.Action.ATTACHED,
                    note=attachment.original_name)
            except DjangoValidationError:
                self.invalid_attachments.append(f.name)
        return letter


class OutgoingDraftForm(_StyledForm):
    """ایجاد پیش‌نویس نامه صادره — P0 (docs/10 بند ۱٫۲)."""

    office = forms.ModelChoiceField(
        label='دبیرخانه', queryset=Office.objects.filter(is_active=True), empty_label='— انتخاب کنید —')
    subject = forms.CharField(label='موضوع', max_length=300)
    summary = forms.CharField(label='خلاصه', required=False, widget=forms.Textarea)
    body = forms.CharField(label='متن نامه', required=False, widget=forms.Textarea)
    letter_date = JalaliDateField(label='تاریخ نامه')
    priority = forms.ChoiceField(label='درجه فوریت', choices=Letter.Priority.choices, initial='normal')
    confidentiality = forms.ChoiceField(
        label='سطح محرمانگی', choices=Letter.Confidentiality.choices, initial='normal')

    def clean_subject(self):
        subject = self.cleaned_data['subject'].strip()
        if len(subject) < 3:
            raise forms.ValidationError('موضوع نامه را کامل‌تر وارد کنید.')
        return subject

    def save(self, user) -> Letter:
        d = self.cleaned_data
        return register_letter(
            d['office'], Letter.LetterType.OUTGOING, d['subject'], created_by=user,
            summary=d['summary'], body=d['body'], letter_date=d['letter_date'],
            priority=d['priority'], confidentiality=d['confidentiality'])


class ReferralActionForm(_StyledForm):
    """ارجاع نامه به شخص یا واحد + مهلت و دستور."""

    to_user = forms.ModelChoiceField(
        label='گیرنده (شخص)', queryset=User.objects.filter(is_active=True), required=False)
    to_department = forms.ModelChoiceField(
        label='واحد گیرنده', queryset=Department.objects.filter(is_active=True), required=False)
    note = forms.CharField(label='دستور/یادداشت', required=False, widget=forms.Textarea)
    deadline = JalaliDateField(label='مهلت اقدام')

    def clean(self):
        cleaned = super().clean()
        if not cleaned.get('to_user') and not cleaned.get('to_department'):
            raise forms.ValidationError('گیرنده ارجاع (شخص یا واحد) را مشخص کنید.')
        return cleaned


class AnswerActionForm(_StyledForm):
    """ثبت پاسخ روی نامه."""

    note = forms.CharField(label='متن پاسخ/اقدام', widget=forms.Textarea)

    def clean_note(self):
        note = self.cleaned_data['note'].strip()
        if len(note) < 3:
            raise forms.ValidationError('متن پاسخ را کامل‌تر بنویسید.')
        return note


class CopyForm(_StyledForm):
    """افزودن رونوشت."""

    to_user = forms.ModelChoiceField(
        label='گیرنده (شخص)', queryset=User.objects.filter(is_active=True), required=False)
    to_department = forms.ModelChoiceField(
        label='واحد گیرنده', queryset=Department.objects.filter(is_active=True), required=False)
    note = forms.CharField(label='یادداشت', required=False)

    def clean(self):
        cleaned = super().clean()
        if not cleaned.get('to_user') and not cleaned.get('to_department'):
            raise forms.ValidationError('گیرنده رونوشت را مشخص کنید.')
        return cleaned


class DelegationForm(_StyledForm):
    """تعریف جانشینی/تفویض — P1 (docs/10 بند ۲)."""

    to_user = forms.ModelChoiceField(
        label='جانشین', queryset=User.objects.filter(is_active=True))
    start_date = JalaliDateField(label='از تاریخ', required=True)
    end_date = JalaliDateField(label='تا تاریخ', required=True)
    note = forms.CharField(label='یادداشت', required=False, max_length=300)

    def __init__(self, *args, from_user=None, **kwargs):
        super().__init__(*args, **kwargs)
        self.from_user = from_user
        if from_user is not None:
            self.fields['to_user'].queryset = User.objects.filter(is_active=True).exclude(pk=from_user.pk)

    def clean(self):
        cleaned = super().clean()
        start, end = cleaned.get('start_date'), cleaned.get('end_date')
        if start and end and end.togregorian() < start.togregorian():
            raise forms.ValidationError('تاریخ پایان پیش از شروع است.')
        return cleaned

    def save(self) -> Delegation:
        d = self.cleaned_data
        return Delegation.objects.create(
            from_user=self.from_user, to_user=d['to_user'],
            start_date=jalali_to_gregorian(d['start_date']),
            end_date=jalali_to_gregorian(d['end_date']), note=d.get('note', ''))
