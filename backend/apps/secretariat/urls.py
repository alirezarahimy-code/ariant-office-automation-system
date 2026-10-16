"""مسیرهای دبیرخانه و کارتابل."""
from django.urls import path

from . import views

app_name = 'secretariat'

urlpatterns = [
    path('kartable/', views.kartable, name='kartable'),
    path('letters/new/', views.register_letter_view, name='register'),
    path('letters/new/internal/', views.letter_internal, name='internal'),
    path('letters/', views.letter_list, name='letter_list'),
    path('letters/<int:pk>/', views.letter_detail, name='letter_detail'),
    path('letters/<int:pk>/refer/', views.letter_refer, name='letter_refer'),
    path('letters/<int:pk>/answer/', views.letter_answer, name='letter_answer'),
    path('letters/<int:pk>/attach/', views.letter_attach, name='letter_attach'),
    path('letters/<int:pk>/copy/', views.letter_add_copy, name='letter_add_copy'),
    path('letters/<int:pk>/close/', views.letter_close, name='letter_close'),
    # گردش صادره
    path('letters/<int:pk>/submit/', views.letter_submit, name='letter_submit'),
    path('letters/<int:pk>/approve/', views.letter_approve, name='letter_approve'),
    path('letters/<int:pk>/reject/', views.letter_reject, name='letter_reject'),
    path('letters/<int:pk>/send/', views.letter_send, name='letter_send'),
    path('letters/<int:pk>/pdf/', views.letter_pdf, name='letter_pdf'),
    path('letters/<int:pk>/print/', views.letter_print, name='letter_print'),
    path('letters/<int:pk>/duplicate/', views.letter_duplicate, name='letter_duplicate'),
    path('letters/<int:pk>/relation/', views.letter_relation_add, name='letter_relation'),
    path('letters/<int:pk>/note/', views.letter_note_add, name='letter_note'),
    path('letters/<int:pk>/refer/cancel/', views.refer_cancel, name='refer_cancel'),
    # جانشینی
    path('delegations/', views.delegations, name='delegations'),
    path('delegations/<int:pk>/deactivate/', views.delegation_deactivate, name='delegation_deactivate'),

    # ── لایه حرفه‌ای ──
    path('casefiles/', views.case_file_list, name='case_file_list'),
    path('casefiles/new/', views.case_file_new, name='case_file_new'),
    path('casefiles/<int:pk>/', views.case_file_detail, name='case_file_detail'),
    path('casefiles/<int:pk>/close/', views.case_file_close, name='case_file_close'),
    path('casefiles/<int:pk>/reopen/', views.case_file_reopen, name='case_file_reopen'),
    path('casefiles/<int:pk>/add-letter/', views.case_file_add_letter, name='case_file_add_letter'),
    path('register-book/', views.register_book, name='register_book'),
    path('register-book/print/', views.register_book_print, name='register_book_print'),
    path('index-book/', views.index_book, name='index_book'),
    path('letters/<int:pk>/check/', views.letter_register_check, name='letter_register_check'),
    path('letters/<int:pk>/signatures/new/', views.signature_new, name='signature_new'),
    path('signatures/step/<int:pk>/decide/', views.signature_decide, name='signature_decide'),
    path('letters/<int:pk>/revision/', views.letter_new_revision, name='letter_new_revision'),
    path('letters/<int:pk>/unlock/', views.letter_unlock, name='letter_unlock'),
    path('copies/<int:pk>/done/', views.copy_done, name='copy_done'),
    path('letters/<int:pk>/paraph/', views.letter_paraph, name='letter_paraph'),
    path('letters/<int:pk>/read/', views.letter_mark_read, name='letter_mark_read'),
    path('sla-rules/', views.sla_rules, name='sla_rules'),
    path('overdue/', views.overdue_list, name='overdue'),
    path('number-reserve/', views.number_reserve, name='number_reserve'),
    path('correspondents/', views.correspondents, name='correspondents'),
    path('phrases/', views.frequent_phrases, name='frequent_phrases'),
    path('letters/new/memo/', views.letter_memo, name='memo'),
    path('letters/<int:pk>/pair/', views.letter_pair, name='letter_pair'),
    path('search/', views.search, name='search'),

    # ── تکمیل دبیرخانه ──
    path('letters/batch-print/', views.batch_print, name='batch_print'),
    path('letters/<int:pk>/receipt/', views.letter_receipt, name='letter_receipt'),
    path('letters/<int:pk>/word/', views.letter_word, name='letter_word'),
    path('letters/<int:pk>/reopen/', views.letter_reopen, name='letter_reopen'),
    path('letters/<int:pk>/personal-archive/', views.personal_archive_toggle, name='personal_archive_toggle'),
    path('letters/<int:pk>/refer-group/', views.letter_refer_group, name='letter_refer_group'),
    path('fax/', views.fax_list, name='fax_list'),
    path('fax/<int:pk>/convert/', views.fax_convert, name='fax_convert'),
    path('personal-archive/', views.personal_archive, name='personal_archive'),
    path('retention-rules/', views.retention_rules, name='retention_rules'),
    path('number-formats/', views.number_formats, name='number_formats'),
    path('unit-report/', views.unit_report, name='unit_report'),

    path('courier/', views.courier_list, name='courier_list'),
    path('courier/<int:pk>/update/', views.courier_update, name='courier_update'),
    path('register-book/excel/', views.register_book_excel, name='register_book_excel'),
]
