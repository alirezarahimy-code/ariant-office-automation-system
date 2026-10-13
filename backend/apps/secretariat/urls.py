"""مسیرهای دبیرخانه و کارتابل."""
from django.urls import path

from . import views

app_name = 'secretariat'

urlpatterns = [
    path('kartable/', views.kartable, name='kartable'),
    path('letters/new/', views.register_letter_view, name='register'),
    path('letters/', views.letter_list, name='letter_list'),
    path('letters/<int:pk>/', views.letter_detail, name='letter_detail'),
    path('letters/<int:pk>/refer/', views.letter_refer, name='letter_refer'),
    path('letters/<int:pk>/answer/', views.letter_answer, name='letter_answer'),
    path('letters/<int:pk>/attach/', views.letter_attach, name='letter_attach'),
    path('letters/<int:pk>/copy/', views.letter_add_copy, name='letter_add_copy'),
    path('letters/<int:pk>/close/', views.letter_close, name='letter_close'),
]
