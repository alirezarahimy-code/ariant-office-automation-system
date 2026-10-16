"""فرم‌های دبیرخانه با ورودی تاریخ شمسی."""
from __future__ import annotations

import jdatetime
from django import forms
from django.contrib.auth import get_user_model
from django.core.exceptions import ValidationError as DjangoValidationError

from apps.core.models import Delegation, Notification
from apps.core.persian import to_english_digits
from apps.organization.models import Department
from .models import (Attachment, CaseFile, Correspondent, FrequentPhrase, Letter,
                     LetterEvent, LetterRelation, Office, RegisterCheck, SLARule,
                     SignatureRequest, SignatureStep, Topic)
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




COPY_KIND_CHOICES = [('original', 'اصل'), ('duplicate', 'المثنی'), ('copy', 'کپی')]

COPY_KIND_FIELD = forms.ChoiceField(
    label='نوع نسخه', choices=COPY_KIND_CHOICES, initial='original', required=False)
PHYSICAL_COUNT_FIELD = forms.IntegerField(
    label='تعداد نسخ فیزیکی', min_value=1, initial=1, required=False)

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
    copy_kind = COPY_KIND_FIELD
    physical_count = PHYSICAL_COUNT_FIELD

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
        letter.copy_kind = d.get('copy_kind') or 'original'
        letter.physical_count = d.get('physical_count') or 1
        letter.save(update_fields=['copy_kind', 'physical_count'])
        self.invalid_attachments = []
        for f in self.files.getlist('attachments'):
            attachment = Attachment(letter=letter, file=f, uploaded_by=user, kind='scan')
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
    copy_kind = COPY_KIND_FIELD
    physical_count = PHYSICAL_COUNT_FIELD

    def clean_subject(self):
        subject = self.cleaned_data['subject'].strip()
        if len(subject) < 3:
            raise forms.ValidationError('موضوع نامه را کامل‌تر وارد کنید.')
        return subject

    def save(self, user) -> Letter:
        d = self.cleaned_data
        letter = register_letter(
            d['office'], Letter.LetterType.OUTGOING, d['subject'], created_by=user,
            summary=d['summary'], body=d['body'], letter_date=d['letter_date'],
            priority=d['priority'], confidentiality=d['confidentiality'])
        letter.copy_kind = d.get('copy_kind') or 'original'
        letter.physical_count = d.get('physical_count') or 1
        letter.save(update_fields=['copy_kind', 'physical_count'])
        return letter


