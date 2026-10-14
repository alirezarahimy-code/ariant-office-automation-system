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
]
