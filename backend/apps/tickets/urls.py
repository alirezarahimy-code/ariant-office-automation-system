from django.urls import path

from . import views

app_name = 'tickets'

urlpatterns = [
    path('', views.ticket_list, name='list'),
    path('new/', views.ticket_new, name='new'),
    path('<int:pk>/', views.ticket_detail, name='detail'),
    path('<int:pk>/message/', views.ticket_message, name='message'),
    path('<int:pk>/status/', views.ticket_status, name='status'),
    path('<int:pk>/assign/', views.ticket_assign, name='assign'),
]