class InternalLetterForm(_StyledForm):
    """ثبت و ارسال نامه داخلی — از شخص/واحد به شخص/واحد با تحویل کارتابل."""

    office = forms.ModelChoiceField(
        label='دبیرخانه', queryset=Office.objects.filter(is_active=True), empty_label='— انتخاب کنید —')
    subject = forms.CharField(label='موضوع', max_length=300)
    body = forms.CharField(label='متن نامه', required=False, widget=forms.Textarea)
    to_user = forms.ModelChoiceField(
        label='گیرنده (شخص)', required=False,
        queryset=get_user_model().objects.filter(is_active=True),
        empty_label='— انتخاب کنید —')
    to_department = forms.ModelChoiceField(
        label='گیرنده (واحد)', required=False,
        queryset=Department.objects.all(), empty_label='— انتخاب کنید —')
    letter_date = JalaliDateField(label='تاریخ نامه')
    priority = forms.ChoiceField(label='درجه فوریت', choices=Letter.Priority.choices, initial='normal')
    confidentiality = forms.ChoiceField(
        label='سطح محرمانگی', choices=Letter.Confidentiality.choices, initial='normal')
    delivery_method = forms.ChoiceField(
        label='شیوه ارسال', choices=Letter.DeliveryMethod.choices, initial='system')
    deadline = JalaliDateField(label='مهلت اقدام')
    template_id = forms.IntegerField(label='الگوی نامه', required=False,
                                     widget=forms.HiddenInput)
    attachments = MultipleFileField(label='پیوست‌ها', required=False)
    copy_kind = COPY_KIND_FIELD
    physical_count = PHYSICAL_COUNT_FIELD

    invalid_attachments: list[str] = []

    def clean(self):
        cleaned = super().clean()
        if not cleaned.get('to_user') and not cleaned.get('to_department'):
            raise forms.ValidationError('برای نامه داخلی حداقل یک گیرنده (شخص یا واحد) لازم است.')
        return cleaned

    def clean_subject(self):
        subject = self.cleaned_data['subject'].strip()
        if len(subject) < 3:
            raise forms.ValidationError('موضوع نامه را کامل‌تر وارد کنید.')
        return subject

    def save(self, user) -> Letter:
        d = self.cleaned_data
        letter = register_letter(
            d['office'], Letter.LetterType.INTERNAL, d['subject'], created_by=user,
            body=d['body'], to_user=d.get('to_user'), to_department=d.get('to_department'),
            letter_date=d['letter_date'], priority=d['priority'],
            confidentiality=d['confidentiality'],
            delivery_method=d['delivery_method'], deadline=d['deadline'])
        letter.copy_kind = d.get('copy_kind') or 'original'
        letter.physical_count = d.get('physical_count') or 1
        letter.save(update_fields=['copy_kind', 'physical_count'])
        self.invalid_attachments = []
        for f in self.files.getlist('attachments'):
            attachment = Attachment(letter=letter, file=f, uploaded_by=user, kind='scan')
            try:
                attachment.full_clean()
                attachment.save()
                LetterEvent.objects.create(
                    letter=letter, actor=user, action=LetterEvent.Action.ATTACHED,
                    note=attachment.original_name)
            except DjangoValidationError:
                self.invalid_attachments.append(f.name)
        # تحویل به کارتابل گیرنده شخصی + اعلان
        if letter.to_user_id and letter.delivery_method == Letter.DeliveryMethod.SYSTEM:
            from apps.core.services import send_notification
            send_notification(
                letter.to_user,
                f'نامه داخلی تازه: {letter.subject}',
                f'از {user.get_full_name() or user.username}',
                f'/letters/{letter.pk}/')
        return letter


class LetterRelationForm(_StyledForm):
    """افزودن نامه مرتبط (پیرو/عطف/بازگشت)."""

    related_pk = forms.IntegerField(label='شماره کارت نامه مرتبط')
    kind = forms.ChoiceField(label='نوع رابطه', choices=LetterRelation.Kind.choices)

    def clean_related_pk(self):
        pk = self.cleaned_data['related_pk']
        if not Letter.objects.filter(pk=pk).exists():
            raise forms.ValidationError('نامه‌ای با این شماره یافت نشد.')
        return pk


class LetterNoteForm(_StyledForm):
    """افزودن هامش/یادداشت روی نامه."""

    text = forms.CharField(label='متن یادداشت', widget=forms.Textarea)


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


# ════════════════════════════════════════════════════════════════
# فرم‌های لایه حرفه‌ای دبیرخانه
# ════════════════════════════════════════════════════════════════

class CaseFileForm(_StyledForm):
    title = forms.CharField(label='عنوان پرونده', max_length=300, required=True)
    description = forms.CharField(label='شرح', widget=forms.Textarea(attrs={'rows': 3}), required=False)
    topic = forms.ModelChoiceField(
        label='موضوع', queryset=None, required=False)
    owner_user = forms.ModelChoiceField(
        label='مسئول پرونده', queryset=None, required=False)
    confidentiality = forms.ChoiceField(
        label='سطح محرمانگی', choices=Letter.Confidentiality.choices,
        initial=Letter.Confidentiality.NORMAL, required=False)

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        from apps.secretariat.models import Topic
        self.fields['topic'].queryset = Topic.objects.all()
        self.fields['owner_user'].queryset = User.objects.filter(is_active=True)


class CaseFileAddLetterForm(_StyledForm):
    letter_pk = forms.IntegerField(label='شماره کارت نامه', required=True)


class RegisterCheckForm(_StyledForm):
    has_signature = forms.BooleanField(
        label='نامه امضا/تأیید مدیر را دارد', required=False)
    copies_count_ok = forms.BooleanField(
        label='تعداد نسخ و رونوشت‌ها درست است', required=False)
    attachments_ok = forms.BooleanField(
        label='ضمائم با تعداد اعلام‌شده مطابقت دارد', required=False)
    dates_ok = forms.BooleanField(
        label='شماره و تاریخ در تمام نسخ درج شده', required=False)
    addressee_ok = forms.BooleanField(
        label='عنوان گیرنده و رونوشت‌ها صحیح است', required=False)
    note = forms.CharField(label='یادداشت', widget=forms.Textarea(attrs={'rows': 2}), required=False)
    result = forms.ChoiceField(
        label='نتیجه', choices=RegisterCheck.Result.choices, initial='pass')


