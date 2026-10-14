from django.urls import path

from . import views

app_name = 'tasks'

urlpatterns = [
    path('', views.task_list, name='list'),
    path('new/', views.task_new, name='new'),
    path('calendar/', views.calendar_month, name='calendar'),
    path('<int:pk>/', views.task_detail, name='detail'),
    path('<int:pk>/progress/', views.task_progress, name='progress'),
    path('<int:pk>/status/', views.task_status, name='status'),
    path('<int:pk>/comment/', views.task_comment, name='comment'),
    path('letter/<int:letter_pk>/new/', views.from_letter, name='from_letter'),
]
