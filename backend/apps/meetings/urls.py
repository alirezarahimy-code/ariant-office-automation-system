from django.urls import path

from . import views

app_name = 'meetings'

urlpatterns = [
    path('', views.meeting_list, name='list'),
    path('new/', views.meeting_new, name='new'),
    path('<int:pk>/', views.meeting_detail, name='detail'),
    path('<int:pk>/status/', views.meeting_status, name='status'),
    path('<int:pk>/attendee/', views.attendee_add, name='attendee_add'),
    path('<int:pk>/attendee/<int:attendee_pk>/status/', views.attendee_status,
         name='attendee_status'),
    path('<int:pk>/agenda/', views.agenda_add, name='agenda_add'),
    path('<int:pk>/minutes/', views.minutes_save, name='minutes_save'),
    path('<int:pk>/minutes/sign/', views.minutes_sign, name='minutes_sign'),
    path('<int:pk>/minutes/finalize/', views.minutes_finalize, name='minutes_finalize'),
    path('<int:pk>/resolution/', views.resolution_add, name='resolution_add'),
    path('<int:pk>/resolution/<int:resolution_pk>/convert/', views.resolution_convert,
         name='resolution_convert'),
]