class SignatureRequestForm(_StyledForm):
    mode = forms.ChoiceField(
        label='حالت امضا', choices=SignatureRequest.Mode.choices, initial='sequential')
    signers = forms.ModelMultipleChoiceField(
        label='امضاکنندگان (به ترتیب انتخاب)', queryset=User.objects.filter(is_active=True),
        widget=forms.CheckboxSelectMultiple)


class SignatureDecisionForm(_StyledForm):
    decision = forms.ChoiceField(
        label='تصمیم', choices=SignatureStep.Status.choices, initial='approved')
    comment = forms.CharField(
        label='نظر', widget=forms.Textarea(attrs={'rows': 2}), required=False)


class RevisionForm(_StyledForm):
    reason = forms.CharField(label='دلیل نسخه جدید', max_length=300, required=True)
    subject = forms.CharField(label='موضوع', max_length=300, required=False)
    body = forms.CharField(label='متن نامه', widget=forms.Textarea(attrs={'rows': 8}), required=False)


class ParaphForm(_StyledForm):
    text = forms.CharField(label='متن پاراف', widget=forms.Textarea(attrs={'rows': 2}), required=True)


class SLARuleForm(_StyledForm):
    name = forms.CharField(label='نام قاعده', max_length=150, required=True)
    letter_type = forms.ChoiceField(
        label='نوع نامه', choices=[('', 'همه')] + list(Letter.LetterType.choices), required=False)
    priority = forms.ChoiceField(
        label='اولویت', choices=[('', 'همه')] + list(Letter.Priority.choices), required=False)
    action_hours = forms.IntegerField(label='مهلت اقدام (ساعت)', min_value=1, initial=48)
    warn_hours_before = forms.IntegerField(label='یادآوری قبل از موعد (ساعت)', min_value=1, initial=24)


class NumberReservationForm(_StyledForm):
    letter_type = forms.ChoiceField(label='نوع نامه', choices=Letter.LetterType.choices)
    note = forms.CharField(label='یادداشت', max_length=200, required=False)


class CorrespondentForm(_StyledForm):
    kind = forms.ChoiceField(label='نوع', choices=Correspondent.Kind.choices, initial='org')
    title = forms.CharField(label='نام/عنوان', max_length=200, required=True)
    national_id = forms.CharField(label='شناسه ملی/کد ملی', max_length=20, required=False)
    phone = forms.CharField(label='تلفن', max_length=30, required=False)
    address = forms.CharField(label='نشانی', widget=forms.Textarea(attrs={'rows': 2}), required=False)


class FrequentPhraseForm(_StyledForm):
    scope = forms.ChoiceField(label='نوع', choices=FrequentPhrase.Scope.choices)
    title = forms.CharField(label='عنوان', max_length=200, required=True)
    text = forms.CharField(label='متن', widget=forms.Textarea(attrs={'rows': 3}), required=True)


class MemoLetterForm(_StyledForm):
    """نامه یادداشتی (اداری) — سبک و سریع، مثل یادداشت‌های اداری مرجع."""

    subject = forms.CharField(label='موضوع', max_length=300, required=True)
    body = forms.CharField(label='متن', widget=forms.Textarea(attrs={'rows': 8}), required=True)
    to_user = forms.ModelChoiceField(
        label='گیرنده', queryset=User.objects.filter(is_active=True), required=True)
    to_department = forms.ModelChoiceField(
        label='واحد گیرنده (اختیاری)', queryset=Department.objects.all(), required=False)
    priority = forms.ChoiceField(
        label='فوریت', choices=Letter.Priority.choices, initial='normal', required=False)
    letter_date = JalaliDateField(label='تاریخ نامه', required=False)


class SearchForm(_StyledForm):
    q = forms.CharField(label='جست‌وجو', max_length=200, required=True)
